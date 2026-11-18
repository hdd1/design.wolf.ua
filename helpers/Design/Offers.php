<?php

namespace Design;

/**
 * Пропозиції дизайнерів (design_offers): надіслати, змінити, відкликати; вибір клієнтом; стрічка брифів і «Мої пропозиції».
 * Одна пара замовлення+дизайнер — один рядок. Переходи статусів — атомарні, вибір — у транзакції з замком замовлення
 */
final class Offers
{
    public const TEXT_MAX = 2000;
    public const PRICE_MAX = 1000000;
    public const PER_PAGE = 24;

    private const ERR_NOT_FOUND = 'Пропозицію не знайдено';
    private const ERR_CLOSED = 'Замовлення вже не приймає пропозицій';
    private const ERR_ALREADY_CHOSEN = 'Дизайнера вже обрано';

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
        $designer = Designers::get($designerId);
        if ($designer === null || $designer['status'] !== DesignerStatus::APPROVED) {
            throw new DesignerException('Лише схвалені дизайнери можуть пропонувати');
        }
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

        $p = (int)Sql::value('SELECT price_client FROM design_offers WHERE id = ?', [$id]);
        $name = $designer['name'] !== '' ? $designer['name'] : 'дизайнера';
        Notify::send([
            'customer_id' => (int)$order['customer_id'],
            'msg' => 'Майстерня дизайну: нова пропозиція від ' . $name . ' по замовленню №' . $orderId . ' — ' . self::money($p) . ' грн. '
                . Config::siteUrl() . '/orders/' . $orderId . '/ (' . date('d.m H:i') . ')',
            'msgSMS' => 'Майстерня дизайну: нова пропозиція по №' . $orderId . '. design.wolf.ua',
        ]);
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

    /** Дизайнер відкликає надіслану пропозицію (до вибору). Повторно надіслати можна в той самий рядок */
    public static function withdraw(int $offerId, int $designerId): void
    {
        $offer = self::get($offerId);
        if ($offer === null || $offer['designer_id'] !== $designerId) throw new DesignerException(self::ERR_NOT_FOUND);

        $n = Sql::exec('UPDATE design_offers SET status = ? WHERE id = ? AND designer_id = ? AND status = ?',
            [OfferStatus::WITHDRAWN, $offerId, $designerId, OfferStatus::SENT]);
        if ($n === 0) throw new DesignerException('Пропозицію вже не можна відкликати');
        // Режим 2 (відкликання після «Так») — як відмова: стан замовлення і сповіщення додає задача 4
    }

    /** @return array{id:int, order_id:int, designer_id:int, status:int, text:string, price_designer:int, markup_percent:float, price_client:int, deadline:?string, created:string, updated:string, customer_id:int, order_status:int, mode:int, chosen_offer_id:?int}|null */
    public static function get(int $offerId): ?array
    {
        $r = Sql::one('SELECT ' . self::COLS . ' FROM design_offers f JOIN design_orders o ON o.id = f.order_id WHERE f.id = ?', [$offerId]);
        return $r === null ? null : self::row($r);
    }

