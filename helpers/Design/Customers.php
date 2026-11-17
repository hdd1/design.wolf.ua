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
}
