<?php
/**
 * Доопрацювання (спека «Ще не закінчили»): POST /orders/{id}/rework, csrf, action =
 *   propose — обраний дизайнер, «Куплено»: price (нова повна сума на руки, «1 300» теж), deadline (Y-m-d);
 *   accept | decline — клієнт відповідає на пропозицію; cancel — клієнт скасовує доопрацювання, яке триває.
 * Звичайні форми робочого простору (views/partials/workspace.php). Успіх — назад на сторінку з тостом (дизайнер —
 * /feed/{id}/?done=rework-sent, клієнт — /orders/{id}/?done=rework-…); сповіщення (Rework::notify) — уже після перенаправлення.
 * Помилка — та сама сторінка з текстом (застарілий CSRF — 403, інше — 422), у дизайнера — з відкритою формою і введеним.
 * Не клієнт і не обраний дизайнер замовлення — 404
 * @var array $params
 */
use Design\DesignerException;
use Design\Guard;
use Design\Http;
use Design\Messages;
use Design\Offers;
use Design\Rework;
use Design\Workspace;

$me = Guard::customer();
$orderId = (int)$params['id'];
$access = Workspace::access($orderId, $me);
if ($access === null) Http::notFound();
$designer = $access['role'] === Messages::ROLE_DESIGNER;
$back = $designer ? "/feed/$orderId/" : "/orders/$orderId/";
$fresh = Guard::csrfOk($me);
$action = Http::str($_POST, 'action');

$done = ['propose' => 'rework-sent', 'accept' => 'rework-accepted', 'decline' => 'rework-declined', 'cancel' => 'rework-cancelled'];
if (!isset($done[$action])) Http::redirect($back, 302);

$values = ['text' => '', 'price' => Http::str($_POST, 'price'), 'deadline' => trim(Http::str($_POST, 'deadline')), 'reason' => ''];
// Як у pages/feed-offer.php: «1 300», «1300 » → 1300; не число — 0 («Вкажіть суму від 1 грн»); задовге — понад межу
$digits = preg_replace('/[\s\x{00A0}\x{202F}]/u', '', $values['price']);
$price = preg_match('/^\d{1,9}$/', $digits) ? (int)$digits : (preg_match('/^\d+$/', $digits) ? Offers::PRICE_MAX + 1 : 0);

try {
    if (!$fresh) throw new DesignerException(Guard::STALE);
    match ($action) {
        'propose' => Rework::propose($orderId, $me, $price, $values['deadline']),
        'accept' => Rework::accept($orderId, $me),
        'decline' => Rework::decline($orderId, $me),
        'cancel' => Rework::cancel($orderId, $me),
    };
} catch (DesignerException $e) {
    if ($fresh) http_response_code(422);
    $params = ['id' => (string)$orderId];
    if ($designer) {
        $posted = ['form' => $action === 'propose' ? 'rework' : '', 'values' => $values, 'error' => $e->getMessage()];
        require __DIR__ . '/feed-order.php';
    } else {
        $posted = ['error' => $e->getMessage()];
        require __DIR__ . '/order.php';
    }
    exit;
}

Http::redirect($back . '?done=' . $done[$action], 302, static fn() => Rework::notify($orderId, $action));
