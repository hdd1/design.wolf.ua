<?php

namespace Design;

/** Налаштування з design_settings. Таблиця читається один раз за запит */
final class Settings
{
    private const DEFAULT_MARKUP_PERCENT = 20.0;
    private const DEFAULT_PRICE_ROUND = 10;
    private const DEFAULT_NO_OFFERS_HOURS = 24;
    private const DEFAULT_PREVIEW_MAX_PAGES = 30;
    private const DEFAULT_PREVIEW_MAX_MB = 50;
    private const DEFAULT_LAYOUT_MAX_MB = 1024;
    private const DEFAULT_LAYOUT_MAX_FILES = 20;
    private const DEFAULT_LAYOUT_MAX_TOTAL_MB = 5120;

    /** @var array<string, string>|null */
    private static ?array $cache = null;

    public static function get(string $key, string $default = ''): string
    {
        if (self::$cache === null) {
            self::$cache = [];
            foreach (Sql::all('SELECT `key`, `value` FROM design_settings') as $r) {
                self::$cache[(string)$r['key']] = (string)$r['value'];
            }
        }
        return self::$cache[$key] ?? $default;
    }

    /** Скидає кеш (тести, довгі процеси) */
    public static function reset(): void
    {
        self::$cache = null;
    }

    /** k — націнка на ціну дизайнера, % */
    public static function markupPercent(): float
    {
        $v = str_replace(',', '.', trim(self::get('markup_percent')));
        return is_numeric($v) && (float)$v >= 0 ? (float)$v : self::DEFAULT_MARKUP_PERCENT;
    }

    /** Крок округлення ціни для клієнта вгору, грн (не менше 1) */
    public static function priceRound(): int
    {
        $v = trim(self::get('price_round'));
        return is_numeric($v) ? max(1, (int)$v) : self::DEFAULT_PRICE_ROUND;
    }

    /** Через скільки годин бриф без пропозицій іде куратору */
    public static function noOffersHours(): int
    {
        $v = trim(self::get('no_offers_hours'));
        return is_numeric($v) && (int)$v > 0 ? (int)$v : self::DEFAULT_NO_OFFERS_HOURS;
    }

    /** users.id куратора (сповіщення про заявки, відмови, брифи без пропозицій); 0 — не задано */
    public static function notifyUserId(): int
    {
        return max(0, (int)self::get('applications_notify_user_id'));
    }

    /** Скільки сторінок PDF можна перетворити на одне превʼю */
    public static function previewMaxPages(): int
    {
        return self::positiveInt('preview_max_pages', self::DEFAULT_PREVIEW_MAX_PAGES);
    }

    /** Максимальний розмір файла превʼю, байти */
    public static function previewMaxBytes(): int
    {
        return self::positiveInt('preview_max_mb', self::DEFAULT_PREVIEW_MAX_MB) * 1048576;
    }

    /** Максимальний розмір одного макета, байти */
    public static function layoutMaxBytes(): int
    {
        return self::positiveInt('layout_max_mb', self::DEFAULT_LAYOUT_MAX_MB) * 1048576;
    }

    /** Скільки активних макетів може бути в замовленні */
    public static function layoutMaxFiles(): int
    {
        return self::positiveInt('layout_max_files', self::DEFAULT_LAYOUT_MAX_FILES);
    }

    /** Сумарний розмір активних макетів замовлення, байти */
    public static function layoutMaxTotalBytes(): int
    {
        return self::positiveInt('layout_max_total_mb', self::DEFAULT_LAYOUT_MAX_TOTAL_MB) * 1048576;
    }

    /** Ціле > 0 з налаштування, інакше значення за замовчуванням */
    private static function positiveInt(string $key, int $default): int
    {
        $v = trim(self::get($key));
        return is_numeric($v) && (int)$v > 0 ? (int)$v : $default;
    }
}
