<?php

use Design\Customers;
use Design\Sql;
use PHPUnit\Framework\TestCase;

/** @group db */
final class CustomersTest extends TestCase
{
    public function test_header_of_customer_29(): void
    {
        $h = Customers::header(29);
        $this->assertNotSame('', $h['name']);
        $this->assertMatchesRegularExpression('/^\p{Lu}{1,2}$/u', $h['initials']);
        $this->assertIsInt($h['balance']);
        $this->assertStringStartsWith(mb_substr($h['name'], 0, 1), $h['short']);
    }

    public function test_header_of_unknown_is_null(): void
    {
        $this->assertNull(Customers::header(999999999));
    }

    public function test_is_sub(): void
    {
        $this->assertFalse(Customers::isSub(999999999), 'немає такого клієнта — не субакаунт');
        $orig = Sql::value('SELECT parent_id FROM customers WHERE id = 29');
        try {
            Sql::exec('UPDATE customers SET parent_id = 25 WHERE id = 29');
            $this->assertTrue(Customers::isSub(29));
            Sql::exec('UPDATE customers SET parent_id = 0 WHERE id = 29');
            $this->assertFalse(Customers::isSub(29));
        } finally {
            Sql::exec('UPDATE customers SET parent_id = ? WHERE id = 29', [$orig]);
        }
    }
}
