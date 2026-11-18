<?php

namespace Design;

/**
 * Пропозиції дизайнерів (design_offers): надіслати, змінити, відкликати; вибір клієнтом; стрічка брифів і «Мої пропозиції»;
 * режим 2 «Одразу обрати»: запрошення, «Так» / «Ні», «Запросити іншого», «Збирати пропозиції».
 * Одна пара замовлення+дизайнер — один рядок. Переходи статусів — атомарні, у транзакціях замок завжди спершу на рядок
 * замовлення, потім на рядок пропозиції
 */
final class Offers
{
    public const TEXT_MAX = 2000;
    public const PRICE_MAX = 1000000;
    public const PER_PAGE = 24;

    private const ERR_NOT_FOUND = 'Пропозицію не знайдено';
    private const ERR_CLOSED = 'Замовлення вже не приймає пропозицій';
    private const ERR_ALREADY_CHOSEN = 'Дизайнера вже обрано';
    private const ERR_ORDER_NOT_FOUND = 'Замовлення не знайдено';
    private const ERR_ORDER_LOCKED = 'Замовлення зараз не можна змінити';
    private const ERR_UNAVAILABLE = 'Дизайнер недоступний';
    private const ERR_INVITE_GONE = 'Запрошення вже неактуальне';
    private const ERR_ALREADY_SENT = 'Ви вже запропонували — змініть пропозицію';

    /** Пропозиції, що тримають замовлення режиму 2: запрошення без відповіді або «Так» (якщо дизайнер досі схвалений) */
    private const ACTIVE = [OfferStatus::INVITED, OfferStatus::SENT];

    /** Статуси, при яких дизайнер може надіслати пропозицію в той самий рядок ще раз */
    private const RESENDABLE = [OfferStatus::DECLINED, OfferStatus::WITHDRAWN];

    /** Вкладка «Пропозиції» в «Мої пропозиції»; «В роботі» — CHOSEN */
    private const MINE_OFFERS = [OfferStatus::INVITED, OfferStatus::SENT, OfferStatus::DECLINED, OfferStatus::WITHDRAWN, OfferStatus::OTHER_CHOSEN];

    private const COLS = 'f.id, f.order_id, f.designer_id, f.status, f.text, f.price_designer, f.markup_percent, f.price_client, f.deadline, f.created, f.updated,
        o.customer_id, o.status AS order_status, o.mode, o.chosen_offer_id';

