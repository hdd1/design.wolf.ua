<?php

use Design\Brief;
use Design\BriefException;
use PHPUnit\Framework\TestCase;

final class BriefTest extends TestCase
{
    private const Q = [['q' => 'Розмір', 'hint' => ''], ['q' => 'Текст', 'hint' => '']];

    public function test_snapshot_pairs_and_trims(): void
    {
        $this->assertSame([['q' => 'Розмір', 'a' => 'А5'], ['q' => 'Текст', 'a' => '']],
            Brief::snapshot(self::Q, [' А5 ', '']));
    }

    public function test_rejects_stale_empty_and_too_long(): void
    {
        $cases = [
            'Форма застаріла, оновіть сторінку' => ['А5'],
            'Заповніть хоча б одну відповідь' => ['', '  '],
            'Відповідь занадто довга' => [str_repeat('я', 5001), ''],
        ];
        foreach ($cases as $message => $answers) {
            try {
                Brief::snapshot(self::Q, $answers);
                $this->fail("Не відхилено: $message");
            } catch (BriefException $e) {
                $this->assertSame($message, $e->getMessage());
            }
        }
    }

    public function test_exactly_5000_chars_is_accepted(): void
    {
        $this->assertSame(5000, mb_strlen(Brief::snapshot(self::Q, [str_repeat('я', 5000), ''])[0]['a']));
    }
}
