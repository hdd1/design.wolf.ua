<?php

namespace Design;

/** Ответы: JSON, редиректы, 404. Все страницы design личные — без кэша */
final class Http
{
    public static function noStore(): void
    {
        header('Cache-Control: private, no-store');
    }

    public static function json(array $data, int $code = 200): never
    {
        self::noStore();
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function redirect(string $url, int $code = 302): never
    {
        http_response_code($code);
        header('Location: ' . $url);
        exit;
    }

    public static function forbidden(): never
    {
        http_response_code(403);
        self::noStore();
        View::render('message', ['title' => 'Сесія застаріла', 'text' => 'Оновіть сторінку і спробуйте ще раз.']);
        exit;
    }

    public static function notFound(): never
    {
        http_response_code(404);
        self::noStore();
        View::render('404', ['title' => 'Сторінку не знайдено']);
        exit;
    }
}
