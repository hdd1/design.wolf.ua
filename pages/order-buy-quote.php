<?php
/**
 * Свіжий вміст вікна оплати: GET /orders/{id}/buy → {quote, html} (Purchases::quote і views/partials/pay-balance.php).
 * assets/js/workspace-buy.js перечитує його, коли клієнт натискає «Купую» (баланс, сума і нові макети могли змінитися
 * після відкриття сторінки). Лише клієнт замовлення, яке зараз можна купити («В роботі», «Доопрацювання»); інакше 404
 * @var array $params
 */
use Design\Guard;
use Design\Http;
use Design\Purchases;
use Design\View;

$me = Guard::customer();
$orderId = (int)$params['id'];

try {
    $quote = Purchases::quote($orderId, $me);
    if ($quote === null) Http::json(['error' => 'Замовлення зараз не можна купити'], 404);
    $data = ['quote' => $quote, 'html' => View::fetch('partials/pay-balance', ['quote' => $quote])];
} catch (\Throwable $e) {
    error_log("design: вікно оплати замовлення $orderId для $me: " . $e);
    Http::json(['error' => 'Не вдалося оновити суму — спробуйте ще раз'], 500);
}
Http::json($data);
