<?php

namespace Design;

/** CSRF-токен изменяющих запросов клиента: HMAC от id клиента и часа, действует текущий и прошлый час */
final class Csrf
{
    public static function token(int $customerId, ?int $hourTs = null): string
    {
        return hash_hmac('sha256', $customerId . '|' . date('Y-m-d H', $hourTs ?? time()), Config::csrfSecret());
    }

    public static function check(int $customerId, string $token): bool
    {
        if ($token === '') return false;
        return hash_equals(self::token($customerId), $token) || hash_equals(self::token($customerId, time() - 3600), $token);
    }
}
