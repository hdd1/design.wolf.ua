<?php

use Design\ChunkUpload;
use Design\DesignerException;
use PHPUnit\Framework\TestCase;

/**
 * Приймання файлу частинами.
 * Перевірки до \UppyChunkGlobal — прямим викликом у CLI (до склеювання справа не доходить: is_uploaded_file у CLI завжди false).
 * Склеювання — справжнім multipart через вбудований `php -S` з tests/fixtures/chunk-receive.php:
 * move_uploaded_file у CLI не підробити, а UppyChunkGlobal працює з глобальним $_FILES. Каталог — справжній /mnt/work/tmp/tmp_uppy;
 * усе створене тест прибирає (частини, склеєні файли й каталоги, яких до тесту не було).
 */
final class ChunkUploadTest extends TestCase
{
    private const TMP = '/mnt/work/tmp/tmp_uppy/';
    private const MB = 1048576;
    private const ERR_META = 'Невірні дані завантаження. Оновіть сторінку і спробуйте ще раз';

    /** @var resource|null */
    private static $server = null;
    private static int $port = 0;
    /** @var string[] каталоги, яких не було до тестів — прибрати, якщо порожні */
    private static array $newDirs = [];

    /** @var array<int, array{string,string,int}> відправлені частини: scope, fileId, chunkIndex */
    private array $sent = [];
    /** @var string[] склеєні файли */
    private array $assembled = [];

