<?php
/**
 * Нове повідомлення: POST /offers/{id}/messages (text, csrf, reply_to — id повідомлення чи прев'ю цього листування, якщо це
 * відповідь / коментар) → {message} у тому ж вигляді, що й опитування.
 * Після запису — seen своєї ролі і сповіщення співрозмовнику (лише про перше непрочитане, див. Messages::notifyNew)
 * @var array $params
 */
use Design\Chat;
use Design\Customers;
use Design\DesignerException;
use Design\Designers;
use Design\Guard;
use Design\Http;
use Design\Messages;
use Design\Offers;

$me = Guard::customer();
$id = (int)$params['id'];
$access = Offers::access($id, $me);
if ($access === null) Http::json(['error' => 'Листування не знайдено'], 404);
if (!Guard::csrfOk($me)) Http::json(['error' => Guard::STALE_AJAX], 403);
if (!$access['write']) Http::json(['error' => 'Листування закрите'], 403);

$offer = $access['offer'];
try {
    $replyTo = trim(Http::str($_POST, 'reply_to'));
    if ($replyTo !== '' && !preg_match('/^[1-9]\d{0,9}$/D', $replyTo)) {
        throw new DesignerException('Повідомлення, на яке ви відповідаєте, не знайдено');
    }
    $msgId = Messages::add($id, $me, $access['role'], Http::str($_POST, 'text'), [], $replyTo === '' ? null : (int)$replyTo);
    Messages::seen($id, $access['role']);
} catch (DesignerException $e) {
    Http::json(['error' => $e->getMessage()], 422);
} catch (\Throwable $e) {
    error_log('design: повідомлення в пропозицію ' . $id . ' від клієнта ' . $me . ': ' . $e);
    Http::json(['error' => 'Не вдалося надіслати. Спробуйте ще раз.'], 500);
}

// Сповіщення — після запису: його збій не скасовує вже надіслане повідомлення
try {
    if ($access['role'] === Messages::ROLE_CLIENT) {
        Messages::notifyNew($id, Messages::ROLE_DESIGNER, $offer['designer_id'],
            Customers::header($me)['short'] ?? 'клієнта', $offer['order_id']);
    } else {
        Messages::notifyNew($id, Messages::ROLE_CLIENT, $offer['customer_id'],
            Designers::get($me)['name'] ?? 'дизайнера', $offer['order_id']);
    }
} catch (\Throwable $e) {
    error_log('design: сповіщення про повідомлення ' . $msgId . ': ' . $e);
}

$message = Chat::messages($access, $msgId - 1, 1)[0] ?? null;
Http::json(['message' => $message]);
