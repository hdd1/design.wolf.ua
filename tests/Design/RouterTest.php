<?php

use Design\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function test_routes(): void
    {
        $this->assertSame('home', Router::match('GET', '/')['page']);
        $this->assertSame('brief-products', Router::match('GET', '/brief/')['page']);
        $this->assertSame(['page' => 'brief-form', 'params' => ['product' => '12']], Router::match('GET', '/brief/12/'));
        $this->assertSame('brief-create', Router::match('POST', '/brief')['page']);
        $this->assertSame('orders', Router::match('GET', '/orders/')['page']);
        $this->assertSame(['page' => 'order', 'params' => ['id' => '345']], Router::match('GET', '/orders/345/'));
        $this->assertSame('order-update', Router::match('POST', '/orders/345')['page']);
    }

    public function test_unknown_and_wrong_method_are_null(): void
    {
        $this->assertNull(Router::match('GET', '/brief/abc/'));
        $this->assertNull(Router::match('POST', '/orders/'));
        $this->assertNull(Router::match('GET', '/orders/1234567890/'));
    }
}
