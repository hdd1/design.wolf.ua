<?php

use Design\Products;
use Design\Sql;
use PHPUnit\Framework\TestCase;

/** @group db */
final class ProductsTest extends TestCase
{
    private static int $hidden;

    public static function setUpBeforeClass(): void
    {
        self::$hidden = Sql::insert('design_products', ['name' => 'Тест прихований', 'brief' => '[]', 'pos' => 1, 'shw' => 0]);
    }

    public static function tearDownAfterClass(): void
    {
        Sql::exec('DELETE FROM design_products WHERE id = ?', [self::$hidden]);
    }

    public function test_visible_ends_with_other(): void
    {
        $names = array_column(Products::visible(), 'name');
        $this->assertSame('Інше', end($names));
        $this->assertNotContains('Тест прихований', $names);
    }

    public function test_hidden_product_is_null(): void   // Review Focus 5
    {
        $this->assertNull(Products::get(self::$hidden));
        $this->assertNull(Products::get(999999999));
    }

    public function test_get_returns_int_id_and_questions(): void
    {
        $first = Products::visible()[0];
        $p = Products::get($first['id']);
        $this->assertSame($first['id'], $p['id']);
        $this->assertIsInt($p['id']);
        $this->assertNotEmpty($p['questions']);
        $this->assertArrayHasKey('hint', $p['questions'][0]);
    }

    public function test_questions_parsing(): void
    {
        $this->assertSame([['q' => 'Розмір', 'hint' => '90×50']],
            Products::questions('[{"q":"Розмір","hint":"90×50"},{"hint":"без питання"}]'));
        $this->assertSame([['q' => 'Текст', 'hint' => '']], Products::questions('[{"q":"Текст"}]'));
        $this->assertSame([], Products::questions('не json'));
        $this->assertSame([], Products::questions(null));
    }
}
