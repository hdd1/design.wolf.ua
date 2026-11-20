<?php

namespace Design;

/**
 * Купівля макетів з балансу wolf.ua (design_purchases) і «Заробіток» дизайнера.
 * Платить завжди клієнт замовлення (субакаунтів на design немає): його customers.inv_sum + credit; credit_ra / temp_credit_ra
 * не враховуються. Гроші — цілі гривні. До сплати = ціна обраної пропозиції − уже сплачене (paid_client / paid_designer).
 *
 * buy() — одна транзакція БД. Замки — завжди в одному порядку: рядок замовлення (FOR UPDATE), потім рядки customers за
 * зростанням id (FOR UPDATE). Під замком замовлення перевіряються статус і некуплені макети — тож друга одночасна купівля
 * чекає на замку й після коммиту першої бачить «Куплено». Списання — лише умовним UPDATE (inv_sum + credit >= P), ніколи
 * не записом прочитаного числа. Сповіщення дизайнеру buy() не шле — сторінка викликає notifyDesigner() після відповіді
 */
final class Purchases
{
    /** design_purchases.method: з балансу */
    public const METHOD_BALANCE = 1;
    /** design_purchases.status: проведена (1 очікує і 3 скасована — етап 6) */
    public const STATUS_DONE = 2;

    /** Термін овердрафту за замовчуванням, днів (як Customer::overDraft() wolf.ua) */
    private const OVERDRAFT_DAYS = 30;

    private const ERR_NOT_FOUND = 'Замовлення не знайдено';
    private const ERR_BOUGHT = 'Макети вже куплено';
    private const ERR_STATUS = 'Замовлення зараз не можна купити';
    private const ERR_NO_LAYOUTS = 'Немає макетів для купівлі';
    private const ERR_PRICE = 'Ціна змінилася — перевірте суму';
    private const ERR_SUB = 'З субакаунта купити не можна — потрібен основний акаунт wolf.ua';

    private const BUYABLE = [OrderStatus::IN_WORK, OrderStatus::REWORK];

    /**
     * Тести: викликається в транзакції buy() одразу після замка рядка замовлення — щоб запустити паралельну купівлю
     * @internal
     * @var (\Closure(int): void)|null (orderId)
     */
    public static ?\Closure $afterLock = null;

