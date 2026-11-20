<?php
// Лише для UploadPageTest: роутер вбудованого `php -S 127.0.0.1:…` (nginx тестів не віддає — location ^/tests/ deny).
// Виконує справжню pages/order-upload.php на справжньому multipart (move_uploaded_file працює, як на php-fpm).
// Сповіщення не йдуть в api2: Notify::$transport дописує в DESIGN_TEST_NOTIFY_LOG (JSON-рядок), що було видно в момент
// сповіщення: чи вже запамʼятовано відповідь, чи вільне блокування завантаження, чи віддано відповідь браузеру.
// Поле notify_sleep — скільки секунд «висить» api2; notify_throw=1 — api2 кидає виняток.
require dirname(__DIR__, 2) . '/helpers/Design/bootstrap.php';

putenv('DESIGN_NOTIFY=on');
$results = '/mnt/work/tmp/tmp_uppy/design-results/';
$meta = is_array($_POST['meta'] ?? null) ? $_POST['meta'] : [];
$key = sha1(Design\Auth::current() . ':' . (int)($_GET['id'] ?? 0) . ':' . (string)($_POST['purpose'] ?? '') . '|'
    . (string)($meta['fileId'] ?? ''));

Design\Notify::$transport = static function (array $params) use ($results, $key): array {
    $log = (string)getenv('DESIGN_TEST_NOTIFY_LOG');
    file_put_contents($log, json_encode(['event' => 'start']) . "\n", FILE_APPEND);
    $fh = fopen($results . $key . '.lock', 'c');
    $free = $fh !== false && flock($fh, LOCK_EX | LOCK_NB);
    if ($free) flock($fh, LOCK_UN);
    if ($fh !== false) fclose($fh);
    $remembered = json_decode((string)@file_get_contents($results . $key . '.json'), true);
    file_put_contents($log, json_encode([
        'event' => 'notify',
        'customer_id' => $params['customer_id'] ?? null,
        'lock_free' => $free,
        'remembered' => $remembered,
        'headers_sent' => headers_sent(),
    ], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
    $sleep = (int)($_POST['notify_sleep'] ?? 0);
    if ($sleep > 0) sleep($sleep);
    if (($_POST['notify_throw'] ?? '') === '1') throw new RuntimeException('api2 недоступний');
    return ['ok' => true];
};

$params = ['id' => (string)(int)($_GET['id'] ?? 0)];
require dirname(__DIR__, 2) . '/pages/order-upload.php';
