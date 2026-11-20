<?php
// Лише для ChunkUploadTest: роутер вбудованого `php -S 127.0.0.1:…` (nginx тестів не віддає — location ^/tests/ deny).
// Справжній multipart-запит, тож move_uploaded_file у \UppyChunkGlobal працює як на php-fpm.
//   remember — JSON, який «сторінка» запамʼятовує після склеювання (як order-upload.php у Task 6)
//   other    — файл, що йде в receive() замість «file» ($files ≠ $_FILES)
require dirname(__DIR__, 2) . '/helpers/Design/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
try {
    $files = isset($_FILES['other']) ? ['file' => $_FILES['other']] : $_FILES;
    $result = Design\ChunkUpload::receive($_POST, $files, (string)($_POST['scope'] ?? ''), (int)($_POST['max'] ?? 0));
    if (!empty($result['key']) && isset($_POST['remember'])) {
        Design\ChunkUpload::remember($result['key'], json_decode($_POST['remember'], true));
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Design\DesignerException $e) {
    http_response_code(422);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => get_class($e) . ': ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
