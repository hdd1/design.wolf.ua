<?php

use Design\Http;
use PHPUnit\Framework\TestCase;

final class HttpTest extends TestCase
{
    public function test_str_returns_strings_as_is(): void
    {
        $this->assertSame(' Олег ', Http::str(['name' => ' Олег '], 'name'));
        $this->assertSame('', Http::str(['name' => ''], 'name'));
    }

    /** name[]=x, відсутнє поле, null чи число з JSON — порожній рядок, без warning і без «Array» */
    public function test_str_non_string_is_empty(): void
    {
        $src = ['a' => ['x'], 'b' => [], 'n' => null, 'i' => 5];
        foreach (['a', 'b', 'n', 'i', 'missing'] as $key) $this->assertSame('', Http::str($src, $key), $key);
    }

    /** respond: спершу відповідь, потім робота після неї; її збій — лише в журнал, відповідь та сама й виняток не виходить назовні */
    public function test_respond_sends_response_before_after_work(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'http-log');
        $oldLog = ini_set('error_log', $log);
        $events = [];
        Http::$output = function (array $data, int $code) use (&$events): void { $events[] = ['sent', $code, $data]; };
        try {
            Http::respond(['done' => true], 200, function () use (&$events): void { $events[] = ['after']; });
            Http::respond(['error' => 'x', 'final' => true], 500, function () use (&$events): void {
                $events[] = ['after-throws'];
                throw new RuntimeException('api2 недоступний');
            });
            Http::respond(['done' => false], 200);
        } finally {
            Http::$output = null;
            ini_set('error_log', (string)$oldLog);
        }
        $this->assertSame([
            ['sent', 200, ['done' => true]], ['after'],
            ['sent', 500, ['error' => 'x', 'final' => true]], ['after-throws'],
            ['sent', 200, ['done' => false]],
        ], $events);
        $this->assertStringContainsString('робота після відповіді', (string)file_get_contents($log));
        $this->assertStringContainsString('api2 недоступний', (string)file_get_contents($log));
        unlink($log);
    }
}
