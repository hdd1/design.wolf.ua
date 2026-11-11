<?php

namespace Design;

/**
 * Клиент wolf.ua по куке wolfid (ставит wolf.ua на весь .wolf.ua).
 * Формат: "<id>.<hmac_md5(customer_pass . User-Agent, id)>" — как Customer::getWolfID() v1,
 * но без побочных эффектов: куки не удаляет, сессию не трогает.
 */
final class Auth
{
    /** @param callable(int):?string $passwordOf пароль-хэш клиента или null */
    public static function customerId(?string $wolfid, string $userAgent, callable $passwordOf): int
    {
        if ($wolfid === null || !preg_match('/^(\d{1,10})\.([0-9a-f]{32})$/', $wolfid, $m)) {
            return 0;
        }
        $id = (int)$m[1];
        $pass = $passwordOf($id);
        if ($pass === null) return 0;
        return hash_equals(hash_hmac('md5', $pass . $userAgent, (string)$id), $m[2]) ? $id : 0;
    }

    public static function current(): int
    {
        static $id = null;
        return $id ??= self::customerId(
            $_COOKIE['wolfid'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? '',
            static function (int $id): ?string {
                $v = Sql::value('SELECT customer_pass FROM customers WHERE id = ?', [$id]);
                return $v === null ? null : (string)$v;
            }
        );
    }
}
