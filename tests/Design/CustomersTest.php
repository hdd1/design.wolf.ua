<?php

use Design\Customers;
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
}
