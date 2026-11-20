<?php
/**
 * «Мої пропозиції і замовлення» схваленого дизайнера: GET /my-offers/ — вкладка «Пропозиції», ?tab=work — «В роботі».
 * У замовлень робочого простору («В роботі», «Куплено», «Доопрацювання») — «чий хід» дизайнера (Workspace::turn, як у шапці)
 */
use Design\Csrf;
use Design\Designers;
use Design\DesignerStatus;
use Design\Guard;
use Design\Http;
use Design\Messages;
use Design\Offers;
use Design\View;
use Design\Workspace;

$me = Guard::customer();
if (Designers::status($me) !== DesignerStatus::APPROVED) Http::notFound();
$mine = Offers::mine($me);
// Рядок Offers::mine має все, що потрібно Workspace::turn: id, order_id, order_status, deadline
foreach ($mine['work'] as &$o) {
    $o['turn'] = in_array($o['order_status'], Workspace::STATUSES, true) ? Workspace::turn($o, Messages::ROLE_DESIGNER) : null;
}
unset($o);

View::render('layout', [
    'title' => 'Мої пропозиції',
    'active' => 'my-offers',
    'scripts' => ['/assets/js/offer-form.js'],
    'toast' => Http::str($_GET, 'done') === 'withdrawn' ? 'Пропозицію відкликано' : null,
    'body' => View::fetch('my-offers', [
        'tab' => Http::str($_GET, 'tab') === 'work' ? 'work' : 'offers',
        'csrf' => Csrf::token($me),
    ] + $mine),
]);
