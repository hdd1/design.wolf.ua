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

    /** «Чий хід» для дизайнера: перше правило, що підходить (спека, розділ «Чий хід» для дизайнера) */
    public function test_designer_turn_rules(): void
    {
        $wrote = ['mine' => true, 'text' => 'Ваш хід: клієнт написав · відповідайте'];
        $first = ['mine' => true, 'text' => "Ваш хід: покажіть перше прев'ю"];
        $wait = ['mine' => false, 'text' => "Коли клієнт погодить прев'ю — завантажте макети"];
        $done = ['mine' => false, 'text' => 'Макети завантажено · чекаємо, поки клієнт натисне «Купую»'];
        $cases = [
            // lastClient, lastDesigner, previews, layouts
            [[12, 10, 0, 0], $wrote],
            [[12, 10, 3, 2], $wrote],          // 1-ше правило важливіше за макети
            [[12, 0, 0, 0], $wrote],           // дизайнер ще нічого не писав
            [[0, 0, 0, 0], $first],
            [[10, 12, 0, 0], $first],
            [[10, 10, 0, 0], $first],          // той самий id не «новіший»
            [[10, 12, 0, 2], $first],          // макети без прев'ю — однаково спершу прев'ю
            [[10, 12, 1, 0], $wait],
            [[0, 12, 4, 0], $wait],
            [[10, 12, 1, 1], $done],
            [[0, 5, 2, 20], $done],
        ];
        foreach ($cases as [$args, $want]) {
            $this->assertSame($want, S::designerTurn(...$args), json_encode($args));
        }
    }
}
