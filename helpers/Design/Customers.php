<?php

namespace Design;

/** Клієнти wolf.ua (customers) — тільки читання */
final class Customers
{
    /** @return array{name:string, short:string, initials:string, balance:int}|null для шапки: «Олена Мельник», «Олена М.», «ОМ», баланс у гривнях */
    public static function header(int $id): ?array
    {
        $r = Sql::one('SELECT first_name, last_name, inv_sum FROM customers WHERE id = ?', [$id]);
        if ($r === null) return null;
        $first = trim((string)$r['first_name']);
        $last = trim((string)$r['last_name']);
        $name = trim($first . ' ' . $last);
        if ($name === '') $name = 'Клієнт';
        $f = mb_strtoupper(mb_substr($first !== '' ? $first : $name, 0, 1));
        $l = mb_strtoupper(mb_substr($last, 0, 1));
        return [
            'name' => $name,
            'short' => $first !== '' ? trim($first . ($l !== '' ? ' ' . $l . '.' : '')) : $name,
            'initials' => $f . $l,
            'balance' => (int)round((float)$r['inv_sum']),
        ];
    }

    /** Субакаунт wolf.ua (customers.parent_id ≠ 0): на design нічого не може. Немає такого клієнта — не субакаунт */
    public static function isSub(int $id): bool
    {
        return (int)Sql::value('SELECT parent_id FROM customers WHERE id = ?', [$id]) !== 0;
    }

    /**
     * Факти про клієнта для брифу дизайнерів: orders — опубліковані замовлення (без чернеток), bought — з проведеною купівлею
     * (design_purchases.status 2; одне замовлення — раз), cancelled — скасовані
     * @return array{orders:int, bought:int, cancelled:int}
     */
    public static function facts(int $customerId): array
    {
        $r = Sql::one(
            'SELECT COUNT(*) AS orders,
                    COALESCE(SUM(EXISTS (SELECT 1 FROM design_purchases p WHERE p.order_id = o.id AND p.status = ?)), 0) AS bought,
                    COALESCE(SUM(o.status = ?), 0) AS cancelled
             FROM design_orders o WHERE o.customer_id = ? AND o.status <> ?',
            [Purchases::STATUS_DONE, OrderStatus::CANCELLED, $customerId, OrderStatus::DRAFT]);
        return ['orders' => (int)$r['orders'], 'bought' => (int)$r['bought'], 'cancelled' => (int)$r['cancelled']];
    }

    /** Рядок фактів для брифу: «Замовлень: 5 · куплено: 3 · скасовано: 1»; клієнт лише з цим замовленням (N ≤ 1) — «Новий клієнт» */
    public static function factsLine(array $facts): string
    {
        if ($facts['orders'] <= 1) return 'Новий клієнт';
        return 'Замовлень: ' . $facts['orders'] . ' · куплено: ' . $facts['bought'] . ' · скасовано: ' . $facts['cancelled'];
    }
}
