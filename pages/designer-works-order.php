<?php
use Design\DesignerException;
use Design\Guard;
use Design\Http;
use Design\Portfolio;

$me = Guard::customer();
if (!Guard::csrfOk($me)) Http::json(['error' => Guard::STALE_AJAX], 403);
$ids = $_POST['ids'] ?? [];
if (!is_array($ids)) $ids = [];

try {
    Portfolio::reorder($me, array_map('intval', array_filter($ids, 'is_string')));
} catch (DesignerException $e) {
    Http::json(['error' => $e->getMessage()], 422);
}
Http::json(['ok' => true]);
