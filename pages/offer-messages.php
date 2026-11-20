<?php
/**
 * Опитування листування: GET /offers/{id}/messages?after=N → нові повідомлення (до 100) і чи можна писати.
 * Обрана пропозиція (робочий простір) — ще workspace: «чий хід», версія списку макетів і приховані прев'ю (Workspace::poll).
 * Відкриття й кожне опитування відмічають, що сторона в листуванні (seen)
 * @var array $params
 */
use Design\Chat;
use Design\Guard;
use Design\Http;
use Design\Messages;
use Design\Offers;
use Design\OfferStatus;
use Design\Workspace;

$me = Guard::customer();
$id = (int)$params['id'];

try {
    $access = Offers::access($id, $me);
    if ($access === null) Http::json(['error' => 'Листування не знайдено'], 404);
    $after = max(0, (int)Http::str($_GET, 'after'));
    $messages = Chat::messages($access, $after, Chat::POLL_LIMIT);
    Messages::seen($id, $access['role']);
    $workspace = $access['offer']['status'] === OfferStatus::CHOSEN ? Workspace::poll($access['offer'], $access['role']) : null;
} catch (\Throwable $e) {
    error_log('design: листування пропозиції ' . $id . ' клієнта ' . $me . ': ' . $e);
    Http::json(['error' => 'Не вдалося оновити листування'], 500);
}
Http::json(['messages' => $messages, 'write' => $access['write']] + ($workspace === null ? [] : ['workspace' => $workspace]));
