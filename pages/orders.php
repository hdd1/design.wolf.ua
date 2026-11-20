<?php
use Design\Guard;
use Design\Layouts;
use Design\Orders;
use Design\OrderStatus;
use Design\View;

$me = Guard::customer();
$mine = $other = [];
foreach (Orders::forCustomer($me) as $o) {
    // Режим 2 у зборі без пропозиції, яку можна обрати: дизайнер відмовився — клієнт вирішує, що далі
    $o['turn'] = $o['status'] === OrderStatus::COLLECTING && $o['mode'] === 2 && $o['offers'] === 0
        ? ['mine' => true, 'text' => 'Ваш хід: оберіть, як продовжити']
        : OrderStatus::turn($o['status'], $o['offers'], $o['status'] === OrderStatus::IN_WORK ? Layouts::count($o['id']) : 0);
    if ($o['turn']['mine']) $mine[] = $o; else $other[] = $o;
}
View::render('layout', ['title' => 'Мої замовлення', 'active' => 'orders', 'body' => View::fetch('orders', ['mine' => $mine, 'other' => $other])]);
