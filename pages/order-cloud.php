<?php
/**
 * «Скопіювати в хмару»: POST /orders/{id}/layouts/{fileId}/cloud — csrf. Лише клієнт замовлення, лише куплений макет цього
 * замовлення. Лише ставить у чергу (CloudCopy::request — тільки БД): копіює крон wolf.ua/cron/design_cloud_copy.php, HTTP-запит
 * до /mnt/cloud не звертається. Відповідь — панель {layouts, count, rev, turn} (у макета cloud_status); стан далі
 * assets/js/workspace-layouts.js опитує GET /orders/{id}/layouts раз на 10 с.
 * Помилка — {error}: 404 не клієнт, 403 застаріла сесія, 422 некуплений / чужий макет, 503 база зайнята, 500
 * @var array $params
 */
use Design\CloudCopy;
use Design\DesignerException;
use Design\Guard;
use Design\Http;
use Design\Layouts;
use Design\Messages;
use Design\Workspace;

$me = Guard::customer();
$orderId = (int)$params['id'];
$fileId = (int)$params['file'];

try {
    $access = Workspace::access($orderId, $me);
    if ($access === null || $access['role'] !== Messages::ROLE_CLIENT) Http::json(['error' => 'Замовлення не знайдено'], 404);
    if (!Guard::csrfOk($me)) Http::json(['error' => Guard::STALE_AJAX], 403);
    // CloudCopy::request бере замовлення з рядка макета — тож макет має бути саме цього замовлення
    if (!in_array($fileId, array_column(Layouts::forOrder($orderId), 'id'), true)) throw new DesignerException('Макет не знайдено');
    CloudCopy::request($fileId, $me);
    $response = Workspace::layouts($orderId, true) + ['turn' => Workspace::turn($access['offer'], Messages::ROLE_CLIENT)];
} catch (DesignerException $e) {
    Http::json(['error' => $e->getMessage()], 422);
} catch (\Throwable $e) {
    error_log("design: копія в хмару макета $fileId замовлення $orderId від $me: " . $e);
    $busy = $e instanceof \PDOException
        && ((string)$e->getCode() === '40001' || in_array((int)($e->errorInfo[1] ?? 0), [1205, 1213], true));
    Http::json(['error' => $busy ? 'Сервер зайнятий — спробуйте ще раз' : 'Не вдалося поставити копію в чергу — спробуйте ще раз'], $busy ? 503 : 500);
}
Http::json($response);
