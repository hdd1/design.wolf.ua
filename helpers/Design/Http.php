<?php

namespace Design;

/** Ответы: JSON, редиректы, 404. Все страницы design личные — без кэша */
final class Http
{
    /** Текстове поле з $_POST/$_GET: не рядок (name[]=x, немає поля) — порожньо, без warning і без «Array» */
    public static function str(array $src, string $key): string
    {
        $v = $src[$key] ?? '';
        return is_string($v) ? $v : '';
    }

    public static function noStore(): void
    {
        header('Cache-Control: private, no-store');
    }

    /**
     * Тести: замість виводу відповіді respond() віддає (дані, код) сюди — без заголовків і без закриття запиту
     * @internal
     * @var (\Closure(array, int): void)|null
     */
    public static ?\Closure $output = null;

    public static function json(array $data, int $code = 200): never
    {
        self::noStore();
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * JSON-відповідь, після якої лишається робота, якої браузер не має чекати (сповіщення: api2 через спільний CalcApiGlobal
     * без тайм-ауту). Відповідь віддається, і запит закривається (fastcgi_finish_request; поза php-fpm — flush); лише тоді
     * виконується $after. Її збій — лише в журнал: відповідь уже віддано. Без exit — сторінка завершується сама
     */
    public static function respond(array $data, int $code, ?\Closure $after = null): void
    {
        if (self::$output !== null) {
            (self::$output)($data, $code);
            self::finishThen($after, false);
            return;
        }
        self::noStore();
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        self::finishThen($after, true);
    }

    /** $after — як у respond(): виконується, коли браузер уже отримав перенаправлення (сповіщення після дії форми) */
    public static function redirect(string $url, int $code = 302, ?\Closure $after = null): never
    {
        http_response_code($code);
        header('Location: ' . $url);
        self::finishThen($after, true);
        exit;
    }

    /**
     * Робота після відповіді: закрити запит ($close — fastcgi_finish_request; поза php-fpm — flush), тоді $after.
     * Збій $after — лише в журнал: відповідь уже віддано. Без $after — нічого (запит закриється сам)
     */
    private static function finishThen(?\Closure $after, bool $close): void
    {
        if ($after === null) return;
        if ($close) {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } else {
                while (ob_get_level() > 0) ob_end_flush();
                flush();
            }
        }
        try {
            $after();
        } catch (\Throwable $e) {
            error_log('design: робота після відповіді ' . ($_SERVER['REQUEST_URI'] ?? '') . ': ' . $e);
        }
    }

    public static function notFound(): never
    {
        http_response_code(404);
        self::noStore();
        View::render('404', ['title' => 'Сторінку не знайдено']);
        exit;
    }
}
