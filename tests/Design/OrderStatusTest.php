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
            // Етап 5: «Купую» працює — макети є, отже хід клієнта
            [[S::IN_WORK, 0, 2], true, 'Ваш хід: перевірте макети і натисніть «Купую»'],
            [[S::AWAITING_INVOICE], false, 'Чекаємо оплату рахунку — макети відкриються після неї'],
            [[S::PURCHASED], false, 'Макети ваші'],
            [[S::PURCHASED, 0, 3], false, 'Макети ваші'],
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

    /** «Куплено»: дизайнеру — «Готово»; клієнт написав після купівлі (напр. просить правки) — його хід, як і «В роботі» */
    public function test_designer_turn_purchased(): void
    {
        $ready = ['mine' => false, 'text' => 'Готово'];
        $this->assertSame($ready, S::designerTurn(10, 12, 2, 1, S::PURCHASED));
        $this->assertSame($ready, S::designerTurn(0, 0, 0, 1, S::PURCHASED));
        $this->assertSame(['mine' => true, 'text' => 'Ваш хід: клієнт написав · відповідайте'], S::designerTurn(13, 12, 2, 1, S::PURCHASED));
        // Тексти етапу 4 «Купівля — незабаром» ніде не лишилось
        foreach ([S::IN_WORK, S::PURCHASED, S::REWORK] as $s) {
            foreach ([0, 1, 5] as $n) $this->assertStringNotContainsString('незабаром', S::turn($s, 0, $n)['text']);
        }
    }

    /**
     * Доопрацювання: пропозиція без відповіді — «Чекає вашої відповіді» (хід клієнта) / «Чекаємо відповіді клієнта»;
     * «Доопрацювання» — як «В роботі» (layouts — нові, ще не куплені макети)
     */
    public function test_rework_turns(): void
    {
        $this->assertSame(['mine' => true, 'text' => 'Чекає вашої відповіді'], S::turn(S::PURCHASED, 0, 0, true));
        $this->assertSame(['mine' => false, 'text' => 'Макети ваші'], S::turn(S::PURCHASED, 0, 0, false));
        $this->assertSame(['mine' => true, 'text' => 'Ваш хід: перевірте макети і натисніть «Купую»'], S::turn(S::REWORK, 0, 1));
        $this->assertSame(['mine' => false, 'text' => 'Дизайнер працює над змінами'], S::turn(S::REWORK, 0, 0));
        $this->assertSame(['mine' => false, 'text' => 'Дизайнер працює над змінами'], S::turn(S::REWORK, 0, 0, true), 'pending — лише для «Куплено»');

        $this->assertSame(['mine' => false, 'text' => 'Чекаємо відповіді клієнта'], S::designerTurn(10, 12, 2, 0, S::PURCHASED, true));
        $this->assertSame(['mine' => true, 'text' => 'Ваш хід: клієнт написав · відповідайте'], S::designerTurn(13, 12, 2, 0, S::PURCHASED, true));
        $this->assertSame(['mine' => false, 'text' => 'Готово'], S::designerTurn(10, 12, 2, 0, S::PURCHASED, false));
        $this->assertSame(['mine' => false, 'text' => "Коли клієнт погодить прев'ю — завантажте макети"], S::designerTurn(10, 12, 2, 0, S::REWORK));
        $this->assertSame(['mine' => false, 'text' => 'Макети завантажено · чекаємо, поки клієнт натисне «Купую»'], S::designerTurn(10, 12, 2, 1, S::REWORK));
        $this->assertSame(['mine' => true, 'text' => 'Ваш хід: клієнт написав · відповідайте'], S::designerTurn(13, 12, 2, 1, S::REWORK));
    }
}
