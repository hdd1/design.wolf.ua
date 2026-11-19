<?php
// Лише для ChunkUploadTest: роутер вбудованого `php -S 127.0.0.1:…` (nginx тестів не віддає — location ^/tests/ deny).
// Справжній multipart-запит, тож move_uploaded_file у \UppyChunkGlobal працює як на php-fpm.
require dirname(__DIR__, 2) . '/helpers/Design/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
try {
    $result = Design\ChunkUpload::receive($_POST, $_FILES, (string)($_POST['scope'] ?? ''), (int)($_POST['max'] ?? 0));
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Design\DesignerException $e) {
    http_response_code(422);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => get_class($e) . ': ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
