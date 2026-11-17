<?php

use Design\OrderStatus as S;
use PHPUnit\Framework\TestCase;

final class OrderStatusTest extends TestCase
{
    public function test_turn_table(): void
    {
        $cases = [
            [[S::DRAFT], true, 'Ваш хід: допишіть і опублікуйте бриф'],
            [[S::COLLECTING], false, 'Чекаємо пропозицій від дизайнерів'],
            [[S::COLLECTING, 1], true, 'Ваш хід: оберіть дизайнера · 1 пропозиція'],
            [[S::COLLECTING, 3], true, 'Ваш хід: оберіть дизайнера · 3 пропозиції'],
            [[S::COLLECTING, 5], true, 'Ваш хід: оберіть дизайнера · 5 пропозицій'],
            [[S::COLLECTING, 11], true, 'Ваш хід: оберіть дизайнера · 11 пропозицій'],
            [[S::COLLECTING, 22], true, 'Ваш хід: оберіть дизайнера · 22 пропозиції'],
            [[S::WAITING_DESIGNER], false, 'Чекаємо відповіді дизайнера'],
            [[S::IN_WORK], false, 'Дизайнер працює над макетами'],
            [[S::IN_WORK, 0, 2], true, 'Ваш хід: перевірте макети і натисніть «Купую»'],
            [[S::AWAITING_INVOICE], false, 'Чекаємо оплату рахунку — макети відкриються після неї'],
            [[S::PURCHASED], false, 'Готово · макети у вашій хмарі wolf.ua'],
            [[S::REWORK], false, 'Дизайнер працює над змінами'],
            [[S::CANCELLED], false, 'Скасовано'],
        ];
        foreach ($cases as [$args, $mine, $text]) {
            $this->assertSame(['mine' => $mine, 'text' => $text], S::turn(...$args), json_encode($args));
        }
    }
}
