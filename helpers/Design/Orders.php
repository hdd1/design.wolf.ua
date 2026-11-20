<?php

namespace Design;

/** Замовлення дизайну (design_orders): бриф і замовлення — одна сутність */
final class Orders
{
    public const MAX_TITLE = 255;
    /** Причина скасування — до 2000 символів */
    public const REASON_MAX = 2000;
    /** Статуси, з яких клієнт може скасувати замовлення: до першої купівлі (і без проведених покупок) */
    public const CANCELLABLE = [OrderStatus::DRAFT, OrderStatus::COLLECTING, OrderStatus::WAITING_DESIGNER, OrderStatus::IN_WORK];

    /**
     * Тести: викликається після коммиту скасування, перед прибиранням файлів (щоб зламати прибирання)
     * @internal
     * @var (\Closure(int): void)|null
     */
    public static ?\Closure $afterCommit = null;

    public static function create(int $customerId, int $productId, array $answers, string $title = ''): int
    {
        $product = Products::get($productId);
        if ($product === null) throw new BriefException('Продукт недоступний');

        return Sql::insert('design_orders', [
            'customer_id' => $customerId,
            'product_id' => $productId,
            'mode' => 1,
            'title' => self::title($title),
            'status' => OrderStatus::DRAFT,
            'answers' => self::json(Brief::snapshot($product['questions'], $answers)),
        ]);
    }

    /** Питання — зі збереженого знімка, а не з продукту */
    public static function updateDraft(int $orderId, int $customerId, array $answers, string $title = ''): void
    {
        $order = self::get($orderId, $customerId);
        if ($order === null || $order['status'] !== OrderStatus::DRAFT) {
            throw new BriefException('Замовлення не можна змінити');
        }
        $snapshot = Brief::snapshot($order['answers'], $answers);
        Sql::exec('UPDATE design_orders SET answers = ?, title = ? WHERE id = ? AND customer_id = ? AND status = ?',
            [self::json($snapshot), self::title($title), $orderId, $customerId, OrderStatus::DRAFT]);
    }

    /** Чернетка → збір пропозицій. Повторна публікація нічого не змінює */
    public static function publish(int $orderId, int $customerId): void
    {
        $n = Sql::exec('UPDATE design_orders SET status = ?, published_at = NOW() WHERE id = ? AND customer_id = ? AND status = ?',
            [OrderStatus::COLLECTING, $orderId, $customerId, OrderStatus::DRAFT]);
        if ($n > 0) return;
        $order = self::get($orderId, $customerId);
        if ($order !== null && $order['status'] === OrderStatus::COLLECTING) return;
        throw new BriefException('Замовлення не можна опублікувати');
    }

