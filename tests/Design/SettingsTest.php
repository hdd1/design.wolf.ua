<?php

use Design\Settings;
use Design\Sql;
use PHPUnit\Framework\TestCase;

/** @group db */
final class SettingsTest extends TestCase
{
    private const KEY = 'test_settings_999999701';

    protected function setUp(): void
    {
        Sql::exec('DELETE FROM design_settings WHERE `key` = ?', [self::KEY]);
        Settings::reset();
    }

    protected function tearDown(): void
    {
        Sql::exec('DELETE FROM design_settings WHERE `key` = ?', [self::KEY]);
        Settings::reset();
    }

    public function test_get_returns_db_value_and_default(): void
    {
        $this->assertSame('fallback', Settings::get(self::KEY, 'fallback'));
        $this->assertSame('', Settings::get(self::KEY));

        Sql::exec('INSERT INTO design_settings (`key`, `value`) VALUES (?, ?)', [self::KEY, 'abc']);
        Settings::reset();
        $this->assertSame('abc', Settings::get(self::KEY, 'fallback'));
    }

    public function test_cached_within_request(): void
    {
        Sql::exec('INSERT INTO design_settings (`key`, `value`) VALUES (?, ?)', [self::KEY, 'one']);
        Settings::reset();
        $this->assertSame('one', Settings::get(self::KEY));
        Sql::exec('UPDATE design_settings SET `value` = ? WHERE `key` = ?', ['two', self::KEY]);
        $this->assertSame('one', Settings::get(self::KEY), 'кеш на запит');
        Settings::reset();
        $this->assertSame('two', Settings::get(self::KEY));
    }

    public function test_typed_getters_from_db(): void
    {
        // у транзакції з відкатом: справжні налаштування dev не чіпаємо
        Sql::pdo()->beginTransaction();
        try {
            foreach (['markup_percent' => '17.5', 'price_round' => '5', 'no_offers_hours' => '12', 'applications_notify_user_id' => '7'] as $k => $v) {
                Sql::exec('INSERT INTO design_settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)', [$k, $v]);
            }
            Settings::reset();
            $this->assertSame(17.5, Settings::markupPercent());
            $this->assertSame(5, Settings::priceRound());
            $this->assertSame(12, Settings::noOffersHours());
            $this->assertSame(7, Settings::notifyUserId());
        } finally {
            Sql::pdo()->rollBack();
            Settings::reset();
        }
    }

    public function test_defaults_when_missing_or_invalid(): void
    {
        Sql::pdo()->beginTransaction();
        try {
            Sql::exec('DELETE FROM design_settings WHERE `key` IN (\'markup_percent\', \'price_round\', \'no_offers_hours\', \'applications_notify_user_id\')');
            Settings::reset();
            $this->assertSame(20.0, Settings::markupPercent());
            $this->assertSame(10, Settings::priceRound());
            $this->assertSame(24, Settings::noOffersHours());
            $this->assertSame(0, Settings::notifyUserId());

            Sql::exec('INSERT INTO design_settings (`key`, `value`) VALUES (\'price_round\', \'0\')');
            Settings::reset();
            $this->assertSame(1, Settings::priceRound(), 'не менше 1');
        } finally {
            Sql::pdo()->rollBack();
            Settings::reset();
        }
    }

    public function test_stage4_limits_from_db(): void
    {
        Sql::pdo()->beginTransaction();
        try {
            foreach (['preview_max_pages' => '12', 'preview_max_mb' => '7', 'layout_max_mb' => '300', 'layout_max_files' => '5', 'layout_max_total_mb' => '900'] as $k => $v) {
                Sql::exec('INSERT INTO design_settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)', [$k, $v]);
            }
            Settings::reset();
            $this->assertSame(12, Settings::previewMaxPages());
            $this->assertSame(7 * 1048576, Settings::previewMaxBytes());
            $this->assertSame(300 * 1048576, Settings::layoutMaxBytes());
            $this->assertSame(5, Settings::layoutMaxFiles());
            $this->assertSame(900 * 1048576, Settings::layoutMaxTotalBytes());
        } finally {
            Sql::pdo()->rollBack();
            Settings::reset();
        }
    }

    public function test_stage4_limit_defaults_when_missing_or_invalid(): void
    {
        Sql::pdo()->beginTransaction();
        try {
            Sql::exec('DELETE FROM design_settings WHERE `key` IN (\'preview_max_pages\', \'preview_max_mb\', \'layout_max_mb\', \'layout_max_files\', \'layout_max_total_mb\')');
            Settings::reset();
            $this->assertSame(30, Settings::previewMaxPages());
            $this->assertSame(50 * 1048576, Settings::previewMaxBytes());
            $this->assertSame(1024 * 1048576, Settings::layoutMaxBytes());
            $this->assertSame(20, Settings::layoutMaxFiles());
            $this->assertSame(5120 * 1048576, Settings::layoutMaxTotalBytes());

            Sql::exec('INSERT INTO design_settings (`key`, `value`) VALUES (\'layout_max_files\', \'0\'), (\'preview_max_mb\', \'abc\')');
            Settings::reset();
            $this->assertSame(20, Settings::layoutMaxFiles(), '0 → за замовчуванням');
            $this->assertSame(50 * 1048576, Settings::previewMaxBytes(), 'не число → за замовчуванням');
        } finally {
            Sql::pdo()->rollBack();
            Settings::reset();
        }
    }
}
