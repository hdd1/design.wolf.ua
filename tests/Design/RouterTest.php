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
        $this->assertSame(['page' => 'order-mode', 'params' => ['id' => '345']], Router::match('GET', '/orders/345/mode/'));
    }

    public function test_unknown_and_wrong_method_are_null(): void
    {
        $this->assertNull(Router::match('GET', '/brief/abc/'));
        $this->assertNull(Router::match('POST', '/orders/'));
        $this->assertNull(Router::match('GET', '/orders/1234567890/'));
    }

    public function test_media_route(): void
    {
        $this->assertSame(['page' => 'media', 'params' => ['designer' => '12', 'name' => '345-card']], Router::match('GET', '/media/12/345-card'));
        $this->assertSame(['page' => 'media', 'params' => ['designer' => '12', 'name' => '345-view']], Router::match('GET', '/media/12/345-view'));
        $this->assertSame(['page' => 'media', 'params' => ['designer' => '12', 'name' => 'avatar-0a1b2c3d']], Router::match('GET', '/media/12/avatar-0a1b2c3d'));
        $this->assertNull(Router::match('GET', '/media/1/2-orig'));
        $this->assertNull(Router::match('GET', '/media/1/2-card.webp'));
        $this->assertNull(Router::match('GET', '/media/1/avatar-0A1B2C3D'));
        $this->assertNull(Router::match('GET', '/media/1/avatar-0a1b2c3'));
        $this->assertNull(Router::match('GET', '/media/1/2-card/'));
        $this->assertNull(Router::match('POST', '/media/1/2-card'));
        $this->assertNull(Router::withSlash('/media/1/2-card'));
    }

    public function test_designer_routes(): void
    {
        $this->assertSame(['page' => 'designer', 'params' => []], Router::match('GET', '/designer/'));
        $this->assertSame('designer-save', Router::match('POST', '/designer')['page']);
        $this->assertSame('designer-work-add', Router::match('POST', '/designer/works')['page']);
        $this->assertSame(['page' => 'designer-works-order', 'params' => []], Router::match('POST', '/designer/works/order'));
        $this->assertSame(['page' => 'designer-work', 'params' => ['id' => '345']], Router::match('POST', '/designer/works/345'));
        $this->assertSame('designer-photo', Router::match('POST', '/designer/photo')['page']);
        $this->assertNull(Router::match('GET', '/designer/works'));
        $this->assertNull(Router::match('POST', '/designer/works/abc'));
        $this->assertNull(Router::match('POST', '/designer/'));
        $this->assertSame('/designer/', Router::withSlash('/designer'));
    }

    public function test_catalog_routes(): void
    {
        $this->assertSame(['page' => 'designers', 'params' => []], Router::match('GET', '/designers/'));
        $this->assertSame(['page' => 'designer-public', 'params' => ['id' => '345']], Router::match('GET', '/designers/345/'));
        $this->assertNull(Router::match('GET', '/designers/abc/'));
        $this->assertNull(Router::match('GET', '/designers/1234567890/'));
        $this->assertNull(Router::match('POST', '/designers/'));
        $this->assertNull(Router::match('POST', '/designers/345/'));
        $this->assertSame('/designers/', Router::withSlash('/designers'));
        $this->assertSame('/designers/345/', Router::withSlash('/designers/345'));
        $this->assertNull(Router::withSlash('/designers/abc'));
    }
}
