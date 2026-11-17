<?php

namespace Design;

/** Маршруты: метод + путь → страница (pages/<page>.php) и параметры */
final class Router
{
    private const ID = '\d{1,9}';

    /** @var array<int, array{0:string,1:string,2:string}> метод, regex, страница */
    private const ROUTES = [
        ['GET', '#^/$#', 'home'],
        ['GET', '#^/brief/$#', 'brief-products'],
        ['GET', '#^/brief/(?<product>' . self::ID . ')/$#', 'brief-form'],
        ['POST', '#^/brief$#', 'brief-create'],
        ['GET', '#^/orders/$#', 'orders'],
        ['GET', '#^/orders/(?<id>' . self::ID . ')/$#', 'order'],
        ['GET', '#^/orders/(?<id>' . self::ID . ')/mode/$#', 'order-mode'],
        ['POST', '#^/orders/(?<id>' . self::ID . ')$#', 'order-update'],
    ];

    /** @return array{page:string,params:array<string,string>}|null */
    public static function match(string $method, string $path): ?array
    {
        foreach (self::ROUTES as [$m, $re, $page]) {
            if ($m !== $method || !preg_match($re, $path, $mm)) continue;
            $params = array_filter($mm, 'is_string', ARRAY_FILTER_USE_KEY);
            return ['page' => $page, 'params' => $params];
        }
        return null;
    }

    /** Адрес страницы без завершающего «/», который стоит перенаправить на версию со слэшем; иначе null */
    public static function withSlash(string $path): ?string
    {
        if (str_ends_with($path, '/') || preg_match('#\.\w+$#', $path)) return null;
        return self::match('GET', $path . '/') !== null ? $path . '/' : null;
    }
}
