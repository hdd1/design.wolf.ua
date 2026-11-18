<?php
use Design\DesignerException;
use Design\Designers;
use Design\Guard;
use Design\Http;
use Design\Media;
use Design\Upload;

$me = Guard::customer();
try {
    // Розмір — до CSRF: файл більший за post_max_size PHP відкидає разом із полем csrf
    $tmp = Upload::tmp('file', $_FILES, $_SERVER);
    if (!Guard::csrfOk($me)) Http::json(['error' => Guard::STALE_AJAX], 403);
    $name = Designers::setPhoto($me, $tmp);
} catch (DesignerException $e) {
    Http::json(['error' => $e->getMessage()], 422);
} catch (\Throwable $e) {
    error_log('design: фото профілю клієнта ' . $me . ': ' . $e);
    Http::json(['error' => 'Не вдалося зберегти фото — спробуйте ще раз'], 500);
}
Http::json(['photo' => Media::url($me, $name)]);
