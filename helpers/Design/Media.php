<?php

namespace Design;

/**
 * Адреси WebP-картинок портфоліо. Файли лежать у сховищі поза webroot, віддає їх CDN i.fastcdn.kiev.ua
 * (як wolf.ua і календар): ImageGlobal::encode робить симлінк у cloud-links/ci і повертає адресу.
 * Імʼя картинки без розширення: «{workId}-card», «{workId}-view» або «avatar-{8 hex}».
 * Оригінали ({id}.jpg|png) не кодуються ніколи. Закритість — у тому, що адреси прихованих робіт
 * і неодобрених дизайнерів сторінки просто не виводять.
 */
final class Media
{
    private const NAME_RE = '/^(?:\d{1,9}-(?:card|view)|avatar-[0-9a-f]{8})$/';

    /**
     * Адреса картинки на CDN. Порожній рядок — імʼя не з дозволеного набору або файлу немає
     * (ImageGlobal::encode у такому разі підставив би no-image зі свого сайту, а в design такого файлу нема):
     * сторінки показують заглушку.
     * Папку сховища не створює: читання не повинно залишати слідів.
     */
    public static function url(int $designerId, string $name): string
    {
        if (!preg_match(self::NAME_RE, $name)) return '';
        $file = self::path($designerId, $name);
        if (!is_file($file)) return '';
        require_once '/home/connect/cdn.wolf.ua/helpers/ImageGlobal.php';
        return \ImageGlobal::encode($file);
    }

    /**
     * Застаріле: adminka до 2026-11-19; TTL ігнорується.
     * Лишається, щоб design і adminka можна було викотити в будь-якому порядку (стара adminka кличе signedUrl).
     * @deprecated використовуйте Media::url
     */
    public static function signedUrl(int $designerId, string $name, int $ttl = 3600, ?int $now = null): string
    {
        return self::url($designerId, $name);
    }

    /** Шлях до файлу в сховищі */
    public static function path(int $designerId, string $name): string
    {
        return Config::storageRoot() . "/portfolio/$designerId/$name.webp";
    }
}
