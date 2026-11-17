<?php
/** @var array $params */
use Design\DesignerException;
use Design\Guard;
use Design\Http;
use Design\Portfolio;

$me = Guard::customer();
if (!Guard::csrfOk($me)) Http::json(['error' => Guard::STALE_AJAX], 403);
$id = (int)$params['id'];

try {
    switch ((string)($_POST['action'] ?? '')) {
        case 'delete':
            Portfolio::remove($me, $id);
            Http::json(['ok' => true, 'count' => Portfolio::count($me)]);
        case 'shw':
            $on = ($_POST['on'] ?? '') === '1';
            Portfolio::setShw($me, $id, $on);
            Http::json(['ok' => true, 'shw' => $on]);
        case 'title':
            $title = trim((string)($_POST['title'] ?? ''));
            Portfolio::setTitle($me, $id, $title);
            Http::json(['ok' => true, 'title' => $title]);
        default:
            Http::json(['error' => 'Невідома дія'], 422);
    }
} catch (DesignerException $e) {
    Http::json(['error' => $e->getMessage()], 422);
}
