<?php
/**
 * Панель «Макети» без перезавантаження: GET /orders/{id}/layouts → {layouts, count, rev, turn} (Workspace::layouts, Workspace::turn).
 * Клієнт замовлення або дизайнер обраної пропозиції; інакше 404. Лише підпис, ім'я файла й розмір — без адрес: до купівлі
 * макети не віддаються. assets/js/workspace-layouts.js перечитує панель, коли змінюється layouts_rev в опитуванні листування
 * @var array $params
 */
use Design\Guard;
use Design\Http;
use Design\Workspace;

$me = Guard::customer();
$orderId = (int)$params['id'];

try {
    $access = Workspace::access($orderId, $me);
    if ($access === null) Http::json(['error' => 'Замовлення не знайдено'], 404);
    $data = Workspace::layouts($orderId) + ['turn' => Workspace::turn($access['offer'], $access['role'])];
} catch (\Throwable $e) {
    error_log("design: макети замовлення $orderId для $me: " . $e);
    Http::json(['error' => 'Не вдалося оновити список макетів'], 500);
}
Http::json($data);
