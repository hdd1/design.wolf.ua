<?php

namespace Design;

/**
 * Доопрацювання купленого замовлення (спека «Ще не закінчили»). Дизайнер у статусі «Куплено» пропонує нову ПОВНУ суму на руки D'
 * (не менше вже нарахованого paid_designer; дорівнює — правка безкоштовна) і дату; ціна для клієнта
 * P' = max(Price::client(D', markup_percent обраної пропозиції, price_round), paid_client). Пропозиція — колонки
 * design_orders.rework_*; клієнт приймає (→ «Доопрацювання», ціни й дата обраної пропозиції = rework_*), відмовляється
 * (пропозиція скидається) або на доопрацюванні скасовує його (→ «Куплено», ціни — до сплачених, дата та сама, нові некуплені
 * макети лишаються закритими). Доплату (P' − paid_client) клієнт вносить «Купую» (Purchases).
 * Кожна дія — транзакція з замком рядка замовлення (як у Purchases::buy, тож купівля й відповідь клієнта не перетинаються).
 * Дії не сповіщають — сторінка викликає notify() після відповіді браузеру
 */
final class Rework
{
    private const ERR_DESIGNER = 'Запропонувати доопрацювання може лише обраний дизайнер';
    private const ERR_NOT_PURCHASED = 'Доопрацювання можна запропонувати лише для купленого замовлення';
    private const ERR_PENDING = 'Пропозицію вже надіслано — чекаємо відповіді клієнта';
    private const ERR_NONE = 'Пропозиції доопрацювання немає — оновіть сторінку';
    private const ERR_CHANGED = 'Пропозиція змінилася — оновіть сторінку';
    private const ERR_NOT_REWORK = 'Скасувати можна лише доопрацювання, яке триває';
    private const ERR_NOT_FOUND = 'Замовлення не знайдено';

