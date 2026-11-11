<?php

namespace Design;

/** Доступ лише для клієнтів, що увійшли на wolf.ua */
final class Guard
{
    /** id клієнта; гість бачить «увійдіть на wolf.ua» з кодом 401 */
    public static function customer(): int
    {
        $id = Auth::current();
        if ($id > 0) return $id;
        http_response_code(401);
        Http::noStore();
        View::render('layout', ['title' => 'Потрібен вхід', 'body' => View::fetch('login-required')]);
        exit;
    }

    /** CSRF у POST: неправильний токен — 403 */
    public static function csrf(int $customerId): void
    {
        if (!Csrf::check($customerId, (string)($_POST['csrf'] ?? ''))) Http::forbidden();
    }
}
