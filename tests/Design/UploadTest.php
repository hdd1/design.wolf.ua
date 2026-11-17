<?php

use Design\DesignerException;
use Design\Upload;
use PHPUnit\Framework\TestCase;

/** Помилки завантаження файлу: тексти для рядка файлу */
final class UploadTest extends TestCase
{
    private function message(array $files, array $server = []): string
    {
        try {
            Upload::tmp('file', $files, $server);
        } catch (DesignerException $e) {
            return $e->getMessage();
        }
        $this->fail('Очікувалась DesignerException');
    }

    private static function file(int $error): array
    {
        return ['file' => ['name' => 'a.jpg', 'type' => 'image/jpeg', 'tmp_name' => '', 'error' => $error, 'size' => 0]];
    }

    public function test_over_server_limit_reads_like_images_limit(): void
    {
        // upload_max_filesize: розмір файлу PHP не каже — беремо Content-Length
        $this->assertSame('Файл завеликий: 48 МБ. Максимум — 20 МБ',
            $this->message(self::file(UPLOAD_ERR_INI_SIZE), ['CONTENT_LENGTH' => (string)(48 * 1048576 - 1000)]));
        $this->assertSame('Файл завеликий: 21 МБ. Максимум — 20 МБ',
            $this->message(self::file(UPLOAD_ERR_FORM_SIZE), ['CONTENT_LENGTH' => (string)(20 * 1048576 + 5)]));
        $this->assertSame('Файл завеликий. Максимум — 20 МБ', $this->message(self::file(UPLOAD_ERR_INI_SIZE)));
    }

    public function test_over_post_max_size_drops_everything(): void
    {
        // post_max_size: PHP відкидає і файли, і поля — лишається лише Content-Length
        $this->assertSame('Файл завеликий: 120 МБ. Максимум — 20 МБ', $this->message([], ['CONTENT_LENGTH' => (string)(120 * 1048576)]));
        $this->assertSame('Файл не надіслано', $this->message([], ['CONTENT_LENGTH' => '0']));
        $this->assertSame('Файл не надіслано', $this->message([]));
    }

    public function test_other_client_errors(): void
    {
        $this->assertSame('Файл не надіслано', $this->message(self::file(UPLOAD_ERR_NO_FILE)));
        $this->assertSame('Файл завантажився не повністю — спробуйте ще раз', $this->message(self::file(UPLOAD_ERR_PARTIAL)));
        $this->assertSame('Файл не надіслано', $this->message(['file' => ['name' => ['a'], 'error' => [0]]]));   // file[]
        $this->assertSame('Файл не надіслано', $this->message(['file' => 'x']));
    }

    public function test_not_uploaded_file_rejected(): void
    {
        // tmp_name, якого PHP не приймав у цьому запиті, — не наш файл
        $files = ['file' => ['name' => 'a.jpg', 'type' => 'image/jpeg', 'tmp_name' => __FILE__, 'error' => UPLOAD_ERR_OK, 'size' => 10]];
        $this->assertSame('Файл не надіслано', $this->message($files));
    }

    public function test_server_side_failure_is_not_users_fault(): void
    {
        $this->expectException(RuntimeException::class);
        Upload::tmp('file', self::file(UPLOAD_ERR_NO_TMP_DIR), []);
    }
}
