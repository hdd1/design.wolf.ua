<?php
/** @var array $params */
use Design\BriefPage;
use Design\Csrf;
use Design\Guard;
use Design\Http;
use Design\Orders;
use Design\OrderStatus;
use Design\Products;
use Design\View;

$me = Guard::customer();
$order = Orders::get((int)$params['id'], $me);
if ($order === null) Http::notFound();

if ($order['status'] === OrderStatus::DRAFT) {
    $saved = isset($_GET['saved']);
    BriefPage::render([
        'productName' => $order['product_name'],
        'productId' => null,
        'postTo' => '/orders/' . $order['id'],
        'title' => $order['title'] !== $order['product_name'] ? $order['title'] : '',
        'fields' => BriefPage::fromSnapshot($order['answers'], Products::get($order['product_id'])),
        'csrf' => Csrf::token($me),
        'savedAt' => $saved ? date('H:i') : null,
    ], $saved ? 'Чернетку збережено. Її видно в «Мої замовлення»' : null);
    exit;
}

$order['turn'] = OrderStatus::turn($order['status']);
View::render('layout', ['title' => $order['title'], 'active' => 'orders', 'body' => View::fetch('order', ['order' => $order])]);