    /**
     * Клієнт скасовує замовлення (спека «Скасувати замовлення»): статуси CANCELLABLE (1–4) і жодної проведеної покупки; причина
     * обов'язкова, 1–REASON_MAX символів. Статус «Скасовано», cancel_reason, cancelled_at; статуси пропозицій не змінюються —
     * замовлення й листування стають лише для читання (кожна дія перевіряє статус під замком рядка замовлення).
     * Транзакція з замком рядка замовлення (як Purchases::buy і вибір дизайнера — купівля й скасування не перетинаються).
     * Після коммиту файли прев'ю (з симлінками i.fastcdn) і некуплених макетів видаляються з диска, рядки лишаються.
     * Сповіщення — notifyCancel(), сторінка шле його після відповіді браузеру
     * @throws DesignerException
     */
    public static function cancel(int $orderId, int $customerId, string $reason): void
    {
        $reason = trim(mb_scrub($reason));
        if ($reason === '') throw new DesignerException('Вкажіть причину');
        if (mb_strlen($reason) > self::REASON_MAX) throw new DesignerException('Причина — не довше ' . self::REASON_MAX . ' символів');

        $pdo = Sql::pdo();
        $pdo->beginTransaction();
        try {
            $o = Sql::one('SELECT status FROM design_orders WHERE id = ? AND customer_id = ? FOR UPDATE', [$orderId, $customerId]);
            if ($o === null) throw new DesignerException('Замовлення не знайдено');
            $status = (int)$o['status'];
            if ($status === OrderStatus::CANCELLED) throw new DesignerException('Замовлення вже скасовано');
            $bought = Sql::value('SELECT EXISTS (SELECT 1 FROM design_purchases WHERE order_id = ? AND status = ?)', [$orderId, Purchases::STATUS_DONE]);
            if (!in_array($status, self::CANCELLABLE, true) || (bool)$bought) {
                throw new DesignerException('Скасувати можна лише до першої купівлі');
            }
            Sql::exec('UPDATE design_orders SET status = ?, cancel_reason = ?, cancelled_at = NOW() WHERE id = ?',
                [OrderStatus::CANCELLED, $reason, $orderId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        // Файли — після коммиту (як Previews::hide): не вдався коммит — замовлення живе з усіма файлами. Збій прибирання —
        // лише журнал: замовлення вже скасоване, клієнт має отримати перенаправлення, а дизайнери — сповіщення
        try {
            if (self::$afterCommit !== null) (self::$afterCommit)($orderId);
            Previews::removeOrderFiles($orderId);
            Layouts::removeUnpurchasedFiles($orderId);
        } catch (\Throwable $e) {
            error_log("design: прибирання файлів скасованого замовлення $orderId: " . $e);
        }
    }

    /**
     * Сповіщення про скасування (після відповіді браузеру): обраному дизайнеру, запрошеному (режим 2) і всім, хто надіслав
     * пропозицію, — «Замовлення №N скасовано клієнтом» (без причини); куратору клієнта — з причиною.
     * Стан читається заново: замовлення не скасоване — нічого
     */
    public static function notifyCancel(int $orderId): void
    {
        $o = Sql::one('SELECT customer_id, status, cancel_reason, chosen_offer_id FROM design_orders WHERE id = ?', [$orderId]);
        if ($o === null || (int)$o['status'] !== OrderStatus::CANCELLED) return;
        $time = ' (' . date('d.m H:i') . ')';
        $what = 'Майстерня дизайну: замовлення №' . $orderId . ' скасовано клієнтом.';
        $designers = Sql::all('SELECT DISTINCT designer_id FROM design_offers WHERE order_id = ? AND (id = ? OR status IN (?, ?)) ORDER BY designer_id',
            [$orderId, (int)$o['chosen_offer_id'], OfferStatus::INVITED, OfferStatus::SENT]);
        foreach ($designers as $d) {
            Notify::send([
                'customer_id' => (int)$d['designer_id'],
                'msg' => $what . ' ' . Config::siteUrl() . '/feed/' . $orderId . '/' . $time,
                'msgSMS' => $what . ' design.wolf.ua',
            ]);
        }
        $customerId = (int)$o['customer_id'];
        $client = Customers::header($customerId)['name'] ?? 'Клієнт';
        Notify::curator($customerId,
            'Майстерня дизайну: клієнт ' . $client . ' (id ' . $customerId . ') скасував замовлення №' . $orderId . '. Причина: '
            . preg_replace('/\s+/u', ' ', (string)$o['cancel_reason']) . $time,
            'Майстерня дизайну: клієнт скасував №' . $orderId . '. design.wolf.ua');
    }

    /** @return array{id:int, status:int, mode:int, product_id:int, product_name:string, title:string, answers:list<array{q:string,a:string}>, created:string, published_at:?string, cancel_reason:?string, cancelled_at:?string}|null чуже — null */
    public static function get(int $orderId, int $customerId): ?array
    {
        $r = Sql::one(
            'SELECT o.id, o.status, o.mode, o.title, o.product_id, p.name AS product_name, o.answers, o.created, o.published_at,
                    o.cancel_reason, o.cancelled_at
             FROM design_orders o JOIN design_products p ON p.id = o.product_id
             WHERE o.id = ? AND o.customer_id = ?', [$orderId, $customerId]);
        return $r === null ? null : self::withAnswers($r);
    }

    /**
     * Замовлення без перевірки власника — для сторінки дизайнера (хто що бачить, вирішує сторінка)
     * @return array{id:int, customer_id:int, status:int, mode:int, product_id:int, product_name:string, title:string, answers:list<array{q:string,a:string}>, created:string, published_at:?string, cancel_reason:?string, cancelled_at:?string}|null
     */
    public static function find(int $orderId): ?array
    {
        $r = Sql::one(
            'SELECT o.id, o.customer_id, o.status, o.mode, o.title, o.product_id, p.name AS product_name, o.answers, o.created, o.published_at,
                    o.cancel_reason, o.cancelled_at
             FROM design_orders o JOIN design_products p ON p.id = o.product_id
             WHERE o.id = ?', [$orderId]);
        return $r === null ? null : ['customer_id' => (int)$r['customer_id']] + self::withAnswers($r);
    }

    private static function withAnswers(array $r): array
    {
        $row = self::row($r);
        // Колонка JSON у MySQL сама переставляє ключі — повертаємо пари у вигляді {q, a}
        $answers = json_decode((string)$r['answers'], true);
        $row['answers'] = array_map(
            static fn($p): array => ['q' => (string)($p['q'] ?? ''), 'a' => (string)($p['a'] ?? '')],
            is_array($answers) ? $answers : []
        );
        return $row;
    }

    /**
     * Замовлення клієнта для «Мої замовлення», нові зверху. offers — пропозиції, які можна обрати (надіслані схваленими
     * дизайнерами) — для «чий хід»; designer / price — обраний дизайнер і ціна для клієнта, поки не обрано — '' і 0;
     * rework — є пропозиція доопрацювання без відповіді (Rework::pending); cancel_reason — причина скасування («чий хід»)
     * @return list<array{id:int, status:int, mode:int, product_id:int, product_name:string, title:string, created:string, published_at:?string, cancel_reason:?string, cancelled_at:?string, offers:int, designer:string, price:int, rework:bool}>
     */
    public static function forCustomer(int $customerId): array
    {
        $rows = Sql::all(
            'SELECT o.id, o.status, o.mode, o.title, o.product_id, p.name AS product_name, o.created, o.published_at, o.cancel_reason, o.cancelled_at,
                    (SELECT COUNT(*) FROM design_offers f JOIN design_designers d ON d.customer_id = f.designer_id
                     WHERE f.order_id = o.id AND f.status = ? AND d.status = ?) AS offers,
                    cd.name AS designer, c.price_client AS price, o.paid_client, o.rework_price_designer, o.rework_price_client, o.rework_deadline
             FROM design_orders o
             JOIN design_products p ON p.id = o.product_id
             LEFT JOIN design_offers c ON c.id = o.chosen_offer_id
             LEFT JOIN design_designers cd ON cd.customer_id = c.designer_id
             WHERE o.customer_id = ? ORDER BY o.id DESC', [OfferStatus::SENT, DesignerStatus::APPROVED, $customerId]);
        return array_map(static fn(array $r): array => self::row($r) + [
            'offers' => (int)$r['offers'],
            'designer' => (string)$r['designer'],
            'price' => (int)$r['price'],
            'rework' => Rework::pending($r) !== null,
        ], $rows);
    }

    private static function row(array $r): array
    {
        return [
            'id' => (int)$r['id'],
            'status' => (int)$r['status'],
            'mode' => (int)$r['mode'],
            'product_id' => (int)$r['product_id'],
            'product_name' => (string)$r['product_name'],
            'title' => (string)$r['title'] !== '' ? (string)$r['title'] : (string)$r['product_name'],
            'created' => (string)$r['created'],
            'published_at' => $r['published_at'] === null ? null : (string)$r['published_at'],
            'cancel_reason' => $r['cancel_reason'] === null ? null : (string)$r['cancel_reason'],
            'cancelled_at' => $r['cancelled_at'] === null ? null : (string)$r['cancelled_at'],
        ];
    }

    private static function title(string $title): string
    {
        $title = trim($title);
        if (mb_strlen($title) > self::MAX_TITLE) throw new BriefException('Назва занадто довга');
        return $title;
    }

    private static function json(array $snapshot): string
    {
        return json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
