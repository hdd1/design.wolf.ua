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

    public function test_stage3_tables(): void
    {
        foreach (['design_offers', 'design_messages'] as $t) {
            $this->assertStringStartsWith('utf8mb4', (string)Sql::value(
                'SELECT table_collation FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$t]), $t);
        }
        $this->assertSame('1', (string)Sql::value("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'design_designers' AND column_name = 'feed_seen_at'"));
        $this->assertSame('20', Sql::value("SELECT value FROM design_settings WHERE `key` = 'markup_percent'"));
        $this->assertSame('10', Sql::value("SELECT value FROM design_settings WHERE `key` = 'price_round'"));
        // UNIQUE (order_id, designer_id) — одна пара замовлення+дизайнер = один рядок
        $this->assertSame('2', (string)Sql::value("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'design_offers' AND index_name = 'uq_order_designer' AND non_unique = 0"));
        foreach (['client_seen_at', 'designer_seen_at', 'client_notified_at', 'designer_notified_at'] as $c) {
            $this->assertSame('1', (string)Sql::value("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'design_offers' AND column_name = ?", [$c]), $c);
        }
    }

    public function test_stage4_tables(): void
    {
        foreach (['design_previews', 'design_files'] as $t) {
            $this->assertStringStartsWith('utf8mb4', (string)Sql::value(
                'SELECT table_collation FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$t]), $t);
        }
        $col = fn(string $t, string $c) => (string)Sql::value('SELECT column_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?', [$t, $c]);
        $this->assertSame(Sql::value("SELECT column_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'design_orders' AND column_name = 'id'"), $col('design_previews', 'order_id'));
        $this->assertSame(Sql::value("SELECT column_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'design_offers' AND column_name = 'id'"), $col('design_previews', 'offer_id'));
        $this->assertSame('varchar(8)', $col('design_previews', 'source_type'));
        $this->assertSame('varchar(120)', $col('design_files', 'label'));
        $this->assertSame('varchar(255)', $col('design_files', 'original_name'));
        $this->assertSame('varchar(64)', $col('design_files', 'file'));
        $this->assertStringStartsWith('bigint', $col('design_files', 'size'));
        $this->assertSame('char(32)', $col('design_files', 'md5'));
        foreach (['based_on_id', 'message_id', 'hidden_at'] as $c) {
            $this->assertSame('YES', Sql::value("SELECT is_nullable FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'design_previews' AND column_name = ?", [$c]), $c);
        }
        foreach (['purchase_id', 'cloud_id'] as $c) {
            $this->assertSame('YES', Sql::value("SELECT is_nullable FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'design_files' AND column_name = ?", [$c]), $c);
        }
        // UNIQUE (order_id, num) — одна версія vN на замовлення
        $this->assertSame('2', (string)Sql::value("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'design_previews' AND index_name = 'uq_order_num' AND non_unique = 0"));
        $this->assertSame('2', (string)Sql::value("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'design_files' AND index_name = 'idx_order_status'"));
        foreach (['preview_max_pages' => '30', 'preview_max_mb' => '50', 'layout_max_mb' => '1024', 'layout_max_files' => '20', 'layout_max_total_mb' => '5120'] as $k => $v) {
            $this->assertSame($v, Sql::value('SELECT value FROM design_settings WHERE `key` = ?', [$k]), $k);
        }
    }
}