    /**
     * Що покаже вікно оплати. null — не клієнт цього замовлення або статус не «В роботі» / «Доопрацювання».
     * can = false за будь-якої причини (reason — текст для клієнта). Сума 0 (доопрацювання без доплати) — без перевірок грошей
     * @return array{amount_client:int, amount_designer:int, price_client:int, price_designer:int, balance:int, credit:int, after:int, can:bool, reason:string, new_layouts:int}|null
     */
    public static function quote(int $orderId, int $customerId): ?array
    {
        $o = Sql::one(
            'SELECT o.status, o.paid_client, o.paid_designer, f.price_client, f.price_designer
             FROM design_orders o JOIN design_offers f ON f.id = o.chosen_offer_id AND f.order_id = o.id
             WHERE o.id = ? AND o.customer_id = ?', [$orderId, $customerId]);
        if ($o === null || !in_array((int)$o['status'], self::BUYABLE, true)) return null;

        [$p, $d] = self::amounts($o, $o);
        $c = Sql::one('SELECT inv_sum, credit, parent_id, overdraft_start, overdraft_days FROM customers WHERE id = ?', [$customerId]);
        if ($c === null) return null;
        $newLayouts = self::newLayouts($orderId, false);
        // Вниз до гривні: показаний залишок не суперечить can (799,50 при P = 800 → 799, «Не вистачає 1 грн»)
        $balance = (int)floor(self::cents($c['inv_sum']) / 100);
        $reason = self::refusal($newLayouts, $c, $p);

        return [
            'amount_client' => $p,
            'amount_designer' => $d,
            'price_client' => (int)$o['price_client'],
            'price_designer' => (int)$o['price_designer'],
            'balance' => $balance,
            'credit' => (int)$c['credit'],
            'after' => $balance - $p,
            'can' => $reason === '',
            'reason' => $reason,
            'new_layouts' => $newLayouts,
        ];
    }

    /**
     * Проведення купівлі (спека «Оплата з балансу», кроки 1–7) однією транзакцією. expectedAmount — сума, яку клієнт бачив
     * у вікні: інша → «Ціна змінилася», нічого не списано. Будь-яка відмова — DesignerException і повний відкат
     * @return array{purchase_id:int, amount_client:int, amount_designer:int, designer_id:int}
     * @throws DesignerException
     */
    public static function buy(int $orderId, int $customerId, int $expectedAmount): array
    {
        $pdo = Sql::pdo();
        $pdo->beginTransaction();
        try {
            // 1. Замок замовлення і всі перевірки під ним
            $o = Sql::one('SELECT status, chosen_offer_id, paid_client, paid_designer FROM design_orders WHERE id = ? AND customer_id = ? FOR UPDATE',
                [$orderId, $customerId]);
            if (self::$afterLock !== null) (self::$afterLock)($orderId);
            if ($o === null || $o['chosen_offer_id'] === null) throw new DesignerException(self::ERR_NOT_FOUND);
            $status = (int)$o['status'];
            if ($status === OrderStatus::PURCHASED) throw new DesignerException(self::ERR_BOUGHT);
            if (!in_array($status, self::BUYABLE, true)) throw new DesignerException(self::ERR_STATUS);

            $offerId = (int)$o['chosen_offer_id'];
            $f = Sql::one('SELECT designer_id, price_client, price_designer, markup_percent FROM design_offers WHERE id = ? AND order_id = ? FOR UPDATE',
                [$offerId, $orderId]);
            if ($f === null) throw new DesignerException(self::ERR_NOT_FOUND);
            $designerId = (int)$f['designer_id'];
            [$p, $d] = self::amounts($o, $f);

            $newLayouts = self::newLayouts($orderId, true);
            if ($newLayouts === 0) throw new DesignerException(self::ERR_NO_LAYOUTS);
            if ($expectedAmount !== $p) throw new DesignerException(self::ERR_PRICE);

            // 2. Рядки customers — за зростанням id (без взаємоблокувань двох купівель), перевірки на заблокованому рядку платника
            $ids = $d > 0 ? [$customerId, $designerId] : [$customerId];
            $rows = Sql::all('SELECT id, inv_sum, credit, parent_id, overdraft_start, overdraft_days FROM customers WHERE id IN ('
                . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id FOR UPDATE', $ids);
            $rows = array_column($rows, null, 'id');
            $payer = $rows[$customerId] ?? null;
            if ($payer === null) throw new DesignerException(self::ERR_NOT_FOUND);
            if ($d > 0 && !isset($rows[$designerId])) throw new \RuntimeException("Design\\Purchases: дизайнера id $designerId немає в customers");
            $reason = self::refusal($newLayouts, $payer, $p);
            if ($reason !== '') throw new DesignerException($reason);

            // Один годинник для created_ts, paid_at і purchased_at
            $created = time();
            $now = date('Y-m-d H:i:s', $created);
            $trClient = $trDesigner = null;
            if ($p > 0) {
                if (Sql::exec('UPDATE customers SET inv_sum = inv_sum - ? WHERE id = ? AND inv_sum + credit >= ?', [$p, $customerId, $p]) !== 1) {
                    throw new DesignerException(self::short($payer, $p));
                }
                // 5. Уперше пішов у мінус (був ≥ 0 або дату не почато) — овердрафт від сьогодні, термін 30 днів, якщо не задано
                $wasNegative = (float)$payer['inv_sum'] < 0 && self::date($payer['overdraft_start']) !== null;
                if (!$wasNegative) {
                    Sql::exec('UPDATE customers SET overdraft_start = ?, overdraft_days = COALESCE(overdraft_days, ?) WHERE id = ? AND inv_sum < 0',
                        [date('Y-m-d'), self::OVERDRAFT_DAYS, $customerId]);
                }
                $trClient = self::transaction($customerId, -$p, '[design] Оплата замовлення №' . $orderId, $created);
            }
            // 3. Гонорар — і заблокованому / знятому зі схвалення після вибору дизайнеру (роботу зроблено)
            if ($d > 0) {
                Sql::exec('UPDATE customers SET inv_sum = inv_sum + ? WHERE id = ?', [$d, $designerId]);
                $trDesigner = self::transaction($designerId, $d, '[design] Гонорар за замовлення №' . $orderId, $created);
            }

            // 6. Купівля, макети, замовлення
            $purchaseId = Sql::insert('design_purchases', [
                'order_id' => $orderId,
                'offer_id' => $offerId,
                'payer_id' => $customerId,
                'price_client' => (int)$f['price_client'],
                'price_designer' => (int)$f['price_designer'],
                'markup_percent' => (string)$f['markup_percent'],
                'amount_client' => $p,
                'amount_designer' => $d,
                'method' => self::METHOD_BALANCE,
                'status' => self::STATUS_DONE,
                'transaction_client_id' => $trClient,
                'transaction_designer_id' => $trDesigner,
                'paid_at' => $now,
            ]);
            Sql::exec('UPDATE design_files SET purchase_id = ? WHERE order_id = ? AND status = ? AND purchase_id IS NULL',
                [$purchaseId, $orderId, Layouts::ACTIVE]);
            Sql::exec('UPDATE design_orders SET paid_client = paid_client + ?, paid_designer = paid_designer + ?, purchased_at = ?, status = ?,
                       rework_price_designer = NULL, rework_price_client = NULL, rework_deadline = NULL, rework_proposed_at = NULL
                       WHERE id = ?', [$p, $d, $now, OrderStatus::PURCHASED, $orderId]);

            // 7. Слід у листуванні
            Messages::system($offerId, $p > 0 ? 'Клієнт купив макети за ' . self::money($p) . ' грн' : 'Клієнт купив макети',
                ['event' => 'purchase', 'purchase_id' => $purchaseId, 'amount' => $p]);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        return ['purchase_id' => $purchaseId, 'amount_client' => $p, 'amount_designer' => $d, 'designer_id' => $designerId];
    }

    /** Дизайнеру — «клієнт купив» (сторінка викликає після відповіді браузеру); result — з buy() */
    public static function notifyDesigner(array $result, int $orderId): void
    {
        $d = (int)$result['amount_designer'];
        $what = 'Клієнт купив макети №' . $orderId . ($d > 0 ? ', +' . self::money($d) . ' грн' : '');
        Notify::send([
            'customer_id' => (int)$result['designer_id'],
            'msg' => 'Майстерня дизайну: ' . $what . '. ' . Config::siteUrl() . '/feed/' . $orderId . '/ (' . date('d.m H:i') . ')',
            'msgSMS' => 'Майстерня дизайну: ' . $what . '. design.wolf.ua',
        ]);
    }

    /**
     * «Заробіток»: проведені купівлі замовлень, де дизайнер — обраний, з гонораром > 0 (і коли клієнт нічого не доплатив,
     * а гонорар виріс через округлення ціни клієнта вгору); нові зверху
     * @return list<array{paid_at:string, order_id:int, title:string, amount_designer:int}>
     */
    public static function forDesigner(int $designerId): array
    {
        $rows = Sql::all(
            'SELECT p.paid_at, p.order_id, o.title, COALESCE(pr.name, \'\') AS product_name, p.amount_designer
             FROM design_purchases p
             JOIN design_offers f ON f.id = p.offer_id
             JOIN design_orders o ON o.id = p.order_id
             LEFT JOIN design_products pr ON pr.id = o.product_id
             WHERE f.designer_id = ? AND p.status = ? AND p.amount_designer > 0
             ORDER BY p.paid_at DESC, p.id DESC', [$designerId, self::STATUS_DONE]);
        return array_map(static fn(array $r): array => [
            'paid_at' => (string)$r['paid_at'],
            'order_id' => (int)$r['order_id'],
            // Заробіток не зникає й без продукту: назва → продукт → «Замовлення №N»
            'title' => (string)$r['title'] !== '' ? (string)$r['title']
                : ((string)$r['product_name'] !== '' ? (string)$r['product_name'] : 'Замовлення №' . (int)$r['order_id']),
            'amount_designer' => (int)$r['amount_designer'],
        ], $rows);
    }

    /**
     * До сплати: повна ціна обраної пропозиції − уже сплачене (не менше 0)
     * @return array{0:int, 1:int} [P, D]
     */
    private static function amounts(array $order, array $offer): array
    {
        return [
            max(0, (int)$offer['price_client'] - (int)$order['paid_client']),
            max(0, (int)$offer['price_designer'] - (int)$order['paid_designer']),
        ];
    }

    /** Активні макети без купівлі; lock — у транзакції buy() (FOR UPDATE: ті самі рядки отримають purchase_id) */
    private static function newLayouts(int $orderId, bool $lock): int
    {
        return count(Sql::all('SELECT id FROM design_files WHERE order_id = ? AND status = ? AND purchase_id IS NULL' . ($lock ? ' FOR UPDATE' : ''),
            [$orderId, Layouts::ACTIVE]));
    }

    /**
     * Чому не можна купити ('' — можна). Порядок: макети, субакаунт; для суми > 0 — прострочений овердрафт, нестача.
     * $c — рядок customers платника (inv_sum, credit, parent_id, overdraft_start, overdraft_days)
     */
    private static function refusal(int $newLayouts, array $c, int $p): string
    {
        if ($newLayouts === 0) return self::ERR_NO_LAYOUTS;
        if ((int)$c['parent_id'] !== 0) return self::ERR_SUB;
        if ($p === 0) return '';
        $late = self::overdraftLate($c);
        if ($late > 0) return 'Перевищено термін погашення заборгованості на ' . $late . ' днів';
        if (self::cents($c['inv_sum']) + (int)$c['credit'] * 100 < $p * 100) return self::short($c, $p);
        return '';
    }

    /** «Не вистачає N грн»: N — скільки бракує до P з урахуванням кредиту, вгору до гривні */
    private static function short(array $c, int $p): string
    {
        $missing = $p * 100 - self::cents($c['inv_sum']) - (int)$c['credit'] * 100;
        return 'Не вистачає ' . self::money(max(1, (int)ceil($missing / 100))) . ' грн';
    }

    /**
     * Як Customer::overDraft() wolf.ua: у мінусі, овердрафт почато і overdraft_start + overdraft_days (немає — 30) днів
     * раніше за сьогодні → на скільки днів прострочено; інакше 0
     */
    private static function overdraftLate(array $c): int
    {
        $start = self::date($c['overdraft_start']);
        if ((float)$c['inv_sum'] >= 0 || $start === null) return 0;
        $days = $c['overdraft_days'] === null ? self::OVERDRAFT_DAYS : (int)$c['overdraft_days'];
        $end = (new \DateTimeImmutable($start))->modify('+' . $days . ' days');
        $today = new \DateTimeImmutable('today');
        return $end < $today ? (int)$end->diff($today)->days : 0;
    }

    /** Дата з БД або null для порожньої / нульової (старі рядки wolf.ua можуть мати '0000-00-00') */
    private static function date(mixed $v): ?string
    {
        $v = $v === null ? '' : (string)$v;
        return $v === '' || str_starts_with($v, '0000') ? null : $v;
    }

    /** decimal inv_sum → копійки (без похибки float) */
    private static function cents(mixed $v): int
    {
        return (int)round((float)$v * 100);
    }

    private static function transaction(int $customerId, int $amount, string $paymethod, int $created): int
    {
        return Sql::insert('transaction', [
            'customer_id' => $customerId,
            'orderstatus' => 'COMPLETE',
            'paymethod' => $paymethod,
            'payment_done' => $amount,
            'order_id' => null,
            'type_id' => 0,
            'user_id' => 0,
            'created_ts' => $created,
        ]);
    }

    /** 1020 → «1 020» (нерозривний пробіл) */
    private static function money(int $v): string
    {
        return number_format($v, 0, ',', "\u{00A0}");
    }
}
