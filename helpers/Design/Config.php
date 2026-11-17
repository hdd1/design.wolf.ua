<?php

namespace Design;

/** Настройки design.wolf.ua */
final class Config
{
    /** Хранилище проекта (секреты, служебные файлы): env DESIGN_STORAGE_ROOT или /home/connect/design_storage */
    public static function storageRoot(): string
    {
        $env = getenv('DESIGN_STORAGE_ROOT');
        return rtrim($env !== false && $env !== '' ? $env : '/home/connect/design_storage', '/');
    }

    /** Секрет CSRF: env DESIGN_CSRF_SECRET или файл в хранилище (создаётся при первом обращении, читаем для группы www-data) */
    public static function csrfSecret(): string
    {
        $env = getenv('DESIGN_CSRF_SECRET');
        if ($env !== false && $env !== '') return $env;

        $file = self::storageRoot() . '/csrf.secret';
        if (!is_file($file)) {
            if (!is_dir(self::storageRoot())) mkdir(self::storageRoot(), 0770, true);
            $tmp = $file . '.' . bin2hex(random_bytes(4));
            file_put_contents($tmp, bin2hex(random_bytes(32)));
            // Создать может и CLI от root, а читать должен php-fpm от www-data
            chmod($tmp, 0640);
            @chgrp($tmp, 'www-data');
            // link не перезаписывает: при гонке двух первых запросов секрет останется один
            if (!@link($tmp, $file) && !is_file($file)) rename($tmp, $file);
            @unlink($tmp);
        }
        $secret = trim((string)@file_get_contents($file));
        if (strlen($secret) < 32) {
            // Пустой ключ HMAC сделал бы токены предсказуемыми
            throw new \RuntimeException("Секрет CSRF недоступен или пуст: $file");
        }
        return $secret;
    }

    /** Куда вести гостя на вход. Пока — главная wolf.ua (dev — dev.wolf.ua); адрес входа с возвратом уточняется у владельца */
    public static function loginUrl(string $back): string
    {
        return 'https://' . (self::isDev() ? 'dev.' : '') . 'wolf.ua/';
    }

    /** Адреса самого сайту: dev.design.wolf.ua на dev, design.wolf.ua на проді */
    public static function siteUrl(): string
    {
        return 'https://' . (self::isDev() ? 'dev.' : '') . 'design.wolf.ua';
    }

    /** Адреса adminka: dev.adminka.wolf.ua на dev, adminka.wolf.ua на проді */
    public static function adminkaUrl(): string
    {
        return 'https://' . (self::isDev() ? 'dev.' : '') . 'adminka.wolf.ua';
    }

    /** dev-стенд: HTTP_HOST починається з «dev.» */
    public static function isDev(): bool
    {
        return str_starts_with($_SERVER['HTTP_HOST'] ?? '', 'dev.');
    }
}
