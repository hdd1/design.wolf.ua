<?php
/**
 * Дія з макетом: POST /orders/{id}/layouts/{fileId} — action=rename (label, 1–120 символів) | delete, csrf.
 * Лише обраний дизайнер замовлення в роботі (Previews::canWork); макет — активний макет цього замовлення.
 * Відповідь — панель після дії: {layouts, count, rev, turn} (як GET /orders/{id}/layouts); помилка — {error}:
 * 403 немає доступу / застаріла сесія, 422 невірні дані або макет уже замінено / видалено, 503 замовлення зайняте, 500.
 * Сповіщення клієнту про видалення — після відповіді браузеру (Http::respond: api2 без тайм-ауту)
 * @var array $params
 */
use Design\DesignerException;
use Design\Guard;
use Design\Http;
use Design\Layouts;
use Design\Messages;
use Design\Previews;
use Design\Workspace;

$me = Guard::customer();
$orderId = (int)$params['id'];
$fileId = (int)$params['file'];
$gone = 'Макет не знайдено: його вже замінено або видалено';
$notice = null;

try {
    $offer = Previews::canWork($orderId, $me);
    if ($offer === null) Http::json(['error' => 'Змінювати макети може лише обраний дизайнер замовлення в роботі'], 403);
    if (!Guard::csrfOk($me)) Http::json(['error' => Guard::STALE_AJAX], 403);
    // Layouts::rename/remove беруть замовлення з рядка макета — тож макет має бути саме цього замовлення
    if (!in_array($fileId, array_column(Layouts::forOrder($orderId), 'id'), true)) throw new DesignerException($gone);
    $notice = match (Http::str($_POST, 'action')) {
        'rename' => Layouts::rename($fileId, $me, Http::str($_POST, 'label')),
        'delete' => Layouts::remove($fileId, $me),
        default => throw new DesignerException('Невідома дія'),
    };
} catch (DesignerException $e) {
    Http::json(['error' => $e->getMessage()], 422);
} catch (\Throwable $e) {
    error_log("design: дія з макетом $fileId замовлення $orderId від $me: " . $e);
    $busy = $e instanceof \PDOException
        && ((string)$e->getCode() === '40001' || in_array((int)($e->errorInfo[1] ?? 0), [1205, 1213], true));
    Http::json(['error' => $busy ? 'Сервер зайнятий — спробуйте ще раз' : 'Не вдалося змінити макет — спробуйте ще раз'], $busy ? 503 : 500);
}

// Дію виконано: збій побудови панелі — не помилка дії (браузер перечитає панель опитуванням)
try {
    $response = Workspace::layouts($orderId) + ['turn' => Workspace::turn($offer, Messages::ROLE_DESIGNER)];
} catch (\Throwable $e) {
    error_log("design: панель макетів замовлення $orderId після дії: " . $e);
    $response = ['done' => true];
}
Http::respond($response, 200, $notice === null ? null : static fn() => Workspace::notifyClient($notice));
exit;
