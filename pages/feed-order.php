<?php
/**
 * Бриф для дизайнера: GET /feed/{id}/ — повний бриф, своя пропозиція або запрошення, листування.
 * Бачить схвалений дизайнер: бриф у зборі пропозицій режиму 1 або замовлення, де в нього є пропозиція / запрошення.
 * ?form=propose|edit|take|decline — відкрита форма; ?done=… — тост після дії.
 * Після помилки POST сторінку рендерить pages/feed-offer.php з $posted (form, values, error)
 * @var array $params
 * @var ?array{form:string, values:array{text:string,price:string,deadline:string,reason:string}, error:string} $posted
 */
use Design\Chat;
use Design\Csrf;
use Design\Customers;
use Design\Designers;
use Design\DesignerStatus;
use Design\Guard;
use Design\Http;
use Design\Messages;
use Design\Offers;
use Design\OfferStatus;
use Design\Orders;
use Design\OrderStatus;
use Design\Settings;
use Design\View;

$me = Guard::customer();
if (Designers::status($me) !== DesignerStatus::APPROVED) Http::notFound();
$order = Orders::find((int)$params['id']);
if ($order === null) Http::notFound();
$id = $order['id'];
// Дизайнер, що відкрив власне замовлення, — на сторінку клієнта
if ($order['customer_id'] === $me) Http::redirect("/orders/$id/", 302);

$offer = Offers::forDesigner($id, $me);
$open = $order['status'] === OrderStatus::COLLECTING && $order['mode'] === 1;
if ($offer === null && !$open) Http::notFound();
$status = $offer['status'] ?? null;

$invite = $status === OfferStatus::INVITED && $order['status'] === OrderStatus::WAITING_DESIGNER && $order['mode'] === 2;
$forms = array_filter([
    'propose' => $open && in_array($status, [null, OfferStatus::DECLINED, OfferStatus::WITHDRAWN], true),
    'edit' => $status === OfferStatus::SENT,
    'take' => $invite,
    'decline' => $invite,
]);
$posted ??= null;
$form = $posted['form'] ?? Http::str($_GET, 'form');
if (!isset($forms[$form])) $form = '';

// Зміна — поточні поля; повторна пропозиція після відкликання — попередні (минулий термін не підставляємо)
$previous = $offer !== null && $offer['price_designer'] > 0 && in_array($form, ['edit', 'propose'], true);
$values = $posted['values'] ?? ($previous
    ? ['text' => $offer['text'], 'price' => (string)$offer['price_designer'],
       'deadline' => $offer['deadline'] !== null && $offer['deadline'] >= date('Y-m-d') ? $offer['deadline'] : '', 'reason' => '']
    : ['text' => '', 'price' => '', 'deadline' => '', 'reason' => '']);

$client = Customers::header($order['customer_id'])['short'] ?? 'Клієнт';
$csrf = Csrf::token($me);

// Листування: є пропозиція і в ній можна писати або вже щось написано. Перший рендер — сторона «в чаті».
// Запрошення без відповіді — без листування (лише «Беру» / «Відмовитися»), навіть якщо в рядку лишилось листування минулого раунду
$chat = null;
if ($offer !== null && $status !== OfferStatus::INVITED) {
    $access = Offers::access($offer['id'], $me);
    $messages = Chat::messages($access);
    if ($access['write'] || $messages) {
        Messages::seen($offer['id'], Messages::ROLE_DESIGNER);
        $chat = [
            'offerId' => $offer['id'],
            'title' => $client,
            'subtitle' => $offer['price_designer'] <= 0 ? ''
                : 'Ви: ' . View::price($offer['price_designer']) . ' на руки' . ($offer['deadline'] ? ' · до ' . View::date($offer['deadline']) : ''),
            'note' => $status === OfferStatus::CHOSEN ? null : 'До вибору — лише текстові повідомлення. Файли і прев\'ю — після вибору дизайнера',
            'csrf' => $csrf,
            'messages' => $messages,
            'write' => $access['write'],
            'footer' => null,
        ];
    }
}

$toasts = [
    'sent' => 'Пропозицію надіслано — клієнт отримав сповіщення',
    'updated' => 'Пропозицію змінено',
    'withdrawn' => 'Пропозицію відкликано',
    'accepted' => 'Ви взяли замовлення — клієнт побачить вашу пропозицію',
    'declined' => 'Ви відмовилися від замовлення',
];

$scripts = ['/assets/js/offer-form.js'];
if ($chat !== null) $scripts[] = '/assets/js/chat.js';
View::render('layout', [
    'title' => $order['title'],
    'active' => 'feed',
    'scripts' => $scripts,
    'toast' => $posted === null ? ($toasts[Http::str($_GET, 'done')] ?? null) : null,
    'body' => View::fetch('feed-order', [
        'order' => $order,
        'offer' => $offer,
        'client' => $client,
        'forms' => $forms,
        'form' => $form,
        'values' => $values,
        'error' => $posted['error'] ?? null,
        'csrf' => $csrf,
        'chat' => $chat,
        'k' => Settings::markupPercent(),
        'round' => Settings::priceRound(),
    ]),
]);
