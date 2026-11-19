<?php

use Design\Config;
use Design\Notify;
use PHPUnit\Framework\TestCase;

final class NotifyTest extends TestCase
{
    private const NO_MARKER = '/немає-маркера-dev-сервера';

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

    /** CLI на dev: на час виклику HTTP_HOST = dev.design.wolf.ua (CalcApiGlobal іде в dev api2), потім знову нема */
    public function test_cli_on_dev_fakes_host_only_during_call(): void
    {
        $hadHost = array_key_exists('HTTP_HOST', $_SERVER);
        $oldHost = $_SERVER['HTTP_HOST'] ?? null;
        unset($_SERVER['HTTP_HOST']);
        Config::$devMarker = __DIR__;
        try {
            $seen = 'не викликано';
            Notify::$transport = function (array $p) use (&$seen) { $seen = $_SERVER['HTTP_HOST'] ?? null; return ['error' => false]; };
            Notify::send(['customer_id' => 55, 'msg' => 'x', 'msgSMS' => 'x']);
            $this->assertSame('dev.design.wolf.ua', $seen);
            $this->assertArrayNotHasKey('HTTP_HOST', $_SERVER);

            // збій у transport теж не лишає підміненого хоста
            Notify::$transport = function (array $p) { throw new \RuntimeException('boom'); };
            Notify::send(['customer_id' => 55, 'msg' => 'x', 'msgSMS' => 'x']);
            $this->assertArrayNotHasKey('HTTP_HOST', $_SERVER);

            // на проді (маркера немає) хост не підставляється
            Config::$devMarker = self::NO_MARKER;
            Notify::$transport = function (array $p) use (&$seen) { $seen = $_SERVER['HTTP_HOST'] ?? null; return ['error' => false]; };
            Notify::send(['customer_id' => 55, 'msg' => 'x', 'msgSMS' => 'x']);
            $this->assertNull($seen);
        } finally {
            Config::$devMarker = self::NO_MARKER;
            if ($hadHost) $_SERVER['HTTP_HOST'] = $oldHost; else unset($_SERVER['HTTP_HOST']);
        }
    }

    public function test_web_host_untouched(): void
    {
        $hadHost = array_key_exists('HTTP_HOST', $_SERVER);
        $oldHost = $_SERVER['HTTP_HOST'] ?? null;
        $_SERVER['HTTP_HOST'] = 'design.wolf.ua';
        try {
            $seen = null;
            Notify::$transport = function (array $p) use (&$seen) { $seen = $_SERVER['HTTP_HOST']; return ['error' => false]; };
            Notify::send(['customer_id' => 55, 'msg' => 'x', 'msgSMS' => 'x']);
            $this->assertSame('design.wolf.ua', $seen);
            $this->assertSame('design.wolf.ua', $_SERVER['HTTP_HOST']);
        } finally {
            if ($hadHost) $_SERVER['HTTP_HOST'] = $oldHost; else unset($_SERVER['HTTP_HOST']);
        }
    }
}
