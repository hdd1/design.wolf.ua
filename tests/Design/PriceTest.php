<?php

use Design\Price;
use PHPUnit\Framework\TestCase;

final class PriceTest extends TestCase
{
    public function test_rounding(): void
    {
        $cases = [
            [850, 20.0, 10, 1020],
            [1000, 20.0, 10, 1200],
            [833, 20.0, 10, 1000],
            [1, 20.0, 10, 10],
            [1000, 0.0, 10, 1000],
            [995, 20.0, 1, 1194],
            [1000, 17.5, 10, 1180],
        ];
        foreach ($cases as [$d, $k, $round, $expected]) {
            $this->assertSame($expected, Price::client($d, $k, $round), "$d / $k / $round");
        }
    }

    public function test_no_float_error_on_exact_multiples(): void
    {
        // 1.1 · 100 в float дає 110.00000000000001 — вгору до 120 не округлюється
        $this->assertSame(110, Price::client(100, 10.0, 10));
        $this->assertSame(1145, Price::client(1000, 14.5, 5));
        // k з копійками відсотка: 12.34% від 1000 = 1123.4 → 1130
        $this->assertSame(1130, Price::client(1000, 12.34, 10));
    }

    public function test_round_below_one_is_treated_as_one(): void
    {
        $this->assertSame(1194, Price::client(995, 20.0, 0));
        $this->assertSame(1194, Price::client(995, 20.0, -5));
    }
}
