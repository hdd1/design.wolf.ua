<?php

namespace Design;

/** Доступ лише для клієнтів, що увійшли на wolf.ua */
final class Guard
{
    /** Субакаунт: текст відмови для дій (JSON) */
    public const SUB_ACCOUNT = 'З субакаунта замовити дизайн не можна — потрібен основний акаунт wolf.ua';

    /**
     * id клієнта; гість бачить «увійдіть на wolf.ua» з кодом 401. Субакаунт wolf.ua (parent_id ≠ 0) не може нічого:
     * сторінка — банер з кодом 403, дія (POST або AJAX-читання) — JSON {error} з кодом 403. Головна не викликає Guard
     */
    public static function customer(): int
    {
        $id = Auth::current();
        if ($id <= 0) {
            http_response_code(401);
            Http::noStore();
            View::render('layout', ['title' => 'Потрібен вхід', 'body' => View::fetch('login-required')]);
            exit;
        }
        if (Customers::isSub($id)) {
            if (self::wantsJson()) Http::json(['error' => self::SUB_ACCOUNT], 403);
            http_response_code(403);
            Http::noStore();
            View::render('layout', ['title' => 'Субакаунт', 'body' => View::fetch('sub-account')]);
            exit;
        }
        return $id;
    }

    /** Відповідь чекає JS: будь-який не-GET (дії сторінок) і GET-маршрути, що віддають JSON (список макетів, листування) */
    private static function wantsJson(): bool
    {
        if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) return true;
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        return in_array(Router::match('GET', $path)['page'] ?? '', ['order-layouts', 'offer-messages'], true);
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
