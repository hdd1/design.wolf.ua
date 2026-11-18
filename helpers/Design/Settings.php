<?php

namespace Design;

/** Налаштування з design_settings. Таблиця читається один раз за запит */
final class Settings
{
    private const DEFAULT_MARKUP_PERCENT = 20.0;
    private const DEFAULT_PRICE_ROUND = 10;
    private const DEFAULT_NO_OFFERS_HOURS = 24;

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
}
