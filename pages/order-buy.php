<?php
/**
 * «Купую» → «Оплатити» / «Відкрити макети»: POST /orders/{id}/buy — amount (сума, яку клієнт бачив у вікні, ціле ≥ 0), csrf.
 * Лише клієнт замовлення (Workspace::access, роль клієнта); гроші — Purchases::buy однією транзакцією.
 * Відповідь 200 — {ok, layouts, count, rev, turn, status_html, balance_text, stage}: панель «Макети» (у куплених — «Завантажити»),
 * «чий хід», статус і баланс («3 800 грн», як у шапці layout) для шапки, stage — новий стан для опитування (Workspace::stage);
 * сповіщення дизайнеру — після відповіді браузеру (Http::respond: api2 без тайм-ауту).
 * Помилка — {error}: 400 відмова купівлі (нестача, ціна змінилася, вже куплено, …) — ще й html свіжого вмісту вікна,
 * якщо замовлення досі можна купити; 403 застаріла сесія; 404 не клієнт; 503 замовлення зайняте (замок, взаємоблокування) або
 * 500 — без подробиць (подробиці — у журнал)
 * @var array $params
 */
use Design\Customers;
use Design\DesignerException;
use Design\Guard;
use Design\Http;
use Design\Messages;
use Design\Purchases;
use Design\View;
use Design\Workspace;

$me = Guard::customer();
$orderId = (int)$params['id'];
$priceChanged = 'Ціна змінилася — перевірте суму';

try {
    $access = Workspace::access($orderId, $me);
    if ($access === null || $access['role'] !== Messages::ROLE_CLIENT) Http::json(['error' => 'Замовлення не знайдено'], 404);
    if (!Guard::csrfOk($me)) Http::json(['error' => Guard::STALE_AJAX], 403);
    $amount = Http::str($_POST, 'amount');
    if (preg_match('/^(0|[1-9]\d{0,8})$/D', $amount) !== 1) throw new DesignerException($priceChanged);
    $result = Purchases::buy($orderId, $me, (int)$amount);
} catch (DesignerException $e) {
    $error = ['error' => $e->getMessage()];
    // Свіжий вміст вікна (сума, баланс, причина) — щоб клієнт побачив, що змінилось, і не оновлював сторінку
    try {
        $quote = Purchases::quote($orderId, $me);
        if ($quote !== null) $error['html'] = View::fetch('partials/pay-balance', ['quote' => $quote]);
    } catch (\Throwable $e2) {
        error_log("design: вікно оплати замовлення $orderId для $me: " . $e2);
    }
    Http::json($error, 400);
} catch (\Throwable $e) {
    error_log("design: купівля замовлення $orderId від $me: " . $e);
    $busy = $e instanceof \PDOException
        && ((string)$e->getCode() === '40001' || in_array((int)($e->errorInfo[1] ?? 0), [1205, 1213], true));
    Http::json(['error' => $busy ? 'Сервер зайнятий — спробуйте ще раз' : 'Не вдалося оплатити — спробуйте ще раз'], $busy ? 503 : 500);
}

// Купівлю проведено: збій побудови відповіді — не помилка купівлі (браузер перезавантажить сторінку)
try {
    $offer = Workspace::access($orderId, $me)['offer'];
    $response = ['ok' => true] + Workspace::layouts($orderId, true) + [
        'turn' => Workspace::turn($offer, Messages::ROLE_CLIENT),
        'status_html' => View::fetch('partials/status', ['status' => $offer['order_status']]),
        'balance_text' => View::price(Customers::header($me)['balance'] ?? 0),
        'stage' => Workspace::stage($offer['order_status'], false),
    ];
} catch (\Throwable $e) {
    error_log("design: відповідь після купівлі замовлення $orderId: " . $e);
    $response = ['ok' => true, 'done' => true];
}
Http::respond($response, 200, static fn() => Purchases::notifyDesigner($result, $orderId));
exit;
