<?php
/**
 * Клієнт скасовує замовлення (спека «Скасувати замовлення»): POST /orders/{id}/cancel, csrf, reason (обов'язкова).
 * Звичайна форма сторінки замовлення (views/partials/order-cancel.php, ?form=cancel). Успіх — назад на сторінку з тостом
 * (?done=cancelled); сповіщення дизайнерам і куратору (Orders::notifyCancel) — уже після перенаправлення.
 * Помилка — та сама сторінка з відкритою формою, введеною причиною і текстом (застарілий CSRF — 403, інше — 422).
 * Чуже замовлення — 404
 * @var array $params
 */
use Design\DesignerException;
use Design\Guard;
use Design\Http;
use Design\Orders;

$me = Guard::customer();
$orderId = (int)$params['id'];
if (Orders::get($orderId, $me) === null) Http::notFound();
$fresh = Guard::csrfOk($me);
$reason = Http::str($_POST, 'reason');

try {
    if (!$fresh) throw new DesignerException(Guard::STALE);
    Orders::cancel($orderId, $me, $reason);
} catch (DesignerException $e) {
    if ($fresh) http_response_code(422);
    $params = ['id' => (string)$orderId];
    $posted = ['error' => $e->getMessage(), 'form' => 'cancel', 'text' => $reason];
    require __DIR__ . '/order.php';
    exit;
}

Http::redirect("/orders/$orderId/?done=cancelled", 302, static fn() => Orders::notifyCancel($orderId));
