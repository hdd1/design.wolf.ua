<?php

use Design\Sql;
use PHPUnit\Framework\TestCase;

/** @group db */
final class SchemaTest extends TestCase
{
    public function test_tables_exist_with_utf8mb4(): void
    {
        foreach (['design_settings', 'design_products', 'design_orders'] as $t) {
            $this->assertStringStartsWith('utf8mb4', (string)Sql::value(
                'SELECT table_collation FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$t]), $t);
        }
    }

    public function test_other_product_is_last_and_settings_seeded(): void
    {
        $last = Sql::one('SELECT name FROM design_products WHERE shw = 1 ORDER BY pos DESC, id DESC LIMIT 1');
        $this->assertSame('Інше', $last['name'] ?? null);
        $this->assertSame('24', Sql::value("SELECT value FROM design_settings WHERE `key` = 'no_offers_hours'"));
    }

    public function test_stage2_tables_and_setting(): void
    {
        foreach (['design_designers', 'design_portfolio'] as $t) {
            $this->assertStringStartsWith('utf8mb4', (string)Sql::value(
                'SELECT table_collation FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$t]), $t);
        }
        $this->assertSame('1', Sql::value("SELECT value FROM design_settings WHERE `key` = 'applications_notify_user_id'"));
        $this->assertSame('5.00', Sql::value("SELECT column_default FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'design_designers' AND column_name = 'rating'"));
    }
}
