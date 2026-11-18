<?php

namespace Design;

/** Ціна для клієнта: P = D·(1 + k/100), вгору до кроку округлення. Лише цілі числа — без похибки float */
final class Price
{
    /**
     * @param int   $designer       D — сума дизайнеру «на руки», грн
     * @param float $markupPercent  k — націнка, %; враховуються соті частки відсотка
     * @param int   $round          крок округлення вгору, грн (менше 1 вважається за 1)
     */
    public static function client(int $designer, float $markupPercent, int $round): int
    {
        $round = max(1, $round);
        $k = (int)round($markupPercent * 100);          // соті частки відсотка
        $num = $designer * (10000 + $k);                // P · 10000 до округлення
        $den = 10000 * $round;
        return intdiv($num + $den - 1, $den) * $round;  // ceil без float
    }
}