    /**
     * Хто й як може бути в листуванні пропозиції: клієнт замовлення або дизайнер пропозиції, інакше null.
     * Писати — поки пропозиція запрошена / надіслана / обрана і замовлення не скасоване; після вибору — лише в обраній
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

        $write = in_array($offer['status'], [OfferStatus::INVITED, OfferStatus::SENT, OfferStatus::CHOSEN], true)
            && $offer['order_status'] !== OrderStatus::CANCELLED
            && ($offer['chosen_offer_id'] === null || $offer['status'] === OfferStatus::CHOSEN);
        return ['offer' => $offer, 'role' => $role, 'write' => $write];
    }

    /**
     * Пропозиції для клієнта на сторінці замовлення. Запрошення режиму 2 без відповіді дизайнера тут не показуються.
     * available — дизайнер досі схвалений (інакше «Дизайнер недоступний», обрати не можна)
     * @return list<array{id:int, status:int, designer_id:int, designer_name:string, designer_photo:string, available:bool, text:string, price_client:int, deadline:?string, messages:int, price_changed:bool, updated:string}>
     */
    public static function forOrder(int $orderId, int $customerId): array
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
             WHERE f.order_id = ? AND o.customer_id = ? AND f.status <> ?
             ORDER BY f.id",
            [Messages::ROLE_SYSTEM, Messages::ROLE_SYSTEM, $orderId, $customerId, OfferStatus::INVITED]);

        return array_map(static fn(array $r): array => [
            'id' => (int)$r['id'],
            'status' => (int)$r['status'],
            'designer_id' => (int)$r['designer_id'],
            'designer_name' => (string)$r['designer_name'],
            'designer_photo' => (string)$r['designer_photo'],
            'available' => $r['designer_status'] !== null && (int)$r['designer_status'] === DesignerStatus::APPROVED,
            'text' => (string)$r['text'],
            'price_client' => (int)$r['price_client'],
            'deadline' => $r['deadline'] === null ? null : (string)$r['deadline'],
            'messages' => (int)$r['messages'],
            'price_changed' => (bool)$r['price_changed'],
            'updated' => (string)$r['updated'],
        ], $rows);
    }

    /**
     * Клієнт обирає надіслану пропозицію: замовлення → «В роботі», обрана → «обрано», решта надісланих → «обрано іншого».
     * Одна транзакція з замком рядка замовлення: з двох одночасних виборів другий отримає «Дизайнера вже обрано»
     */
    public static function choose(int $orderId, int $customerId, int $offerId): void
    {
        $pdo = Sql::pdo();
        $pdo->beginTransaction();
        try {
            $order = Sql::one('SELECT status, chosen_offer_id FROM design_orders WHERE id = ? AND customer_id = ? FOR UPDATE', [$orderId, $customerId]);
            if ($order === null) throw new DesignerException('Замовлення не знайдено');
            if ($order['chosen_offer_id'] !== null) throw new DesignerException(self::ERR_ALREADY_CHOSEN);
            if ((int)$order['status'] !== OrderStatus::COLLECTING) throw new DesignerException('Замовлення зараз не можна змінити');

            $offer = Sql::one('SELECT designer_id, status FROM design_offers WHERE id = ? AND order_id = ? FOR UPDATE', [$offerId, $orderId]);
            if ($offer === null || (int)$offer['status'] !== OfferStatus::SENT) throw new DesignerException('Пропозиція недоступна');
            $designerId = (int)$offer['designer_id'];
            if (Designers::status($designerId) !== DesignerStatus::APPROVED) throw new DesignerException('Дизайнер недоступний');

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
     * Записує пропозицію «надіслано» зі знімком ціни: новий рядок або той самий після відмови / відкликання.
     * Статус замовлення перевіряє той, хто викликає
     */
    private static function send(int $orderId, int $designerId, string $text, int $price, string $deadline): int
    {
        [$k, $p] = self::snapshot($price);
        $values = ['status' => OfferStatus::SENT, 'text' => $text, 'price_designer' => $price, 'markup_percent' => $k, 'price_client' => $p, 'deadline' => $deadline];

        $row = Sql::one('SELECT id, status FROM design_offers WHERE order_id = ? AND designer_id = ?', [$orderId, $designerId]);
        if ($row === null) {
            try {
                return Sql::insert('design_offers', ['order_id' => $orderId, 'designer_id' => $designerId] + $values);
            } catch (\PDOException $e) {
                // Подвійний клік: рядок щойно вставив паралельний запит
                if ($e->getCode() === '23000') throw new DesignerException('Ви вже запропонували — змініть пропозицію');
                throw $e;
            }
        }
        $id = (int)$row['id'];
        $n = Sql::exec('UPDATE design_offers SET ' . implode(' = ?, ', array_keys($values)) . ' = ?
                        WHERE id = ? AND status IN (' . implode(', ', self::RESENDABLE) . ')',
            array_merge(array_values($values), [$id]));
        if ($n > 0) return $id;

        $status = (int)Sql::value('SELECT status FROM design_offers WHERE id = ?', [$id]);
        throw new DesignerException($status === OfferStatus::SENT ? 'Ви вже запропонували — змініть пропозицію' : 'Пропозицію зараз не можна надіслати');
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
