<?php
/** @var array $params */
use Design\Auth;
use Design\Http;
use Design\Media;

$designerId = (int)$params['designer'];
$name = $params['name'];

// Гість теж може прийти: з підписом від adminka. Тому Auth::current(), а не Guard::customer()
if (!Media::canView(Auth::current(), $designerId, $name, $_GET)) Http::notFound();
$file = Media::path($designerId, $name);
if (!is_file($file)) Http::notFound();

// Перезаписує private, no-store з index.php: картинка незмінна під своїм імʼям
header('Content-Type: image/webp');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=86400');
header('Content-Length: ' . filesize($file));
readfile($file);
