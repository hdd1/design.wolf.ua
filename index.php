<?php
/** design.wolf.ua: входной файл */
require __DIR__ . '/helpers/Design/bootstrap.php';

use Design\Http;
use Design\Router;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'HEAD') $method = 'GET';

$route = Router::match($method, $path);
if ($route === null) {
    if ($method === 'GET' && ($to = Router::withSlash($path)) !== null) Http::redirect($to, 301);
    Http::notFound();
}

$handler = __DIR__ . '/pages/' . $route['page'] . '.php';
if (!is_file($handler)) Http::notFound();

Http::noStore();
$params = $route['params'];
require $handler;