    /**
     * Режим 1: дизайнер пропонує себе на опублікований бриф. Рядка ще немає або дизайнер відмовлявся/відкликав → «надіслано»
     * зі знімком D, k, P. Сповіщає клієнта.
     * @return int id пропозиції
     */
    public static function propose(int $orderId, int $designerId, string $text, int $price, string $deadline): int
    {
        $designer = self::approved($designerId);
        $text = self::validate($text, $price, $deadline);

        $pdo = Sql::pdo();
        $pdo->beginTransaction();
        try {
            // Спільний замок замовлення: вибір дизайнера (FOR UPDATE) не проскочить між перевіркою статусу і записом пропозиції
            $order = Sql::one('SELECT customer_id, status, mode FROM design_orders WHERE id = ? FOR SHARE', [$orderId]);
            if ($order !== null && (int)$order['customer_id'] === $designerId) {
                throw new DesignerException('Це ваше замовлення — пропонувати на нього не можна');
            }
            if ($order === null || (int)$order['status'] !== OrderStatus::COLLECTING || (int)$order['mode'] !== 1) {
                throw new DesignerException(self::ERR_CLOSED);
            }
            $id = self::send($orderId, $designerId, $text, $price, $deadline);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        self::notifyNewOffer($orderId, (int)$order['customer_id'], $designer, $id);
        return $id;
    }

    /**
     * Змінити надіслану пропозицію (до вибору). Нова сума — нова P за поточною націнкою; без зміни суми знімок ціни лишається.
     * Зміна ціни для клієнта або терміну — системне повідомлення в листування
     */
    public static function update(int $offerId, int $designerId, string $text, int $price, string $deadline): void
    {
        $text = self::validate($text, $price, $deadline);

        $pdo = Sql::pdo();
        $pdo->beginTransaction();
        try {
            // Замок рядка: вибір клієнтом або друга вкладка не перепишуть ціну між читанням і записом
            $row = Sql::one('SELECT status, price_designer, markup_percent, price_client, deadline FROM design_offers
                             WHERE id = ? AND designer_id = ? FOR UPDATE', [$offerId, $designerId]);
            if ($row === null) throw new DesignerException(self::ERR_NOT_FOUND);
            if ((int)$row['status'] !== OfferStatus::SENT) throw new DesignerException('Пропозицію вже не можна змінити');

            $oldP = (int)$row['price_client'];
            $oldDeadline = (string)$row['deadline'];
            if ($price !== (int)$row['price_designer']) {
                [$k, $p] = self::snapshot($price);
            } else {
                [$k, $p] = [(float)$row['markup_percent'], $oldP];
            }
            Sql::exec('UPDATE design_offers SET text = ?, price_designer = ?, markup_percent = ?, price_client = ?, deadline = ?
                       WHERE id = ? AND status = ?', [$text, $price, $k, $p, $deadline, $offerId, OfferStatus::SENT]);

            if ($p !== $oldP) {
                Messages::system($offerId, 'Ціну змінено з ' . self::money($oldP) . ' на ' . self::money($p) . ' грн',
                    ['event' => 'price', 'from' => $oldP, 'to' => $p]);
            }
            if ($deadline !== $oldDeadline) {
                Messages::system($offerId, 'Термін змінено з ' . View::date($oldDeadline) . ' на ' . View::date($deadline),
                    ['event' => 'deadline', 'from' => $oldDeadline, 'to' => $deadline]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Дизайнер відкликає надіслану пропозицію (до вибору). Повторно надіслати можна в той самий рядок.
     * Режим 2 (відкликання після «Так») — як відмова: замовлення лишається в зборі режиму 2 без пропозиції,
     * клієнту — «Запросити іншого» / «Збирати пропозиції», клієнту й куратору — ті самі сповіщення, що й при «Ні»
     */
    public static function withdraw(int $offerId, int $designerId): void
    {
        $orderId = self::orderOf($offerId, $designerId);

        $pdo = Sql::pdo();
        $pdo->beginTransaction();
        try {
            $order = Sql::one('SELECT customer_id, mode FROM design_orders WHERE id = ? FOR SHARE', [$orderId]);
            $n = Sql::exec('UPDATE design_offers SET status = ? WHERE id = ? AND designer_id = ? AND status = ?',
                [OfferStatus::WITHDRAWN, $offerId, $designerId, OfferStatus::SENT]);
            if ($n === 0) throw new DesignerException('Пропозицію вже не можна відкликати');
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        // У замовленні режиму 2 надіслана пропозиція буває лише з запрошення: propose приймає тільки режим 1
        if ($order !== null && (int)$order['mode'] === 2) self::notifyRefusal($orderId, (int)$order['customer_id'], $designerId);
    }

    /**
     * Режим 2: клієнт обирає схваленого дизайнера з каталогу. З чернетки — публікація з вибором
     * (WAITING_DESIGNER, mode = 2, published_at = NOW()); зі збору режиму 2 без активної пропозиції — «Запросити іншого».
     * Рядок новий або той самий після відмови / відкликання → «запрошено». Запрошення недоступних (заблокованих) дизайнерів
     * клієнт бачить як відмову — їх закриваємо. Повтор (подвійний клік: цей дизайнер уже запрошений і чекаємо його) нічого
     * не змінює і не шле. Сповіщає дизайнера
     */
    public static function invite(int $orderId, int $customerId, int $designerId): void
    {
        if ($designerId === $customerId) throw new DesignerException('Це ваше замовлення — обрати себе не можна');
        if (Designers::status($designerId) !== DesignerStatus::APPROVED) throw new DesignerException(self::ERR_UNAVAILABLE);

        $pdo = Sql::pdo();
        $pdo->beginTransaction();
        try {
            $order = Sql::one('SELECT status, mode FROM design_orders WHERE id = ? AND customer_id = ? FOR UPDATE', [$orderId, $customerId]);
            if ($order === null) throw new DesignerException(self::ERR_ORDER_NOT_FOUND);
            if ((int)$order['status'] === OrderStatus::WAITING_DESIGNER && (int)$order['mode'] === 2
                && (int)Sql::value('SELECT status FROM design_offers WHERE order_id = ? AND designer_id = ?', [$orderId, $designerId]) === OfferStatus::INVITED) {
                $pdo->commit();
                return;
            }
            $draft = (int)$order['status'] === OrderStatus::DRAFT;
            if (!$draft && !self::reopenable($orderId, (int)$order['status'], (int)$order['mode'])) {
                throw new DesignerException(self::ERR_ORDER_LOCKED);
            }
            self::closeStaleInvites($orderId);

            $row = Sql::one('SELECT id FROM design_offers WHERE order_id = ? AND designer_id = ? FOR UPDATE', [$orderId, $designerId]);
            if ($row === null) {
                Sql::insert('design_offers', ['order_id' => $orderId, 'designer_id' => $designerId, 'status' => OfferStatus::INVITED]);
            } else {
                // Нове запрошення — з чистими полями: текст, ціна й термін попереднього раунду не мають показуватися
                $n = Sql::exec('UPDATE design_offers SET status = ?, text = NULL, price_designer = 0, markup_percent = 0, price_client = 0, deadline = NULL
                                WHERE id = ? AND status IN (' . implode(', ', self::RESENDABLE) . ')',
                    [OfferStatus::INVITED, (int)$row['id']]);
                if ($n === 0) throw new DesignerException(self::ERR_ORDER_LOCKED);
            }

            if ($draft) {
                Sql::exec('UPDATE design_orders SET status = ?, mode = 2, published_at = NOW() WHERE id = ? AND status = ?',
                    [OrderStatus::WAITING_DESIGNER, $orderId, OrderStatus::DRAFT]);
            } else {
                Sql::exec('UPDATE design_orders SET status = ? WHERE id = ? AND mode = 2 AND status IN (?, ?)',
                    [OrderStatus::WAITING_DESIGNER, $orderId, OrderStatus::COLLECTING, OrderStatus::WAITING_DESIGNER]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        $client = Customers::header($customerId)['short'] ?? 'Клієнт';
        Notify::send([
            'customer_id' => $designerId,
            'msg' => 'Майстерня дизайну: клієнт ' . $client . ' обрав вас для замовлення №' . $orderId . '. Береш? '
                . Config::siteUrl() . '/feed/' . $orderId . '/ (' . date('d.m H:i') . ')',
            'msgSMS' => 'Майстерня дизайну: вас обрали для №' . $orderId . '. design.wolf.ua',
        ]);
    }

    /**
     * Режим 2, «Так»: запрошення → надіслана пропозиція (ті самі перевірки і знімок ціни, що й у propose),
     * замовлення → збір пропозицій (mode лишається 2). Сповіщає клієнта, як про нову пропозицію
     */
    public static function accept(int $offerId, int $designerId, string $text, int $price, string $deadline): void
    {
        $designer = self::approved($designerId);
        $text = self::validate($text, $price, $deadline);
        $orderId = self::orderOf($offerId, $designerId);

        $pdo = Sql::pdo();
        $pdo->beginTransaction();
        try {
            $order = self::lockInvite($orderId, $offerId);
            self::send($orderId, $designerId, $text, $price, $deadline, [OfferStatus::INVITED]);
            Sql::exec('UPDATE design_orders SET status = ? WHERE id = ? AND status = ?',
                [OrderStatus::COLLECTING, $orderId, OrderStatus::WAITING_DESIGNER]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        self::notifyNewOffer($orderId, (int)$order['customer_id'], $designer, $offerId);
    }

    /**
     * Режим 2, «Ні»: запрошення → відмова, замовлення → збір пропозицій (mode лишається 2: клієнт обирає «Запросити іншого»
     * або «Збирати пропозиції»). Непорожня причина — повідомлення дизайнера в листуванні з позначкою data.event = decline
     * (клієнт бачить як причину лише її, див. declineReason). Сповіщає клієнта і куратора
     */
    public static function decline(int $offerId, int $designerId, string $reason): void
    {
        $orderId = self::orderOf($offerId, $designerId);
        $reason = trim($reason);

        $pdo = Sql::pdo();
        $pdo->beginTransaction();
        try {
            $order = self::lockInvite($orderId, $offerId);
            Sql::exec('UPDATE design_offers SET status = ? WHERE id = ? AND status = ?', [OfferStatus::DECLINED, $offerId, OfferStatus::INVITED]);
            Sql::exec('UPDATE design_orders SET status = ? WHERE id = ? AND status = ?',
                [OrderStatus::COLLECTING, $orderId, OrderStatus::WAITING_DESIGNER]);
            // Задовга причина — виняток і відкат: відмова не записується без неї
            if ($reason !== '') Messages::add($offerId, $designerId, Messages::ROLE_DESIGNER, $reason, ['event' => 'decline']);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        self::notifyRefusal($orderId, (int)$order['customer_id'], $designerId);
    }

    /**
     * Режим 2 → «Збирати пропозиції»: замовлення без активної пропозиції стає режимом 1 і зʼявляється в стрічці як нове
     * (published_at = NOW(), крон рахує добу від цього моменту). Повтор (подвійний клік) нічого не змінює
     */
    public static function collect(int $orderId, int $customerId): void
    {
        $pdo = Sql::pdo();
        $pdo->beginTransaction();
        try {
            $order = Sql::one('SELECT status, mode FROM design_orders WHERE id = ? AND customer_id = ? FOR UPDATE', [$orderId, $customerId]);
            if ($order === null) throw new DesignerException(self::ERR_ORDER_NOT_FOUND);
            $status = (int)$order['status'];
            if ($status === OrderStatus::COLLECTING && (int)$order['mode'] === 1) {
                $pdo->commit();
                return;
            }
            if (!self::reopenable($orderId, $status, (int)$order['mode'])) throw new DesignerException(self::ERR_ORDER_LOCKED);
            self::closeStaleInvites($orderId);
            Sql::exec('UPDATE design_orders SET status = ?, mode = 1, published_at = NOW(), notified_no_offers_at = NULL WHERE id = ?',
                [OrderStatus::COLLECTING, $orderId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Стан режиму 2 для екрана клієнта: waiting — запрошення без відповіді, offer — дизайнер сказав «Так» (обирайте),
     * declined — відмовився, відкликав або став недоступним (кнопки «Запросити іншого» / «Збирати пропозиції»).
     * offer — картка в тому ж вигляді, що й у forOrder (для declined — остання за часом). Не режим 2, не очікування / збір
     * або чуже замовлення — null
     * @return array{state:'waiting'|'declined'|'offer', offer:?array}|null
     */
    public static function mode2State(int $orderId, int $customerId): ?array
    {
        $order = Sql::one('SELECT status, mode FROM design_orders WHERE id = ? AND customer_id = ?', [$orderId, $customerId]);
        if ($order === null || (int)$order['mode'] !== 2
            || !in_array((int)$order['status'], [OrderStatus::WAITING_DESIGNER, OrderStatus::COLLECTING], true)) {
            return null;
        }
        $cards = self::cards($orderId, $customerId, true);
        foreach ($cards as $c) {
            if ($c['available'] && in_array($c['status'], self::ACTIVE, true)) {
                return ['state' => $c['status'] === OfferStatus::INVITED ? 'waiting' : 'offer', 'offer' => $c];
            }
        }
        usort($cards, static fn(array $a, array $b): int => [$b['updated'], $b['id']] <=> [$a['updated'], $a['id']]);
        return ['state' => 'declined', 'offer' => $cards[0] ?? null];
    }

    /**
     * Причина відмови від запрошення режиму 2 для клієнта: останнє повідомлення дизайнера з позначкою data.event = decline,
     * записане не раніше за саму відмову (updated рядка — час переходу в «відмова»; облік seen/notified його не рухає).
     * Тож причина минулого раунду (рядок запрошували знову) і звичайні повідомлення дизайнера причиною не стають.
     * Пропозиція не у «відмова» — null
     * @return array{id:int, role:int, author_id:int, text:string, data:?array, created:string}|null у вигляді Messages::list
     */
    public static function declineReason(int $offerId): ?array
    {
        $id = Sql::value(
            "SELECT m.id FROM design_messages m JOIN design_offers f ON f.id = m.offer_id
             WHERE m.offer_id = ? AND f.status = ? AND m.author_role = ? AND m.created >= f.updated
               AND JSON_UNQUOTE(JSON_EXTRACT(m.data, '$.event')) = 'decline'
             ORDER BY m.id DESC LIMIT 1",
            [$offerId, OfferStatus::DECLINED, Messages::ROLE_DESIGNER]);
        return $id === null ? null : (Messages::list($offerId, (int)$id - 1, 1)[0] ?? null);
    }

    /** @return array{id:int, order_id:int, designer_id:int, status:int, text:string, price_designer:int, markup_percent:float, price_client:int, deadline:?string, created:string, updated:string, customer_id:int, order_status:int, mode:int, chosen_offer_id:?int}|null */
    public static function get(int $offerId): ?array
    {
        $r = Sql::one('SELECT ' . self::COLS . ' FROM design_offers f JOIN design_orders o ON o.id = f.order_id WHERE f.id = ?', [$offerId]);
        return $r === null ? null : self::row($r);
    }

    /** Пропозиція (будь-якого статусу) цього дизайнера на замовлення — у вигляді get(); немає — null */
    public static function forDesigner(int $orderId, int $designerId): ?array
    {
        $r = Sql::one('SELECT ' . self::COLS . ' FROM design_offers f JOIN design_orders o ON o.id = f.order_id
                       WHERE f.order_id = ? AND f.designer_id = ?', [$orderId, $designerId]);
        return $r === null ? null : self::row($r);
    }

    /**
     * Хто й як може бути в листуванні пропозиції: клієнт замовлення або дизайнер пропозиції, інакше null.
     * Писати — поки пропозиція надіслана / обрана, замовлення не скасоване і дизайнер досі схвалений; після вибору — лише в обраній.
     * Запрошення без відповіді — лише читання: листування починається з «Беру». Недоступний дизайнер — читання для обох сторін
     * @return array{offer:array, role:int, write:bool}|null
     */
    public static function access(int $offerId, int $customerId): ?array
    {
        $offer = self::get($offerId);
        if ($offer === null) return null;
        $role = match ($customerId) {
            $offer['customer_id'] => Messages::ROLE_CLIENT,
            $offer['designer_id'] => Messages::ROLE_DESIGNER,
            default => null,
        };
        if ($role === null) return null;

        $write = in_array($offer['status'], [OfferStatus::SENT, OfferStatus::CHOSEN], true)
            && $offer['order_status'] !== OrderStatus::CANCELLED
            && ($offer['chosen_offer_id'] === null || $offer['status'] === OfferStatus::CHOSEN)
            && Designers::status($offer['designer_id']) === DesignerStatus::APPROVED;
        return ['offer' => $offer, 'role' => $role, 'write' => $write];
    }

    /**
     * Пропозиції для клієнта на сторінці замовлення. Запрошення режиму 2 без відповіді дизайнера тут не показуються.
     * available — дизайнер досі схвалений (інакше «Дизайнер недоступний», обрати не можна)
     * @return list<array{id:int, status:int, designer_id:int, designer_name:string, designer_photo:string, available:bool, text:string, price_client:int, deadline:?string, messages:int, price_changed:bool, updated:string}>
     */
    public static function forOrder(int $orderId, int $customerId): array
    {
        return self::cards($orderId, $customerId, false);
    }

    /**
     * Клієнт обирає надіслану пропозицію: замовлення → «В роботі», обрана → «обрано», решта надісланих → «обрано іншого».
     * Одна транзакція з замком рядка замовлення: з двох одночасних виборів другий отримає «Дизайнера вже обрано».
     * expectedPrice — ціна, яку клієнт бачив і підтвердив: якщо дизайнер тим часом її змінив, нічого не змінюється
     */
    public static function choose(int $orderId, int $customerId, int $offerId, int $expectedPrice): void
    {
        $pdo = Sql::pdo();
        $pdo->beginTransaction();
        try {
            $order = Sql::one('SELECT status, chosen_offer_id FROM design_orders WHERE id = ? AND customer_id = ? FOR UPDATE', [$orderId, $customerId]);
            if ($order === null) throw new DesignerException(self::ERR_ORDER_NOT_FOUND);
            if ($order['chosen_offer_id'] !== null) throw new DesignerException(self::ERR_ALREADY_CHOSEN);
            if ((int)$order['status'] !== OrderStatus::COLLECTING) throw new DesignerException(self::ERR_ORDER_LOCKED);

            $offer = Sql::one('SELECT designer_id, status, price_client FROM design_offers WHERE id = ? AND order_id = ? FOR UPDATE', [$offerId, $orderId]);
            if ($offer === null || (int)$offer['status'] !== OfferStatus::SENT) throw new DesignerException('Пропозиція недоступна');
            if ((int)$offer['price_client'] !== $expectedPrice) throw new DesignerException('Ціну змінено — перегляньте пропозицію');
            $designerId = (int)$offer['designer_id'];
            if (Designers::status($designerId) !== DesignerStatus::APPROVED) throw new DesignerException(self::ERR_UNAVAILABLE);

            Sql::exec('UPDATE design_orders SET status = ?, chosen_offer_id = ?, chosen_at = NOW() WHERE id = ? AND status = ? AND chosen_offer_id IS NULL',
                [OrderStatus::IN_WORK, $offerId, $orderId, OrderStatus::COLLECTING]);
            Sql::exec('UPDATE design_offers SET status = ? WHERE id = ? AND status = ?', [OfferStatus::CHOSEN, $offerId, OfferStatus::SENT]);
            Sql::exec('UPDATE design_offers SET status = ? WHERE order_id = ? AND id <> ? AND status = ?',
                [OfferStatus::OTHER_CHOSEN, $orderId, $offerId, OfferStatus::SENT]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        Notify::send([
            'customer_id' => $designerId,
            'msg' => 'Майстерня дизайну: клієнт обрав вашу пропозицію по №' . $orderId . '. '
                . Config::siteUrl() . '/feed/' . $orderId . '/ (' . date('d.m H:i') . ')',
            'msgSMS' => 'Майстерня дизайну: вас обрали виконавцем №' . $orderId . '. design.wolf.ua',
        ]);
    }

    /**
     * Картки пропозицій замовлення для клієнта (forOrder, mode2State); invited — разом із запрошеннями без відповіді
     * @return list<array{id:int, status:int, designer_id:int, designer_name:string, designer_photo:string, available:bool, text:string, price_client:int, deadline:?string, messages:int, price_changed:bool, updated:string}>
     */
    private static function cards(int $orderId, int $customerId, bool $invited): array
    {
        $rows = Sql::all(
            "SELECT f.id, f.status, f.designer_id, d.name AS designer_name, d.photo AS designer_photo, d.status AS designer_status,
                    f.text, f.price_client, f.deadline, f.updated,
                    (SELECT COUNT(*) FROM design_messages m WHERE m.offer_id = f.id AND m.author_role <> ?) AS messages,
                    EXISTS (SELECT 1 FROM design_messages m WHERE m.offer_id = f.id AND m.author_role = ?
                            AND JSON_UNQUOTE(JSON_EXTRACT(m.data, '$.event')) = 'price') AS price_changed
             FROM design_offers f
             JOIN design_orders o ON o.id = f.order_id
             LEFT JOIN design_designers d ON d.customer_id = f.designer_id
             WHERE f.order_id = ? AND o.customer_id = ?" . ($invited ? '' : ' AND f.status <> ' . OfferStatus::INVITED) . "
             ORDER BY f.id",
            [Messages::ROLE_SYSTEM, Messages::ROLE_SYSTEM, $orderId, $customerId]);

        return array_map(static function (array $r): array {
            // Запрошення ще без відповіді: ні тексту, ні ціни, ні терміну (навіть якщо в рядку лишилося щось від минулого раунду)
            $pending = (int)$r['status'] === OfferStatus::INVITED;
            return [
                'id' => (int)$r['id'],
                'status' => (int)$r['status'],
                'designer_id' => (int)$r['designer_id'],
                'designer_name' => (string)$r['designer_name'],
                'designer_photo' => (string)$r['designer_photo'],
                'available' => $r['designer_status'] !== null && (int)$r['designer_status'] === DesignerStatus::APPROVED,
                'text' => $pending ? '' : (string)$r['text'],
                'price_client' => $pending ? 0 : (int)$r['price_client'],
                'deadline' => $pending || $r['deadline'] === null ? null : (string)$r['deadline'],
                'messages' => (int)$r['messages'],
                'price_changed' => (bool)$r['price_changed'],
                'updated' => (string)$r['updated'],
            ];
        }, $rows);
    }

    /**
     * Стрічка брифів схваленого дизайнера: брифи в зборі пропозицій режиму 1 (крім своїх замовлень)
     * і замовлення, де клієнт обрав іншого, а цей дизайнер пропонував. Нові зверху
     * @return array{items:list<array{order_id:int, product_name:string, title:string, published_at:?string, preview:list<array{q:string,a:string}>, offers:int, mine:?array{id:int,status:int,text:string,price_designer:int,price_client:int,deadline:?string}, chosen_other:bool, is_new:bool}>, total:int, pages:int}
     */
    public static function feed(int $designerId, int $page): array
    {
        $seenAt = self::approvedSeenAt($designerId);
        if ($seenAt === false) return ['items' => [], 'total' => 0, 'pages' => 1];

        [$where, $params] = self::feedWhere($designerId);
        $total = (int)Sql::value("SELECT COUNT(*) FROM design_orders o
            LEFT JOIN design_offers m ON m.order_id = o.id AND m.designer_id = ? WHERE $where", array_merge([$designerId], $params));
        $pages = max(1, (int)ceil($total / self::PER_PAGE));
        $offset = (max(1, $page) - 1) * self::PER_PAGE;

        $rows = Sql::all(
            "SELECT o.id, o.title, o.answers, o.published_at, p.name AS product_name,
                    (SELECT COUNT(*) FROM design_offers x WHERE x.order_id = o.id AND x.status IN (?, ?)) AS offers,
                    m.id AS mine_id, m.status AS mine_status, m.text AS mine_text, m.price_designer, m.price_client, m.deadline
             FROM design_orders o
             JOIN design_products p ON p.id = o.product_id
             LEFT JOIN design_offers m ON m.order_id = o.id AND m.designer_id = ?
             WHERE $where
             ORDER BY o.published_at DESC, o.id DESC LIMIT " . self::PER_PAGE . " OFFSET $offset",
            array_merge([OfferStatus::SENT, OfferStatus::CHOSEN, $designerId], $params));

        $items = array_map(static fn(array $r): array => [
            'order_id' => (int)$r['id'],
            'product_name' => (string)$r['product_name'],
            'title' => (string)$r['title'] !== '' ? (string)$r['title'] : (string)$r['product_name'],
            'published_at' => $r['published_at'] === null ? null : (string)$r['published_at'],
            'preview' => self::preview((string)$r['answers']),
            'offers' => (int)$r['offers'],
            'mine' => $r['mine_id'] === null ? null : [
                'id' => (int)$r['mine_id'],
                'status' => (int)$r['mine_status'],
                'text' => (string)$r['mine_text'],
                'price_designer' => (int)$r['price_designer'],
                'price_client' => (int)$r['price_client'],
                'deadline' => $r['deadline'] === null ? null : (string)$r['deadline'],
            ],
            'chosen_other' => $r['mine_status'] !== null && (int)$r['mine_status'] === OfferStatus::OTHER_CHOSEN,
            'is_new' => $seenAt === null || ($r['published_at'] !== null && (string)$r['published_at'] > $seenAt),
        ], $rows);
        return ['items' => $items, 'total' => $total, 'pages' => $pages];
    }

    /** Кружечок у меню: брифи, опубліковані після останнього відкриття стрічки (ще не відкривав — усі) */
    public static function feedNewCount(int $designerId): int
    {
        $seenAt = self::approvedSeenAt($designerId);
        if ($seenAt === false) return 0;
        $sql = 'SELECT COUNT(*) FROM design_orders WHERE status = ? AND mode = 1 AND customer_id <> ?';
        $params = [OrderStatus::COLLECTING, $designerId];
        if ($seenAt !== null) {
            $sql .= ' AND published_at > ?';
            $params[] = $seenAt;
        }
        return (int)Sql::value($sql, $params);
    }

    /** Дизайнер відкрив стрічку */
    public static function markFeedSeen(int $designerId): void
    {
        Sql::exec('UPDATE design_designers SET feed_seen_at = NOW() WHERE customer_id = ?', [$designerId]);
    }

    /**
     * «Мої пропозиції»: вкладка «Пропозиції» (запрошення, надіслані, відмови, відкликані, обрано іншого) і «В роботі» (обрані).
     * Клієнт — лише скорочене імʼя. Свіжі зверху
     * @return array{offers:list<array{id:int, order_id:int, status:int, order_status:int, mode:int, product_name:string, title:string, client:string, text:string, price_designer:int, price_client:int, deadline:?string, messages:int, updated:string}>, work:list<array>}
     */
    public static function mine(int $designerId): array
    {
        $rows = Sql::all(
            'SELECT f.id, f.order_id, f.status, f.text, f.price_designer, f.price_client, f.deadline, f.updated,
                    o.status AS order_status, o.mode, o.customer_id, o.title, p.name AS product_name,
                    (SELECT COUNT(*) FROM design_messages m WHERE m.offer_id = f.id AND m.author_role <> ?) AS messages
             FROM design_offers f
             JOIN design_orders o ON o.id = f.order_id
             JOIN design_products p ON p.id = o.product_id
             WHERE f.designer_id = ?
             ORDER BY f.updated DESC, f.id DESC',
            [Messages::ROLE_SYSTEM, $designerId]);

        $clients = [];
        $out = ['offers' => [], 'work' => []];
        foreach ($rows as $r) {
            $status = (int)$r['status'];
            $tab = match (true) {
                $status === OfferStatus::CHOSEN => 'work',
                in_array($status, self::MINE_OFFERS, true) => 'offers',
                default => null,
            };
            if ($tab === null) continue;
            $cid = (int)$r['customer_id'];
            $clients[$cid] ??= (Customers::header($cid)['short'] ?? 'Клієнт');
            $out[$tab][] = [
                'id' => (int)$r['id'],
                'order_id' => (int)$r['order_id'],
                'status' => $status,
                'order_status' => (int)$r['order_status'],
                'mode' => (int)$r['mode'],
                'product_name' => (string)$r['product_name'],
                'title' => (string)$r['title'] !== '' ? (string)$r['title'] : (string)$r['product_name'],
                'client' => $clients[$cid],
                'text' => (string)$r['text'],
                'price_designer' => (int)$r['price_designer'],
                'price_client' => (int)$r['price_client'],
                'deadline' => $r['deadline'] === null ? null : (string)$r['deadline'],
                'messages' => (int)$r['messages'],
                'updated' => (string)$r['updated'],
            ];
        }
        return $out;
    }

    /**
     * Записує пропозицію «надіслано» зі знімком ціни: новий рядок або той самий з одного зі статусів from
     * (режим 1 — після відмови / відкликання, «Так» у режимі 2 — із запрошення). Статус замовлення перевіряє той, хто викликає
     * @param list<int> $from
     */
    private static function send(int $orderId, int $designerId, string $text, int $price, string $deadline, array $from = self::RESENDABLE): int
    {
        [$k, $p] = self::snapshot($price);
        $values = ['status' => OfferStatus::SENT, 'text' => $text, 'price_designer' => $price, 'markup_percent' => $k, 'price_client' => $p, 'deadline' => $deadline];

        $row = Sql::one('SELECT id, status FROM design_offers WHERE order_id = ? AND designer_id = ?', [$orderId, $designerId]);
        if ($row === null) {
            try {
                return Sql::insert('design_offers', ['order_id' => $orderId, 'designer_id' => $designerId] + $values);
            } catch (\PDOException $e) {
                // Подвійний клік: рядок щойно вставив паралельний запит
                if ($e->getCode() === '23000') throw new DesignerException(self::ERR_ALREADY_SENT);
                throw $e;
            }
        }
        $id = (int)$row['id'];
        $n = Sql::exec('UPDATE design_offers SET ' . implode(' = ?, ', array_keys($values)) . ' = ?
                        WHERE id = ? AND status IN (' . implode(', ', $from) . ')',
            array_merge(array_values($values), [$id]));
        if ($n > 0) return $id;

        $status = (int)Sql::value('SELECT status FROM design_offers WHERE id = ?', [$id]);
        throw new DesignerException($status === OfferStatus::SENT ? self::ERR_ALREADY_SENT : 'Пропозицію зараз не можна надіслати');
    }

    /** @return array дизайнер (Designers::get), якщо схвалений */
    private static function approved(int $designerId): array
    {
        $designer = Designers::get($designerId);
        if ($designer === null || $designer['status'] !== DesignerStatus::APPROVED) {
            throw new DesignerException('Лише схвалені дизайнери можуть пропонувати');
        }
        return $designer;
    }

    /** Замовлення пропозиції цього дизайнера; чужа або неіснуюча — «Пропозицію не знайдено» */
    private static function orderOf(int $offerId, int $designerId): int
    {
        $orderId = Sql::value('SELECT order_id FROM design_offers WHERE id = ? AND designer_id = ?', [$offerId, $designerId]);
        if ($orderId === null) throw new DesignerException(self::ERR_NOT_FOUND);
        return (int)$orderId;
    }

    /**
     * У транзакції: замок замовлення, потім пропозиції; запрошення ще чекає відповіді («Так» / «Ні»)
     * @return array{customer_id:mixed} рядок замовлення
     */
    private static function lockInvite(int $orderId, int $offerId): array
    {
        $order = Sql::one('SELECT customer_id, status, mode FROM design_orders WHERE id = ? FOR UPDATE', [$orderId]);
        $status = (int)Sql::value('SELECT status FROM design_offers WHERE id = ? FOR UPDATE', [$offerId]);
        if ($status === OfferStatus::SENT) throw new DesignerException(self::ERR_ALREADY_SENT);
        if ($status !== OfferStatus::INVITED || $order === null
            || (int)$order['status'] !== OrderStatus::WAITING_DESIGNER || (int)$order['mode'] !== 2) {
            throw new DesignerException(self::ERR_INVITE_GONE);
        }
        return $order;
    }

    /**
     * Замовлення режиму 2 можна знову віддати іншому дизайнеру або на збір пропозицій: у ньому немає активної пропозиції
     * схваленого дизайнера (запрошення недоступного клієнт бачить як відмову). Викликати під замком замовлення
     */
    private static function reopenable(int $orderId, int $status, int $mode): bool
    {
        if ($mode !== 2 || !in_array($status, [OrderStatus::COLLECTING, OrderStatus::WAITING_DESIGNER], true)) return false;
        return !(bool)Sql::value(
            'SELECT EXISTS (SELECT 1 FROM design_offers f JOIN design_designers d ON d.customer_id = f.designer_id
                            WHERE f.order_id = ? AND f.status IN (' . implode(', ', self::ACTIVE) . ') AND d.status = ?)',
            [$orderId, DesignerStatus::APPROVED]);
    }

    /** Запрошення недоступних дизайнерів (заблокований тощо) → відмова, щоб не «ожили» після розблокування */
    private static function closeStaleInvites(int $orderId): void
    {
        Sql::exec('UPDATE design_offers SET status = ? WHERE order_id = ? AND status = ?
                   AND designer_id NOT IN (SELECT customer_id FROM design_designers WHERE status = ?)',
            [OfferStatus::DECLINED, $orderId, OfferStatus::INVITED, DesignerStatus::APPROVED]);
    }

    /** Клієнту — нова пропозиція (режим 1 і «Так» режиму 2) */
    private static function notifyNewOffer(int $orderId, int $customerId, array $designer, int $offerId): void
    {
        $p = (int)Sql::value('SELECT price_client FROM design_offers WHERE id = ?', [$offerId]);
        $name = $designer['name'] !== '' ? $designer['name'] : 'дизайнера';
        Notify::send([
            'customer_id' => $customerId,
            'msg' => 'Майстерня дизайну: нова пропозиція від ' . $name . ' по замовленню №' . $orderId . ' — ' . self::money($p) . ' грн. '
                . Config::siteUrl() . '/orders/' . $orderId . '/ (' . date('d.m H:i') . ')',
            'msgSMS' => 'Майстерня дизайну: нова пропозиція по №' . $orderId . '. design.wolf.ua',
        ]);
    }

    /** Режим 2: дизайнер сказав «Ні» або відкликав «Так» — клієнту і куратору клієнта */
    private static function notifyRefusal(int $orderId, int $customerId, int $designerId): void
    {
        $name = (string)(Designers::get($designerId)['name'] ?? '');
        $time = date('d.m H:i');
        $sms = 'Майстерня дизайну: дизайнер відмовився від №' . $orderId . '. design.wolf.ua';
        Notify::send([
            'customer_id' => $customerId,
            'msg' => 'Майстерня дизайну: ' . ($name !== '' ? $name : 'Дизайнер') . ' не може взяти №' . $orderId . '. Оберіть іншого дизайнера: '
                . Config::siteUrl() . '/orders/' . $orderId . '/ (' . $time . ')',
            'msgSMS' => $sms,
        ]);
        $client = Customers::header($customerId)['name'] ?? 'Клієнт';
        self::notifyCurator($customerId,
            'Майстерня дизайну: дизайнер ' . ($name !== '' ? $name : 'id ' . $designerId) . ' відмовився від №' . $orderId
            . ' (клієнт ' . $client . ', id ' . $customerId . ') (' . $time . ')',
            $sms);
    }

    /**
     * Крон (раз на годину): бриф у збиранні пропозицій (режим 1) довше Settings::noOffersHours() без жодної «надіслано» / «обрано»
     * → куратору клієнта один раз. Відмітка ставиться й коли куратора немає (лише error_log), щоб не повторювати щогодини
     * @return int скільки брифів оброблено
     */
    public static function notifyNoOffers(): int
    {
        $rows = Sql::all(
            'SELECT o.id, o.customer_id, p.name AS product_name FROM design_orders o JOIN design_products p ON p.id = o.product_id
             WHERE o.status = ? AND o.mode = 1 AND o.notified_no_offers_at IS NULL
               AND o.published_at < NOW() - INTERVAL ' . Settings::noOffersHours() . ' HOUR
               AND NOT EXISTS (SELECT 1 FROM design_offers f WHERE f.order_id = o.id AND f.status IN (?, ?))
             ORDER BY o.id',
            [OrderStatus::COLLECTING, OfferStatus::SENT, OfferStatus::CHOSEN]);

        $done = 0;
        foreach ($rows as $r) {
            // Захоплюємо відмітку першими: паралельний запуск або пропозиція, що встигла прийти, не дадуть дубля
            $claimed = Sql::exec(
                'UPDATE design_orders SET notified_no_offers_at = NOW() WHERE id = ? AND status = ? AND notified_no_offers_at IS NULL
                   AND NOT EXISTS (SELECT 1 FROM design_offers f WHERE f.order_id = design_orders.id AND f.status IN (?, ?))',
                [$r['id'], OrderStatus::COLLECTING, OfferStatus::SENT, OfferStatus::CHOSEN]);
            if ($claimed === 0) continue;
            $done++;
            $customerId = (int)$r['customer_id'];
            $client = Customers::header($customerId)['name'] ?? 'Клієнт';
            self::notifyCurator($customerId,
                'Майстерня дизайну: бриф №' . $r['id'] . ' (' . $r['product_name'] . ') клієнта ' . $client . ' (id ' . $customerId . ') добу без пропозицій ('
                . date('d.m H:i') . ')',
                'Майстерня дизайну: бриф №' . $r['id'] . ' без пропозицій. design.wolf.ua');
        }
        return $done;
    }

    /** Куратор клієнта — customers.curator_id (users.id); немає — лише запис у журнал */
    private static function notifyCurator(int $customerId, string $msg, string $msgSMS): void
    {
        $curatorId = (int)Sql::value('SELECT curator_id FROM customers WHERE id = ?', [$customerId]);
        if ($curatorId <= 0) {
            error_log('Design\\Offers: у клієнта id ' . $customerId . ' немає куратора — сповіщення не надіслано: ' . $msg);
            return;
        }
        Notify::send(['user_id' => $curatorId, 'msg' => $msg, 'msgSMS' => $msgSMS]);
    }

    /** @return array{0:float, 1:int} поточна націнка k і ціна для клієнта P */
    private static function snapshot(int $price): array
    {
        $k = Settings::markupPercent();
        return [$k, Price::client($price, $k, Settings::priceRound())];
    }

    /** Перевірка полів пропозиції; повертає обрізаний текст */
    private static function validate(string $text, int $price, string $deadline): string
    {
        $text = trim($text);
        if ($text === '') throw new DesignerException('Опишіть, що зробите');
        if (mb_strlen($text) > self::TEXT_MAX) throw new DesignerException('Опис — не більше ' . self::TEXT_MAX . ' символів');
        if ($price < 1) throw new DesignerException('Вкажіть суму від 1 грн');
        if ($price > self::PRICE_MAX) throw new DesignerException('Сума — не більше ' . self::money(self::PRICE_MAX, ' ') . ' грн');

        $d = \DateTime::createFromFormat('!Y-m-d', $deadline);
        if ($d === false || $d->format('Y-m-d') !== $deadline) throw new DesignerException('Вкажіть дату «Готово до»');
        // Дата календарна за Києвом (часовий пояс задає bootstrap); сьогодні — можна
        if ($deadline < date('Y-m-d')) throw new DesignerException('Дата — не раніше сьогодні');
        return $text;
    }

    /**
     * Схвалений дизайнер: його feed_seen_at (null — стрічку ще не відкривав); не схвалений — false
     * @return string|null|false
     */
    private static function approvedSeenAt(int $designerId): string|null|false
    {
        $r = Sql::one('SELECT status, feed_seen_at FROM design_designers WHERE customer_id = ?', [$designerId]);
        if ($r === null || (int)$r['status'] !== DesignerStatus::APPROVED) return false;
        return $r['feed_seen_at'] === null ? null : (string)$r['feed_seen_at'];
    }

    /** @return array{0:string, 1:array} умова стрічки; m — пропозиція цього дизайнера (LEFT JOIN) */
    private static function feedWhere(int $designerId): array
    {
        return [
            '((o.status = ? AND o.mode = 1 AND o.customer_id <> ?) OR m.status = ?)',
            [OrderStatus::COLLECTING, $designerId, OfferStatus::OTHER_CHOSEN],
        ];
    }

    /** @return list<array{q:string,a:string}> перші дві непорожні відповіді брифу */
    private static function preview(string $answers): array
    {
        $out = [];
        foreach (json_decode($answers, true) ?: [] as $p) {
            $a = trim((string)($p['a'] ?? ''));
            if ($a === '') continue;
            $out[] = ['q' => (string)($p['q'] ?? ''), 'a' => $a];
            if (count($out) === 2) break;
        }
        return $out;
    }

    /** 1020 → «1 020» (нерозривний пробіл) */
    private static function money(int $v, string $sep = "\u{00A0}"): string
    {
        return number_format($v, 0, ',', $sep);
    }

    private static function row(array $r): array
    {
        return [
            'id' => (int)$r['id'],
            'order_id' => (int)$r['order_id'],
            'designer_id' => (int)$r['designer_id'],
            'status' => (int)$r['status'],
            'text' => (string)$r['text'],
            'price_designer' => (int)$r['price_designer'],
            'markup_percent' => (float)$r['markup_percent'],
            'price_client' => (int)$r['price_client'],
            'deadline' => $r['deadline'] === null ? null : (string)$r['deadline'],
            'created' => (string)$r['created'],
            'updated' => (string)$r['updated'],
            'customer_id' => (int)$r['customer_id'],
            'order_status' => (int)$r['order_status'],
            'mode' => (int)$r['mode'],
            'chosen_offer_id' => $r['chosen_offer_id'] === null ? null : (int)$r['chosen_offer_id'],
        ];
    }
}
