<?php
use Design\DesignerException;
use Design\Guard;
use Design\Http;
use Design\Media;
use Design\Portfolio;
use Design\Upload;

$me = Guard::customer();
try {
    // Розмір — до CSRF: файл більший за post_max_size PHP відкидає разом із полем csrf
    $tmp = Upload::tmp('file', $_FILES, $_SERVER);
    if (!Guard::csrfOk($me)) Http::json(['error' => Guard::STALE_AJAX], 403);
    $id = Portfolio::add($me, $tmp)['id'];
} catch (DesignerException $e) {
    Http::json(['error' => $e->getMessage()], 422);
} catch (\Throwable $e) {
    error_log('design: завантаження роботи клієнта ' . $me . ': ' . $e);
    Http::json(['error' => 'Не вдалося зберегти файл — спробуйте ще раз'], 500);
}
Http::json([
    'id' => $id,
    'card' => Media::url($me, "$id-card"),
    'view' => Media::url($me, "$id-view"),
    'count' => Portfolio::count($me),
]);
