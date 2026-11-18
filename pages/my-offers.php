<?php
/** «Мої пропозиції і замовлення» схваленого дизайнера: GET /my-offers/ — вкладка «Пропозиції», ?tab=work — «В роботі» */
use Design\Csrf;
use Design\Designers;
use Design\DesignerStatus;
use Design\Guard;
use Design\Http;
use Design\Offers;
use Design\View;

$me = Guard::customer();
if (Designers::status($me) !== DesignerStatus::APPROVED) Http::notFound();

View::render('layout', [
    'title' => 'Мої пропозиції',
    'active' => 'my-offers',
    'scripts' => ['/assets/js/offer-form.js'],
    'toast' => Http::str($_GET, 'done') === 'withdrawn' ? 'Пропозицію відкликано' : null,
    'body' => View::fetch('my-offers', [
        'tab' => Http::str($_GET, 'tab') === 'work' ? 'work' : 'offers',
        'csrf' => Csrf::token($me),
    ] + Offers::mine($me)),
]);