    public static function setUpBeforeClass(): void
    {
        foreach (['/mnt/work/tmp', '/mnt/work/tmp/tmp_uppy', '/mnt/work/tmp/tmp_uppy/chunks'] as $dir) {
            if (!is_dir($dir)) self::$newDirs[] = $dir;
        }
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::$port = (int)substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);
        $null = ['file', '/dev/null', 'w'];
        self::$server = proc_open(
            [PHP_BINARY, '-d', 'upload_max_filesize=8M', '-d', 'post_max_size=16M', '-S', '127.0.0.1:' . self::$port, 'chunk-receive.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => $null, 2 => $null], $pipes, dirname(__DIR__) . '/fixtures'
        );
        for ($i = 0; $i < 100; $i++) {
            $s = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.1);
            if ($s) {
                fclose($s);
                return;
            }
            usleep(50000);
        }
        self::fail('Не стартував php -S');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        foreach (array_reverse(self::$newDirs) as $dir) @rmdir($dir);
    }

    protected function tearDown(): void
    {
        foreach ($this->sent as [$scope, $fileId, $index]) {
            @unlink(self::TMP . 'chunks/' . md5(sha1($scope . '|' . $fileId)) . '.part' . $index);
        }
        foreach ($this->assembled as $path) @unlink($path);
    }

    private static function fileId(): string
    {
        return 'test' . bin2hex(random_bytes(8));
    }

    private static function meta(array $over = []): array
    {
        return ['meta' => $over + ['fileId' => 'abcdefgh12', 'chunkIndex' => '0', 'totalChunks' => '1', 'totalSize' => '10', 'originalName' => 'a.pdf']];
    }

    /** Помилка receive у CLI — лише перевірки до склеювання */
    private function error(array $post, array $files = [], int $max = 50 * self::MB): string
    {
        try {
            ChunkUpload::receive($post, $files, '29:1:layout', $max);
        } catch (DesignerException $e) {
            return $e->getMessage();
        }
        $this->fail('Очікувалась DesignerException');
    }

    /**
     * Частина через php -S
     * @return array{0:int, 1:array} статус і JSON
     */
    private function send(string $scope, int $max, array $meta, ?string $bytes, string $field = 'file'): array
    {
        $post = ['scope' => $scope, 'max' => (string)$max];
        foreach ($meta as $k => $v) $post["meta[$k]"] = (string)$v;
        $tmp = null;
        if ($bytes !== null) {
            $tmp = tempnam(sys_get_temp_dir(), 'chunk');
            file_put_contents($tmp, $bytes);
            $post[$field] = new CURLFile($tmp, 'application/octet-stream', 'blob');
        }
        $this->sent[] = [$scope, (string)$meta['fileId'], (int)$meta['chunkIndex']];
        $ch = curl_init('http://127.0.0.1:' . self::$port . '/');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
        $body = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($tmp !== null) unlink($tmp);
        $json = json_decode($body, true);
        $this->assertIsArray($json, "Не JSON: $body");
        if (!empty($json['path'])) $this->assembled[] = $json['path'];
        return [$code, $json];
    }

    /** Файли в tmp_uppy і chunks/ — щоб переконатися, що нічого не лишилось */
    private static function listing(): array
    {
        $files = array_merge(glob(self::TMP . '*') ?: [], glob(self::TMP . 'chunks/*') ?: []);
        sort($files);
        return $files;
    }

    public function test_assembles_and_returns_path(): void
    {
        $id = self::fileId();
        $parts = ['перша-', 'друга-', 'третя'];
        $size = strlen(implode('', $parts));
        $scope = '29:1:layout';
        foreach ($parts as $i => $bytes) {
            [$code, $json] = $this->send($scope, 50 * self::MB,
                ['fileId' => $id, 'chunkIndex' => $i, 'totalChunks' => 3, 'totalSize' => $size, 'originalName' => 'Візитка 1.PDF'], $bytes);
            $this->assertSame(200, $code, json_encode($json, JSON_UNESCAPED_UNICODE));
            if ($i < 2) $this->assertSame(['done' => false], $json);
        }
        $this->assertTrue($json['done']);
        $this->assertSame('Візитка 1.PDF', $json['name']);
        $this->assertSame($size, $json['size']);
        $this->assertStringStartsWith(self::TMP, $json['path']);
        $this->assertStringEndsWith('.pdf', $json['path']);
        $this->assertSame(implode('', $parts), file_get_contents($json['path']));
        // Частини UppyChunkGlobal прибрав сам
        foreach ([0, 1, 2] as $i) $this->assertFileDoesNotExist(self::TMP . 'chunks/' . md5(sha1("$scope|$id")) . ".part$i");
    }

    public function test_rejects_bad_file_id_and_counts(): void
    {
        foreach (['short7_', str_repeat('a', 65), 'abc def gh', 'abcdefgh/1', "abcdefgh1\n", ['abcdefgh1']] as $bad) {
            $this->assertSame(self::ERR_META, $this->error(self::meta(['fileId' => $bad])), var_export($bad, true));
        }
        $this->assertSame(self::ERR_META, $this->error(['meta' => ['chunkIndex' => '0', 'totalChunks' => '1', 'totalSize' => '10']]));
        $this->assertSame(self::ERR_META, $this->error([]));
        $this->assertSame(self::ERR_META, $this->error(['meta' => 'x']));
        foreach (['-1', '1.5', 'a', '', ' 1', '1'] as $bad) {   // '1' — індекс поза межами при totalChunks=1
            $this->assertSame(self::ERR_META, $this->error(self::meta(['chunkIndex' => $bad])), "chunkIndex $bad");
        }
        foreach (['0', '-1', 'x', '12'] as $bad) {   // 50 МБ: не більше ceil(50/5)+1 = 11 частин
            $this->assertSame(self::ERR_META, $this->error(self::meta(['totalChunks' => $bad])), "totalChunks $bad");
        }
        $this->assertSame(self::ERR_META, $this->error(self::meta(['totalSize' => 'x'])));
        $this->assertSame('Файл порожній', $this->error(self::meta(['totalSize' => '0'])));
        // 11 частин — ще можна: далі перевіряється сама частина
        $this->assertSame('Файл не надіслано', $this->error(self::meta(['totalChunks' => '11', 'chunkIndex' => '10'])));
        // Межа рахується від ліміту: 1 МБ — до 2 частин
        $this->assertSame(self::ERR_META, $this->error(self::meta(['totalChunks' => '3']), [], self::MB));
    }

    public function test_rejects_missing_or_foreign_part(): void
    {
        $this->assertSame('Файл не надіслано', $this->error(self::meta()));
        $file = ['name' => 'blob', 'type' => 'application/octet-stream', 'tmp_name' => __FILE__, 'error' => UPLOAD_ERR_OK, 'size' => 10];
        // Файл, якого PHP не приймав у цьому запиті, — не наш
        $this->assertSame('Файл не надіслано', $this->error(self::meta(), ['file' => $file]));
        $this->assertSame('Файл не надіслано', $this->error(self::meta(), ['file' => ['error' => [0]]]));
        $this->assertSame('Файл завантажився не повністю — спробуйте ще раз',
            $this->error(self::meta(), ['file' => ['error' => UPLOAD_ERR_PARTIAL] + $file]));
        $this->assertSame('Частина файлу завелика. Оновіть сторінку і спробуйте ще раз',
            $this->error(self::meta(), ['file' => ['error' => UPLOAD_ERR_INI_SIZE] + $file]));
        // «files» UppyChunkGlobal читає першим — приймаємо лише «file»
        $this->assertSame(self::ERR_META, $this->error(self::meta(), ['files' => $file, 'file' => $file]));
    }

    public function test_rejects_part_over_5_mb(): void
    {
        $limit = 5 * self::MB + 65536;
        [$code, $json] = $this->send('29:1:layout', 50 * self::MB,
            ['fileId' => self::fileId(), 'chunkIndex' => 0, 'totalChunks' => 2, 'totalSize' => 6 * self::MB, 'originalName' => 'a.pdf'],
            str_repeat('x', $limit + 1));
        $this->assertSame(422, $code);
        $this->assertSame('Частина файлу завелика. Оновіть сторінку і спробуйте ще раз', $json['error']);

        // Рівно межа — приймається
        [$code, $json] = $this->send('29:1:layout', 50 * self::MB,
            ['fileId' => self::fileId(), 'chunkIndex' => 0, 'totalChunks' => 1, 'totalSize' => $limit, 'originalName' => 'a.pdf'],
            str_repeat('x', $limit));
        $this->assertSame(200, $code, json_encode($json, JSON_UNESCAPED_UNICODE));
        $this->assertSame($limit, $json['size']);
    }

    public function test_rejects_declared_too_big(): void
    {
        $this->assertSame('Файл завеликий: 51 МБ. Максимум — 50 МБ', $this->error(self::meta(['totalSize' => (string)(50 * self::MB + 1)])));
        // На будь-якій частині, не лише на першій
        $this->assertSame('Файл завеликий: 2048 МБ. Максимум — 1024 МБ',
            $this->error(self::meta(['totalSize' => (string)(2048 * self::MB), 'chunkIndex' => '7', 'totalChunks' => '205']), [], 1024 * self::MB));
    }

    public function test_rejects_actual_too_big_after_assembly(): void
    {
        $before = self::listing();
        $id = self::fileId();
        $part = str_repeat('x', 600 * 1024);
        // Заявлено 1 МБ, надіслано 2 × 600 КБ
        $meta = ['fileId' => $id, 'chunkIndex' => 0, 'totalChunks' => 2, 'totalSize' => self::MB, 'originalName' => 'a.pdf'];
        [$code, $json] = $this->send('29:1:preview', self::MB, $meta, $part);
        $this->assertSame([200, ['done' => false]], [$code, $json]);
        [$code, $json] = $this->send('29:1:preview', self::MB, ['chunkIndex' => 1] + $meta, $part);
        $this->assertSame(422, $code);
        $this->assertSame('Файл завеликий: 2 МБ. Максимум — 1 МБ', $json['error']);
        // Склеєний файл видалено, частин не лишилось
        $this->assertSame($before, self::listing());
    }

    public function test_scope_isolates_same_file_id(): void
    {
        $id = self::fileId();
        $meta = ['fileId' => $id, 'totalChunks' => 2, 'totalSize' => 6, 'originalName' => 'a.txt'];
        [, $json] = $this->send('29:1:layout', 50 * self::MB, ['chunkIndex' => 0] + $meta, 'AAA');
        $this->assertSame(['done' => false], $json);
        // Той самий fileId в іншому замовленні не дописує чужий файл
        [, $json] = $this->send('29:2:layout', 50 * self::MB, ['chunkIndex' => 1] + $meta, 'BBB');
        $this->assertSame(['done' => false], $json);
        [, $json] = $this->send('29:1:layout', 50 * self::MB, ['chunkIndex' => 1] + $meta, 'CCC');
        $this->assertTrue($json['done']);
        $this->assertSame('AAACCC', file_get_contents($json['path']));
    }

    public function test_name_is_sanitized(): void
    {
        $this->assertSame('макет.pdf', ChunkUpload::name('C:\\Users\\me\\макет.pdf'));
        $this->assertSame('passwd', ChunkUpload::name('../../etc/passwd'));
        $this->assertSame('ab.pdf', ChunkUpload::name("a\x00\x1F\x7Fb.pdf"));
        $this->assertSame('fdp.exe', ChunkUpload::name("\u{202E}fdp.exe"));   // RTL-перевертень
        $this->assertSame('file', ChunkUpload::name('..'));
        $this->assertSame('file', ChunkUpload::name("  \x01 "));
        $this->assertSame('a?b', ChunkUpload::name("a\xFFb"));   // битий UTF-8
        $long = str_repeat('я', 300) . '.pdf';
        $this->assertSame(str_repeat('я', 255), ChunkUpload::name($long));

        // Відповідь сервера — вже очищене імʼя; розширення тимчасового файлу — безпечне
        [, $json] = $this->send('29:1:layout', 50 * self::MB,
            ['fileId' => self::fileId(), 'chunkIndex' => 0, 'totalChunks' => 1, 'totalSize' => 3, 'originalName' => "dir/../x\ty.p h"], 'abc');
        $this->assertTrue($json['done']);
        $this->assertSame('xy.p h', $json['name']);
        $this->assertMatchesRegularExpression('~^' . self::TMP . '[0-9a-f]{32}\.bin$~', $json['path']);
    }
}
