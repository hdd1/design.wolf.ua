<?php
/**
 * Дія з прев'ю: POST /orders/{id}/previews/{previewId} (action=hide, csrf) → {preview} у вигляді Previews::byIds
 * (приховане — без адрес картинок). Лише обраний дизайнер замовлення в роботі; прев'ю — цього замовлення
 * @var array $params
 */
use Design\DesignerException;
use Design\Guard;
use Design\Http;
use Design\Previews;

$me = Guard::customer();
$orderId = (int)$params['id'];
$previewId = (int)$params['preview'];
$denied = "Приховувати прев'ю може лише обраний дизайнер замовлення в роботі";

try {
    // Немає доступу і немає прев'ю в цьому замовленні — та сама відповідь
    if (Previews::canWork($orderId, $me) === null || Previews::byIds($orderId, [$previewId]) === []) {
        Http::json(['error' => $denied], 403);
    }
    if (!Guard::csrfOk($me)) Http::json(['error' => Guard::STALE_AJAX], 403);
    if (Http::str($_POST, 'action') !== 'hide') Http::json(['error' => 'Невідома дія'], 422);
    Previews::hide($previewId, $me);
    Http::json(['preview' => Previews::byIds($orderId, [$previewId])[$previewId]]);
} catch (DesignerException $e) {
    Http::json(['error' => $e->getMessage()], 403);
} catch (\Throwable $e) {
    error_log("design: приховати прев'ю $previewId замовлення $orderId від $me: " . $e);
    Http::json(['error' => "Не вдалося приховати прев'ю — спробуйте ще раз"], 500);
}
