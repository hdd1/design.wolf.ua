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

    /** Картинки портфоліо віддає i.fastcdn: власного маршруту /media/… немає */
    public function test_media_route_is_gone(): void
    {
        $this->assertNull(Router::match('GET', '/media/1/2-card'));
        $this->assertNull(Router::match('GET', '/media/12/345-view'));
        $this->assertNull(Router::match('GET', '/media/12/avatar-0a1b2c3d'));
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

    public function test_workspace_routes(): void
    {
        $this->assertSame(['page' => 'order-upload', 'params' => ['id' => '345']], Router::match('POST', '/orders/345/upload'));
        $this->assertSame(['page' => 'order-preview', 'params' => ['id' => '345', 'preview' => '17']], Router::match('POST', '/orders/345/previews/17'));
        $this->assertNull(Router::match('GET', '/orders/345/upload'));
        $this->assertNull(Router::match('POST', '/orders/345/upload/'));
        $this->assertNull(Router::match('POST', '/orders/abc/upload'));
        $this->assertNull(Router::match('GET', '/orders/345/previews/17'));
        $this->assertNull(Router::match('POST', '/orders/345/previews/abc'));
        $this->assertNull(Router::match('POST', '/orders/345/previews/1234567890'));
        $this->assertNull(Router::withSlash('/orders/345/upload'));
    }

    public function test_chat_routes(): void
    {
        $this->assertSame(['page' => 'offer-messages', 'params' => ['id' => '345']], Router::match('GET', '/offers/345/messages'));
        $this->assertSame(['page' => 'offer-message-add', 'params' => ['id' => '345']], Router::match('POST', '/offers/345/messages'));
        $this->assertNull(Router::match('GET', '/offers/345/messages/'));
        $this->assertNull(Router::match('GET', '/offers/abc/messages'));
        $this->assertNull(Router::match('GET', '/offers/1234567890/messages'));
        $this->assertNull(Router::match('PUT', '/offers/345/messages'));
        $this->assertNull(Router::withSlash('/offers/345/messages'));
    }

    public function test_designer_offer_routes(): void
    {
        $this->assertSame(['page' => 'feed', 'params' => []], Router::match('GET', '/feed/'));
        $this->assertSame(['page' => 'feed-order', 'params' => ['id' => '345']], Router::match('GET', '/feed/345/'));
        $this->assertSame(['page' => 'feed-offer', 'params' => ['id' => '345']], Router::match('POST', '/feed/345'));
        $this->assertSame(['page' => 'my-offers', 'params' => []], Router::match('GET', '/my-offers/'));
        $this->assertNull(Router::match('POST', '/feed/'));
        $this->assertNull(Router::match('POST', '/feed/345/'));
        $this->assertNull(Router::match('GET', '/feed/abc/'));
        $this->assertNull(Router::match('GET', '/feed/1234567890/'));
        $this->assertNull(Router::match('POST', '/my-offers/'));
        $this->assertSame('/feed/', Router::withSlash('/feed'));
        $this->assertSame('/feed/345/', Router::withSlash('/feed/345'));
        $this->assertSame('/my-offers/', Router::withSlash('/my-offers'));
    }

    public function test_client_offer_routes(): void
    {
        $this->assertSame(['page' => 'order-pick', 'params' => ['id' => '345']], Router::match('GET', '/orders/345/pick/'));
        $this->assertNull(Router::match('POST', '/orders/345/pick/'));
        $this->assertNull(Router::match('GET', '/orders/abc/pick/'));
        $this->assertNull(Router::match('GET', '/orders/1234567890/pick/'));
        $this->assertSame('/orders/345/pick/', Router::withSlash('/orders/345/pick'));
    }
}
