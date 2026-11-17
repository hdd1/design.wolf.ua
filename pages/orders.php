<?php
use Design\Guard;
use Design\Orders;
use Design\OrderStatus;
use Design\View;

$me = Guard::customer();
$mine = $other = [];
foreach (Orders::forCustomer($me) as $o) {
    $o['turn'] = OrderStatus::turn($o['status']);
    if ($o['turn']['mine']) $mine[] = $o; else $other[] = $o;
}
View::render('layout', ['title' => 'Мої замовлення', 'active' => 'orders', 'body' => View::fetch('orders', ['mine' => $mine, 'other' => $other])]);
