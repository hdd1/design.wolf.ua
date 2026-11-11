<?php
/** @var array $params */
use Design\Csrf;
use Design\Guard;
use Design\Http;
use Design\Orders;
use Design\OrderStatus;
use Design\View;

$me = Guard::customer();
$order = Orders::get((int)$params['id'], $me);
if ($order === null || $order['status'] !== OrderStatus::DRAFT) Http::notFound();

View::render('layout', ['title' => 'Як обрати дизайнера', 'active' => 'brief', 'body' => View::fetch('brief-mode', ['orderId' => $order['id'], 'csrf' => Csrf::token($me)])]);
