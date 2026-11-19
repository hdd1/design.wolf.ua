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
        ['GET', '#^/orders/(?<id>' . self::ID . ')/pick/$#', 'order-pick'],
        ['POST', '#^/orders/(?<id>' . self::ID . ')$#', 'order-update'],
        ['POST', '#^/orders/(?<id>' . self::ID . ')/upload$#', 'order-upload'],
        ['POST', '#^/orders/(?<id>' . self::ID . ')/previews/(?<preview>' . self::ID . ')$#', 'order-preview'],
        ['GET', '#^/designer/$#', 'designer'],
        ['POST', '#^/designer$#', 'designer-save'],
        ['POST', '#^/designer/works$#', 'designer-work-add'],
        ['POST', '#^/designer/works/order$#', 'designer-works-order'],
        ['POST', '#^/designer/works/(?<id>' . self::ID . ')$#', 'designer-work'],
        ['POST', '#^/designer/photo$#', 'designer-photo'],
        ['GET', '#^/designers/$#', 'designers'],
        ['GET', '#^/designers/(?<id>' . self::ID . ')/$#', 'designer-public'],
        ['GET', '#^/offers/(?<id>' . self::ID . ')/messages$#', 'offer-messages'],
        ['POST', '#^/offers/(?<id>' . self::ID . ')/messages$#', 'offer-message-add'],
        ['GET', '#^/feed/$#', 'feed'],
        ['GET', '#^/feed/(?<id>' . self::ID . ')/$#', 'feed-order'],
        ['POST', '#^/feed/(?<id>' . self::ID . ')$#', 'feed-offer'],
        ['GET', '#^/my-offers/$#', 'my-offers'],
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
