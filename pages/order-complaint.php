<?php
/**
 * Скарга клієнта після купівлі (спека «Скарга»): POST /orders/{id}/complaint, csrf, text (обов'язковий).
 * Звичайна форма робочого простору (views/partials/workspace.php, ?form=complaint). Успіх — назад на сторінку з тостом
 * (?done=complaint), там «Скаргу надіслано {дата}»; сповіщення куратору і user_id = 1 (Complaints::notify) — після перенаправлення.
 * Помилка — та сама сторінка з відкритою формою, введеним текстом і помилкою (застарілий CSRF — 403, інше — 422).
 * Чуже замовлення — 404
 * @var array $params
 */
use Design\Complaints;
use Design\DesignerException;
use Design\Guard;
use Design\Http;
use Design\Orders;

$me = Guard::customer();
$orderId = (int)$params['id'];
if (Orders::get($orderId, $me) === null) Http::notFound();
$fresh = Guard::csrfOk($me);
$text = Http::str($_POST, 'text');

try {
    if (!$fresh) throw new DesignerException(Guard::STALE);
    $complaintId = Complaints::add($orderId, $me, $text);
} catch (DesignerException $e) {
    if ($fresh) http_response_code(422);
    $params = ['id' => (string)$orderId];
    $posted = ['error' => $e->getMessage(), 'form' => 'complaint', 'text' => $text];
    require __DIR__ . '/order.php';
    exit;
}

Http::redirect("/orders/$orderId/?done=complaint", 302, static fn() => Complaints::notify($complaintId));