    /**
     * Дизайнер пропонує доопрацювання: priceDesigner — нова повна сума на руки D', deadline — Y-m-d (не раніше сьогодні)
     * @throws DesignerException
     */
    public static function propose(int $orderId, int $designerId, int $priceDesigner, string $deadline): void
    {
        if ($priceDesigner < 1) throw new DesignerException('Вкажіть суму від 1 грн');
        if ($priceDesigner > Offers::PRICE_MAX) throw new DesignerException('Сума — не більше ' . self::money(Offers::PRICE_MAX, ' ') . ' грн');
        $d = \DateTime::createFromFormat('!Y-m-d', $deadline);
        if ($d === false || $d->format('Y-m-d') !== $deadline) throw new DesignerException('Вкажіть дату «Готово до»');
        if ($deadline < date('Y-m-d')) throw new DesignerException('Дата — не раніше сьогодні');

        self::locked($orderId, null, static function (array $o, array $f) use ($orderId, $designerId, $priceDesigner, $deadline): void {
            if ($f['designer_id'] !== $designerId || Designers::status($designerId) !== DesignerStatus::APPROVED) {
                throw new DesignerException(self::ERR_DESIGNER);
            }
            if ($o['status'] !== OrderStatus::PURCHASED) throw new DesignerException(self::ERR_NOT_PURCHASED);
            if ($o['rework_price_client'] !== null) throw new DesignerException(self::ERR_PENDING);
            if ($priceDesigner < $o['paid_designer']) {
                throw new DesignerException('Сума — не менше вже нарахованих ' . self::money($o['paid_designer'], ' ') . ' грн');
            }
            $p = max(Price::client($priceDesigner, $f['markup_percent'], Settings::priceRound()), $o['paid_client']);
            Sql::exec('UPDATE design_orders SET rework_price_designer = ?, rework_price_client = ?, rework_deadline = ?, rework_proposed_at = NOW()
                       WHERE id = ?', [$priceDesigner, $p, $deadline, $orderId]);
            $extra = $p - $o['paid_client'];
            Messages::system($f['id'], 'Дизайнер пропонує доопрацювання: ' . self::extra($extra) . ', до ' . date('d.m.Y', strtotime($deadline)),
                ['event' => 'rework_propose', 'price_designer' => $priceDesigner, 'price_client' => $p, 'extra' => $extra, 'deadline' => $deadline]);
        });
    }

    /**
     * Клієнт приймає пропозицію: статус «Доопрацювання», ціни й дата обраної пропозиції — з пропозиції, rework_* і
     * notified_deadline_at (крон «термін минув») скидаються. $token — token() пропозиції, яку клієнт бачив (форма сторінки):
     * інша (стара вкладка, а дизайнер уже запропонував інше) — «Пропозиція змінилася»; null — без перевірки
     * @throws DesignerException
     */
    public static function accept(int $orderId, int $customerId, ?string $token = null): void
    {
        self::locked($orderId, $customerId, static function (array $o, array $f) use ($orderId, $token): void {
            $r = self::pending($o);
            if ($r === null) throw new DesignerException(self::ERR_NONE);
            if ($token !== null && !hash_equals(self::token($o), $token)) throw new DesignerException(self::ERR_CHANGED);
            Sql::exec('UPDATE design_offers SET price_designer = ?, price_client = ?, deadline = ? WHERE id = ?',
                [$r['price_designer'], $r['price_client'], $r['deadline'], $f['id']]);
            Sql::exec('UPDATE design_orders SET status = ?, rework_price_designer = NULL, rework_price_client = NULL, rework_deadline = NULL,
                       rework_proposed_at = NULL, notified_deadline_at = NULL WHERE id = ?', [OrderStatus::REWORK, $orderId]);
            $date = date('d.m.Y', strtotime($r['deadline']));
            $text = $r['price_client'] !== $f['price_client']
                ? 'Клієнт прийняв доопрацювання. Ціну змінено з ' . self::money($f['price_client']) . ' на ' . self::money($r['price_client'])
                    . ' грн, термін — до ' . $date
                : 'Клієнт прийняв доопрацювання без доплати, термін — до ' . $date;
            Messages::system($f['id'], $text, ['event' => 'rework_accept', 'price_from' => $f['price_client'], 'price_to' => $r['price_client'],
                'deadline' => $r['deadline']]);
        });
    }

    /**
     * Клієнт відмовляється: пропозиція скидається, статус «Куплено» лишається; дизайнер може запропонувати знову.
     * $token — як в accept()
     * @throws DesignerException
     */
    public static function decline(int $orderId, int $customerId, ?string $token = null): void
    {
        self::locked($orderId, $customerId, static function (array $o, array $f) use ($orderId, $token): void {
            if (self::pending($o) === null) throw new DesignerException(self::ERR_NONE);
            if ($token !== null && !hash_equals(self::token($o), $token)) throw new DesignerException(self::ERR_CHANGED);
            Sql::exec('UPDATE design_orders SET rework_price_designer = NULL, rework_price_client = NULL, rework_deadline = NULL,
                       rework_proposed_at = NULL WHERE id = ?', [$orderId]);
            Messages::system($f['id'], 'Клієнт відмовився від доопрацювання', ['event' => 'rework_decline']);
        });
    }

    /**
     * Клієнт скасовує доопрацювання, яке триває: «Доопрацювання» → «Куплено», ціни обраної пропозиції — до сплачених
     * (paid_client / paid_designer), дата лишається; нові некуплені макети лишаються закритими (купити в «Куплено» не можна)
     * @throws DesignerException
     */
    public static function cancel(int $orderId, int $customerId): void
    {
        self::locked($orderId, $customerId, static function (array $o, array $f) use ($orderId): void {
            if ($o['status'] !== OrderStatus::REWORK) throw new DesignerException(self::ERR_NOT_REWORK);
            Sql::exec('UPDATE design_offers SET price_designer = ?, price_client = ? WHERE id = ?', [$o['paid_designer'], $o['paid_client'], $f['id']]);
            Sql::exec('UPDATE design_orders SET status = ? WHERE id = ?', [OrderStatus::PURCHASED, $orderId]);
            Messages::system($f['id'], 'Клієнт скасував доопрацювання. Ціна повернулася до сплаченої: ' . self::money($o['paid_client']) . ' грн',
                ['event' => 'rework_cancel', 'price_client' => $o['paid_client']]);
        });
    }

    /**
     * Пропозиція доопрацювання без відповіді: замовлення «Куплено» з rework_price_client. extra — скільки клієнт доплатить
     * (P' − paid_client); price_designer — D' (для дизайнера). Інакше null.
     * $order — рядок design_orders (status, paid_client, rework_price_designer, rework_price_client, rework_deadline)
     * @return array{price_client:int, extra:int, deadline:string, price_designer:int}|null
     */
    public static function pending(array $order): ?array
    {
        if ((int)$order['status'] !== OrderStatus::PURCHASED || $order['rework_price_client'] === null) return null;
        $p = (int)$order['rework_price_client'];
        return [
            'price_client' => $p,
            'extra' => max(0, $p - (int)$order['paid_client']),
            'deadline' => (string)$order['rework_deadline'],
            'price_designer' => (int)$order['rework_price_designer'],
        ];
    }

    /**
     * Відбиток пропозиції без відповіді (час, ціни, дата) для форм «Прийняти» / «Відмовитися»: accept / decline порівнюють його
     * під замком, щоб стара вкладка не прийняла пізнішу пропозицію. Немає пропозиції — ''
     */
    public static function token(array $order): string
    {
        $p = self::pending($order);
        if ($p === null) return '';
        return substr(sha1(implode('|', [(string)($order['rework_proposed_at'] ?? ''), $p['price_client'], $p['price_designer'], $p['deadline']])), 0, 16);
    }

    /**
     * Рядок замовлення для pending() і стану сторінки (без замка); немає — null
     * @return array{status:int, paid_client:int, paid_designer:int, rework_price_designer:?int, rework_price_client:?int, rework_deadline:?string, rework_proposed_at:?string}|null
     */
    public static function order(int $orderId): ?array
    {
        $o = Sql::one('SELECT status, paid_client, paid_designer, rework_price_designer, rework_price_client, rework_deadline,
                              rework_proposed_at
                       FROM design_orders WHERE id = ?', [$orderId]);
        return $o === null ? null : self::orderRow($o);
    }

    /**
     * Сповіщення після дії (сторінка — після відповіді браузеру): propose — клієнту; accept / decline / cancel — обраному дизайнеру.
     * Стан читається заново: пропозицію вже прийнято / скасовано (друга вкладка) — сповіщення про неї не шлемо
     */
    public static function notify(int $orderId, string $action): void
    {
        $r = Sql::one('SELECT o.customer_id, o.status, o.paid_client, o.paid_designer, o.rework_price_designer, o.rework_price_client, o.rework_deadline,
                              o.rework_proposed_at, f.designer_id, f.deadline
                       FROM design_orders o JOIN design_offers f ON f.id = o.chosen_offer_id WHERE o.id = ?', [$orderId]);
        if ($r === null) return;
        $site = Config::siteUrl();
        $time = ' (' . date('d.m H:i') . ')';
        if ($action === 'propose') {
            $p = self::pending(self::orderRow($r));
            if ($p === null) return;
            Notify::send([
                'customer_id' => (int)$r['customer_id'],
                'msg' => 'Майстерня дизайну: дизайнер пропонує доопрацювання №' . $orderId . ': ' . self::extra($p['extra']) . ', до '
                    . date('d.m', strtotime($p['deadline'])) . '. Прийміть або відмовтеся: ' . $site . '/orders/' . $orderId . '/' . $time,
                'msgSMS' => 'Майстерня дизайну: пропозиція доопрацювання №' . $orderId . '. design.wolf.ua',
            ]);
            return;
        }
        $what = match ($action) {
            'accept' => 'клієнт прийняв доопрацювання №' . $orderId . ($r['deadline'] ? ', до ' . date('d.m', strtotime((string)$r['deadline'])) : ''),
            'decline' => 'клієнт відмовився від доопрацювання №' . $orderId,
            'cancel' => 'клієнт скасував доопрацювання №' . $orderId,
            default => null,
        };
        if ($what === null) return;
        Notify::send([
            'customer_id' => (int)$r['designer_id'],
            'msg' => 'Майстерня дизайну: ' . $what . '. ' . $site . '/feed/' . $orderId . '/' . $time,
            'msgSMS' => 'Майстерня дизайну: ' . $what . '. design.wolf.ua',
        ]);
    }

    /**
     * Транзакція з замком рядка замовлення (customerId — лише замовлення цього клієнта) і обраної пропозиції; $fn отримує
     * рядки (типізовані) і робить перевірки й записи. Будь-яка відмова — відкат
     * @param \Closure(array, array): void $fn
     */
    private static function locked(int $orderId, ?int $customerId, \Closure $fn): void
    {
        $pdo = Sql::pdo();
        $pdo->beginTransaction();
        try {
            $o = Sql::one('SELECT status, chosen_offer_id, paid_client, paid_designer, rework_price_designer, rework_price_client, rework_deadline,
                                  rework_proposed_at
                           FROM design_orders WHERE id = ?' . ($customerId !== null ? ' AND customer_id = ?' : '') . ' FOR UPDATE',
                $customerId !== null ? [$orderId, $customerId] : [$orderId]);
            if ($o === null || $o['chosen_offer_id'] === null) {
                throw new DesignerException($customerId === null ? self::ERR_DESIGNER : self::ERR_NOT_FOUND);
            }
            $f = Sql::one('SELECT id, designer_id, status, price_client, price_designer, markup_percent FROM design_offers
                           WHERE id = ? AND order_id = ? FOR UPDATE', [(int)$o['chosen_offer_id'], $orderId]);
            if ($f === null || (int)$f['status'] !== OfferStatus::CHOSEN) {
                throw new DesignerException($customerId === null ? self::ERR_DESIGNER : self::ERR_NOT_FOUND);
            }
            $fn(self::orderRow($o), [
                'id' => (int)$f['id'],
                'designer_id' => (int)$f['designer_id'],
                'price_client' => (int)$f['price_client'],
                'price_designer' => (int)$f['price_designer'],
                'markup_percent' => (float)$f['markup_percent'],
            ]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    private static function orderRow(array $o): array
    {
        return [
            'status' => (int)$o['status'],
            'paid_client' => (int)$o['paid_client'],
            'paid_designer' => (int)$o['paid_designer'],
            'rework_price_designer' => $o['rework_price_designer'] === null ? null : (int)$o['rework_price_designer'],
            'rework_price_client' => $o['rework_price_client'] === null ? null : (int)$o['rework_price_client'],
            'rework_deadline' => $o['rework_deadline'] === null ? null : (string)$o['rework_deadline'],
            'rework_proposed_at' => $o['rework_proposed_at'] === null ? null : (string)$o['rework_proposed_at'],
        ];
    }

    /** Доплата клієнта для текстів: «+360 грн» або «без доплати» (як «чий хід» і форма) */
    private static function extra(int $extra): string
    {
        return $extra > 0 ? '+' . self::money($extra) . ' грн' : 'без доплати';
    }

    /** 1020 → «1 020» (нерозривний пробіл; для тексту помилок — звичайний) */
    private static function money(int $v, string $sep = "\u{00A0}"): string
    {
        return number_format($v, 0, ',', $sep);
    }
}
