<?php

namespace Design;

/** Статуси замовлення (design_orders.status) */
final class OrderStatus
{
    public const DRAFT = 1;
    public const COLLECTING = 2;
    public const WAITING_DESIGNER = 3;
    public const IN_WORK = 4;
    public const AWAITING_INVOICE = 5;
    public const PURCHASED = 6;
    public const REWORK = 7;
    public const CANCELLED = 8;

    private const LABELS = [
        self::DRAFT => 'Чернетка',
        self::COLLECTING => 'Збір пропозицій',
        self::WAITING_DESIGNER => 'Очікує відповіді дизайнера',
        self::IN_WORK => 'В роботі',
        self::AWAITING_INVOICE => 'Очікує оплати рахунку',
        self::PURCHASED => 'Куплено',
        self::REWORK => 'Доопрацювання',
        self::CANCELLED => 'Скасовано',
    ];

    public static function label(int $s): string
    {
        return self::LABELS[$s] ?? '';
    }

    /**
     * «Чий хід» для клієнта: mine — чекаємо дії клієнта.
     * @return array{mine:bool, text:string}
     */
    public static function turn(int $status, int $offers = 0, int $layouts = 0): array
    {
        return match ($status) {
            self::DRAFT => ['mine' => true, 'text' => 'Ваш хід: допишіть і опублікуйте бриф'],
            self::COLLECTING => $offers > 0
                ? ['mine' => true, 'text' => 'Ваш хід: оберіть дизайнера · ' . $offers . ' ' . self::plural($offers, 'пропозиція', 'пропозиції', 'пропозицій')]
                : ['mine' => false, 'text' => 'Чекаємо пропозицій від дизайнерів'],
            self::WAITING_DESIGNER => ['mine' => false, 'text' => 'Чекаємо відповіді дизайнера'],
            self::IN_WORK => $layouts > 0
                ? ['mine' => true, 'text' => 'Ваш хід: перевірте макети і натисніть «Купую»']
                : ['mine' => false, 'text' => 'Дизайнер працює над макетами'],
            self::AWAITING_INVOICE => ['mine' => false, 'text' => 'Чекаємо оплату рахунку — макети відкриються після неї'],
            self::PURCHASED => ['mine' => false, 'text' => 'Готово · макети у вашій хмарі wolf.ua'],
            self::REWORK => ['mine' => false, 'text' => 'Дизайнер працює над змінами'],
            default => ['mine' => false, 'text' => 'Скасовано'],
        };
    }

    /** 1 пропозиція, 2–4 пропозиції, 5–20 пропозицій, 21 пропозиція… */
    public static function plural(int $n, string $one, string $few, string $many): string
    {
        $m100 = $n % 100;
        $m10 = $n % 10;
        if ($m100 >= 11 && $m100 <= 14) return $many;
        if ($m10 === 1) return $one;
        if ($m10 >= 2 && $m10 <= 4) return $few;
        return $many;
    }
}
