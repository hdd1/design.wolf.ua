<?php
/**
 * Инициализация design.wolf.ua для веба и CLI: автозагрузка Design\* и общих хелперов cdn.wolf.ua.
 * Без config/config.php wolf.ua: он стартует сессию и синглтоны движка.
 */

// api2 и HMAC-токен CalcApi считают часы по Киеву
date_default_timezone_set('Europe/Kiev');
ini_set('default_charset', 'UTF-8');
mb_internal_encoding('UTF-8');

require __DIR__ . '/autoload.php';

const DESIGN_CDN_HELPERS = '/home/connect/cdn.wolf.ua/helpers/';

// CdnConfig, CalcApi… — из cdn.wolf.ua
spl_autoload_register(function (string $class): void {
    if (str_contains($class, '\\')) return;
    $file = DESIGN_CDN_HELPERS . $class . '.php';
    if (is_file($file)) require $file;
});
