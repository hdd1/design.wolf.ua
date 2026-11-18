<?php

use Design\Notify;
use PHPUnit\Framework\TestCase;

final class NotifyTest extends TestCase
{
    private string $log;
    private string|false $oldLog;
    private string|false $oldEnv;

    protected function setUp(): void
    {
        $this->log = tempnam(sys_get_temp_dir(), 'notify');
        $this->oldLog = ini_set('error_log', $this->log);
        $this->oldEnv = getenv('DESIGN_NOTIFY');
        putenv('DESIGN_NOTIFY=on');   // перевіряємо бойовий шлях, але лише через підміну transport — у мережу не ходимо
        Notify::$sent = [];
    }

    protected function tearDown(): void
    {
        Notify::$transport = null;
        Notify::$sent = [];
        putenv($this->oldEnv === false ? 'DESIGN_NOTIFY' : 'DESIGN_NOTIFY=' . $this->oldEnv);
        ini_set('error_log', $this->oldLog === false ? '' : $this->oldLog);
        @unlink($this->log);
    }

    /** @dataProvider failures */
    public function test_failure_is_logged_not_thrown(mixed $result, string $expectInLog): void
    {
        Notify::$transport = fn(array $p) => $result;
        Notify::send(['customer_id' => 55, 'msg' => 'x', 'msgSMS' => 'x']);
        $log = (string)file_get_contents($this->log);
        $this->assertStringContainsString('Design\Notify', $log);
        $this->assertStringContainsString('customer_id=55', $log);
        $this->assertStringContainsString($expectInLog, $log);
    }

    public static function failures(): array
    {
        return [
            'curl false' => [false, 'збій звʼязку'],
            'не JSON' => ['<html>502 Bad Gateway</html>', '502 Bad Gateway'],
            'помилка api2' => [['error' => true, 'message' => 'Немає такого клієнта'], 'Немає такого клієнта'],
        ];
    }

    public function test_exception_in_transport_is_logged_not_thrown(): void
    {
        Notify::$transport = function (array $p) { throw new \RuntimeException('boom'); };
        Notify::send(['user_id' => 1, 'msg' => 'x', 'msgSMS' => 'x']);
        $log = (string)file_get_contents($this->log);
        $this->assertStringContainsString('user_id=1', $log);
        $this->assertStringContainsString('boom', $log);
    }

    public function test_success_writes_nothing_and_passes_params(): void
    {
        $got = null;
        Notify::$transport = function (array $p) use (&$got) { $got = $p; return ['error' => false, 'id' => 7]; };
        Notify::send(['customer_id' => 55, 'msg' => 'x', 'msgSMS' => 'x']);
        $this->assertSame(['customer_id' => 55, 'msg' => 'x', 'msgSMS' => 'x'], $got);
        $this->assertSame('', (string)file_get_contents($this->log));
        $this->assertSame([], Notify::$sent);
    }

    public function test_off_mode_never_calls_transport(): void
    {
        putenv('DESIGN_NOTIFY=off');
        $called = false;
        Notify::$transport = function (array $p) use (&$called) { $called = true; return []; };
        Notify::send(['customer_id' => 55, 'msg' => 'x', 'msgSMS' => 'x']);
        $this->assertFalse($called);
        $this->assertCount(1, Notify::$sent);
    }
}
