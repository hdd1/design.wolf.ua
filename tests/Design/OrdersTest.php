<?php

use Design\BriefException;
use Design\Orders;
use Design\OrderStatus;
use Design\Products;
use Design\Sql;
use PHPUnit\Framework\TestCase;

/** @group db */
final class OrdersTest extends TestCase
{
    private const ME = 999999001;
    private const OTHER = 999999002;

    public static function tearDownAfterClass(): void
    {
        Sql::exec('DELETE FROM design_orders WHERE customer_id IN (?, ?)', [self::ME, self::OTHER]);
    }

    private function product(): int { return Products::visible()[0]['id']; }

    private function answers(string $first): array
    {
        return array_merge([$first], array_fill(0, count(Products::get($this->product())['questions']) - 1, ''));
    }

    public function test_create_publish_and_list(): void
    {
        $id = Orders::create(self::ME, $this->product(), $this->answers('А5'));
        $this->assertSame(OrderStatus::DRAFT, Orders::get($id, self::ME)['status']);
        Orders::publish($id, self::ME);
        $this->assertSame(OrderStatus::COLLECTING, Orders::get($id, self::ME)['status']);
        $this->assertContains($id, array_column(Orders::forCustomer(self::ME), 'id'));
    }

    public function test_get_foreign_order_is_null(): void   // Review Focus 1
    {
        $id = Orders::create(self::ME, $this->product(), $this->answers('А5'));
        $this->assertNull(Orders::get($id, self::OTHER));
        $this->expectException(BriefException::class);
        Orders::publish($id, self::OTHER);
    }

    public function test_answers_keep_apostrophe_and_html(): void   // Review Focus 2
    {
        $id = Orders::create(self::ME, $this->product(), $this->answers("м'ята <b>жирно</b>"));
        $this->assertSame("м'ята <b>жирно</b>", Orders::get($id, self::ME)['answers'][0]['a']);
    }

    public function test_snapshot_survives_question_change(): void   // Review Focus 3
    {
        $pid = Sql::insert('design_products', ['name' => 'Тест знімок', 'brief' => '[{"q":"Старе питання"}]', 'pos' => 999, 'shw' => 1]);
        try {
            $id = Orders::create(self::ME, $pid, ['відповідь']);
            Sql::exec('UPDATE design_products SET brief = ? WHERE id = ?', ['[{"q":"Нове питання"}]', $pid]);
            Orders::updateDraft($id, self::ME, ['інша відповідь']);
            $this->assertSame([['q' => 'Старе питання', 'a' => 'інша відповідь']], Orders::get($id, self::ME)['answers']);
        } finally {
            Sql::exec('DELETE FROM design_products WHERE id = ?', [$pid]);
        }
    }

    public function test_publish_twice_is_noop(): void   // Review Focus 4
    {
        $id = Orders::create(self::ME, $this->product(), $this->answers('А5'));
        Orders::publish($id, self::ME);
        $first = Orders::get($id, self::ME)['published_at'];
        sleep(1);
        Orders::publish($id, self::ME);
        $this->assertSame($first, Orders::get($id, self::ME)['published_at']);
    }

    public function test_published_order_cannot_be_edited(): void
    {
        $id = Orders::create(self::ME, $this->product(), $this->answers('А5'));
        Orders::publish($id, self::ME);
        $this->expectException(BriefException::class);
        Orders::updateDraft($id, self::ME, $this->answers('А4'));
    }

    public function test_hidden_product_is_rejected(): void
    {
        $pid = Sql::insert('design_products', ['name' => 'Тест прихований', 'brief' => '[{"q":"Питання"}]', 'pos' => 999, 'shw' => 0]);
        try {
            $this->expectExceptionMessage('Продукт недоступний');
            Orders::create(self::ME, $pid, ['відповідь']);
        } finally {
            Sql::exec('DELETE FROM design_products WHERE id = ?', [$pid]);
        }
    }

    public function test_title_saved_and_defaults_to_product_name(): void
    {
        $named = Orders::create(self::ME, $this->product(), $this->answers('А5'), "  Візитка для кав'ярні «Зерно» ");
        $this->assertSame("Візитка для кав'ярні «Зерно»", Orders::get($named, self::ME)['title']);
        $plain = Orders::create(self::ME, $this->product(), $this->answers('А5'));
        $row = Orders::get($plain, self::ME);
        $this->assertSame($row['product_name'], $row['title']);
        $this->assertSame($this->product(), $row['product_id']);
        $this->assertSame(1, $row['mode']);
        Orders::updateDraft($plain, self::ME, $this->answers('А4'), 'Нова назва');
        $titles = array_column(Orders::forCustomer(self::ME), 'title', 'id');
        $this->assertSame('Нова назва', $titles[$plain]);
    }

    public function test_title_too_long_is_rejected(): void
    {
        $this->expectExceptionMessage('Назва занадто довга');
        Orders::create(self::ME, $this->product(), $this->answers('А5'), str_repeat('я', 256));
    }

    public function test_status_labels(): void
    {
        $this->assertSame('Збір пропозицій', OrderStatus::label(OrderStatus::COLLECTING));
        $this->assertSame('Скасовано', OrderStatus::label(OrderStatus::CANCELLED));
    }
}
