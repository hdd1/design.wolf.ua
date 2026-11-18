<?php
/**
 * Дії дизайнера з брифом: POST /feed/{id}, action = propose | update | withdraw | accept | decline.
 * Успіх — на сторінку брифу з тостом (back = feed | my — у стрічку чи «Мої пропозиції»); помилка — та сама сторінка
 * з введеним і текстом помилки (застарілий CSRF — 403, інше — 422)
 * @var array $params
 */
use Design\DesignerException;
use Design\Designers;
use Design\DesignerStatus;
use Design\Guard;
use Design\Http;
use Design\Offers;

$me = Guard::customer();
if (Designers::status($me) !== DesignerStatus::APPROVED) Http::notFound();
$orderId = (int)$params['id'];
$fresh = Guard::csrfOk($me);
$action = Http::str($_POST, 'action');

/** Яка форма відкрита на сторінці, якщо дія не вдалася */
$forms = ['propose' => 'propose', 'update' => 'edit', 'accept' => 'take', 'decline' => 'decline', 'withdraw' => ''];
$done = ['propose' => 'sent', 'update' => 'updated', 'accept' => 'accepted', 'decline' => 'declined', 'withdraw' => 'withdrawn'];
if (!isset($forms[$action])) Http::redirect("/feed/$orderId/", 302);

$values = [
    'text' => Http::str($_POST, 'text'),
    'price' => Http::str($_POST, 'price'),
    'deadline' => trim(Http::str($_POST, 'deadline')),
    'reason' => Http::str($_POST, 'reason'),
];
// «1 000», «1000 » → 1000; не число — 0 («Вкажіть суму від 1 грн»); задовге число — понад межу («Сума — не більше …»)
$digits = preg_replace('/[\s\x{00A0}\x{202F}]/u', '', $values['price']);
$price = preg_match('/^\d{1,9}$/', $digits) ? (int)$digits : (preg_match('/^\d+$/', $digits) ? Offers::PRICE_MAX + 1 : 0);

try {
    if (!$fresh) throw new DesignerException(Guard::STALE);
    if ($action === 'propose') {
        Offers::propose($orderId, $me, $values['text'], $price, $values['deadline']);
    } else {
        $offerId = Offers::forDesigner($orderId, $me)['id'] ?? throw new DesignerException('Пропозицію не знайдено');
        match ($action) {
            'update' => Offers::update($offerId, $me, $values['text'], $price, $values['deadline']),
            'accept' => Offers::accept($offerId, $me, $values['text'], $price, $values['deadline']),
            'decline' => Offers::decline($offerId, $me, $values['reason']),
            'withdraw' => Offers::withdraw($offerId, $me),
        };
    }
} catch (DesignerException $e) {
    if ($fresh) http_response_code(422);
    $posted = ['form' => $forms[$action], 'values' => $values, 'error' => $e->getMessage()];
    require __DIR__ . '/feed-order.php';
    exit;
}

$back = match (Http::str($_POST, 'back')) {
    'feed' => '/feed/',
    'my' => '/my-offers/',
    default => "/feed/$orderId/",
};
Http::redirect($back . '?done=' . $done[$action], 302);
