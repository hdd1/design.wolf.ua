<?php
/** Стрічка брифів схваленого дизайнера: GET /feed/?page=N. Відкриття стрічки скидає кружечок нових брифів у меню */
use Design\Csrf;
use Design\Customers;
use Design\Designers;
use Design\DesignerStatus;
use Design\Guard;
use Design\Http;
use Design\Offers;
use Design\View;

$me = Guard::customer();
if (Designers::status($me) !== DesignerStatus::APPROVED) Http::notFound();

$page = max(1, min(1000, (int)Http::str($_GET, 'page')));
$feed = Offers::feed($me, $page);
if ($page > $feed['pages']) {   // застаріле посилання на сторінку, якої вже немає
    $page = $feed['pages'];
    $feed = Offers::feed($me, $page);
}
// Факти про клієнта брифу — по одному запиту на клієнта сторінки
$facts = [];
foreach ($feed['items'] as $b) $facts[$b['customer_id']] ??= Customers::factsLine(Customers::facts($b['customer_id']));
// Позначки «нове» вже пораховані від попереднього відкриття — тепер відмічаємо це
Offers::markFeedSeen($me);

View::render('layout', [
    'title' => 'Стрічка брифів',
    'active' => 'feed',
    'scripts' => ['/assets/js/offer-form.js'],
    'toast' => Http::str($_GET, 'done') === 'withdrawn' ? 'Пропозицію відкликано' : null,
    'body' => View::fetch('feed', ['page' => $page, 'csrf' => Csrf::token($me), 'facts' => $facts] + $feed),
]);
