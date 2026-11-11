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
}
