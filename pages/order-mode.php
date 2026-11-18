<?php
/** Крок 3 брифу «Як обрати дизайнера?»: GET /orders/{id}/mode/ — «Зібрати пропозиції» (публікація) або «Одразу обрати» (/pick/) */
/** @var array $params */
use Design\Csrf;
use Design\Designers;
use Design\DesignerStatus;
use Design\Guard;
use Design\Http;
use Design\Orders;
use Design\OrderStatus;
use Design\View;

$me = Guard::customer();
$order = Orders::get((int)$params['id'], $me);
if ($order === null) Http::notFound();
// Уже опубліковано («Назад» після публікації) — на сторінку замовлення, а не 404
if ($order['status'] !== OrderStatus::DRAFT) Http::redirect('/orders/' . $order['id'] . '/', 302);

// «Одразу обрати» — коли є хоч один схвалений дизайнер, крім самого клієнта
$canPick = Designers::catalog('', 1)['total'] - (Designers::status($me) === DesignerStatus::APPROVED ? 1 : 0) > 0;
View::render('layout', ['title' => 'Як обрати дизайнера', 'active' => 'brief',
    'body' => View::fetch('brief-mode', ['orderId' => $order['id'], 'csrf' => Csrf::token($me), 'canPick' => $canPick])]);
