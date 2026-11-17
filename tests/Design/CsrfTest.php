<?php

use Design\Csrf;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    public function test_token_is_per_customer_and_accepts_previous_hour(): void
    {
        $this->assertTrue(Csrf::check(7, Csrf::token(7)));
        $this->assertTrue(Csrf::check(7, Csrf::token(7, time() - 3600)));
        $this->assertFalse(Csrf::check(8, Csrf::token(7)));
        $this->assertFalse(Csrf::check(7, Csrf::token(7, time() - 7200)));
        $this->assertFalse(Csrf::check(7, ''));
    }
}
