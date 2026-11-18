<?php

use Design\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    private const NO_MARKER = '/немає-маркера-dev-сервера';

    private bool $hadHost;
    private mixed $oldHost;

    protected function setUp(): void
    {
        $this->hadHost = array_key_exists('HTTP_HOST', $_SERVER);
        $this->oldHost = $_SERVER['HTTP_HOST'] ?? null;
    }

    protected function tearDown(): void
    {
        Config::$devMarker = self::NO_MARKER;
        if ($this->hadHost) $_SERVER['HTTP_HOST'] = $this->oldHost; else unset($_SERVER['HTTP_HOST']);
    }

    public function test_cli_dev_marker(): void
    {
        unset($_SERVER['HTTP_HOST']);
        Config::$devMarker = __DIR__;                       // каталог існує — «dev-сервер»
        $this->assertTrue(Config::isDev());
        Config::$devMarker = self::NO_MARKER;
        $this->assertFalse(Config::isDev());                // прод: маркера немає
    }

    public function test_web_ignores_marker(): void
    {
        Config::$devMarker = __DIR__;
        $_SERVER['HTTP_HOST'] = 'design.wolf.ua';
        $this->assertFalse(Config::isDev());
        $_SERVER['HTTP_HOST'] = 'dev.design.wolf.ua';
        Config::$devMarker = self::NO_MARKER;
        $this->assertTrue(Config::isDev());
    }
}
