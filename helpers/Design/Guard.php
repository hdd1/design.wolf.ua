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

    public const STALE = 'Сесія застаріла: нічого не збережено. Перевірте відповіді і натисніть ще раз.';
    /** Те саме для AJAX-дій сторінки: введеного в форму вони не гублять, досить оновити сторінку */
    public const STALE_AJAX = 'Сесія застаріла — оновіть сторінку';

    /** CSRF у POST. Невірний токен: код 403, сторінка показує форму знову з уже введеним (нічого не зберігаємо) */
    public static function csrfOk(int $customerId): bool
    {
        if (Csrf::check($customerId, (string)($_POST['csrf'] ?? ''))) return true;
        http_response_code(403);
        return false;
    }
}
