<?php

namespace Design;

/**
 * Скарги клієнта після купівлі (design_complaints, спека «Скарга»): кнопка «Скарга» в замовленнях «Куплено» і «Доопрацювання».
 * Рядок прив'язаний до останньої проведеної покупки; статус 1 «нова». Розбирають вручну (перегляд в adminka — етап 7),
 * сповіщення — куратору клієнта і user_id = 1. Замовлення лишається як було; повторні скарги дозволені
 */
final class Complaints
{
    public const TEXT_MAX = 2000;
    public const STATUS_NEW = 1;
    /** «Вам» — власник Майстерні, users.id (спека: скарга — куратору і user_id = 1) */
    public const OWNER_USER_ID = 1;

    private const STATUSES = [OrderStatus::PURCHASED, OrderStatus::REWORK];
    private const ERR_STATUS = 'Поскаржитися можна після купівлі макетів';

    /**
     * Нова скарга клієнта на своє замовлення «Куплено» / «Доопрацювання» з проведеною покупкою; text — 1–TEXT_MAX символів.
     * Сповіщення — notify(), сторінка шле його після відповіді браузеру
     * @return int id скарги
     * @throws DesignerException
     */
    public static function add(int $orderId, int $customerId, string $text): int
    {
        $text = trim(mb_scrub($text));
        if ($text === '') throw new DesignerException('Опишіть скаргу');
        if (mb_strlen($text) > self::TEXT_MAX) throw new DesignerException('Скарга — не довше ' . self::TEXT_MAX . ' символів');

        $status = Sql::value('SELECT status FROM design_orders WHERE id = ? AND customer_id = ?', [$orderId, $customerId]);
        if ($status === null) throw new DesignerException('Замовлення не знайдено');
        $purchaseId = Sql::value('SELECT id FROM design_purchases WHERE order_id = ? AND status = ? ORDER BY id DESC LIMIT 1',
            [$orderId, Purchases::STATUS_DONE]);
        if (!in_array((int)$status, self::STATUSES, true) || $purchaseId === null) throw new DesignerException(self::ERR_STATUS);

        return Sql::insert('design_complaints', [
            'order_id' => $orderId,
            'purchase_id' => (int)$purchaseId,
            'text' => $text,
            'status' => self::STATUS_NEW,
        ]);
    }

    /**
     * Остання скарга замовлення — для «Скаргу надіслано {дата}»; немає — null
     * @return array{created:string}|null
     */
    public static function lastForOrder(int $orderId): ?array
    {
        $created = Sql::value('SELECT created FROM design_complaints WHERE order_id = ? ORDER BY id DESC LIMIT 1', [$orderId]);
        return $created === null ? null : ['created' => (string)$created];
    }

    /** Сповіщення про скаргу (після відповіді браузеру): куратору клієнта і user_id = 1 — з текстом скарги */
    public static function notify(int $complaintId): void
    {
        $r = Sql::one('SELECT c.order_id, c.text, o.customer_id FROM design_complaints c JOIN design_orders o ON o.id = c.order_id WHERE c.id = ?',
            [$complaintId]);
        if ($r === null) return;
        $orderId = (int)$r['order_id'];
        $customerId = (int)$r['customer_id'];
        $client = Customers::header($customerId)['name'] ?? 'Клієнт';
        $msg = 'Майстерня дизайну: скарга на замовлення №' . $orderId . ' від клієнта ' . $client . ' (id ' . $customerId . '): '
            . preg_replace('/\s+/u', ' ', (string)$r['text']) . ' (' . date('d.m H:i') . ')';
        $sms = 'Майстерня дизайну: скарга по №' . $orderId . '. design.wolf.ua';
        // Куратор клієнта і є user 1 — одного сповіщення досить
        if ((int)Sql::value('SELECT curator_id FROM customers WHERE id = ?', [$customerId]) !== self::OWNER_USER_ID) {
            Notify::curator($customerId, $msg, $sms);
        }
        Notify::send(['user_id' => self::OWNER_USER_ID, 'msg' => $msg, 'msgSMS' => $sms]);
    }
}
