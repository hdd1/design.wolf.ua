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
    private const RESULTS = self::TMP . 'design-results/';
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
    /** @var string[] ключі remember/recall, використані напряму */
    private array $keys = [];

    public static function setUpBeforeClass(): void
    {
        foreach (['/mnt/work/tmp', '/mnt/work/tmp/tmp_uppy', '/mnt/work/tmp/tmp_uppy/chunks', '/mnt/work/tmp/tmp_uppy/design-results'] as $dir) {
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
        $keys = $this->keys;
        foreach ($this->sent as [$scope, $fileId, $index]) {
            $keys[] = sha1($scope . '|' . $fileId);
            @unlink(self::TMP . 'chunks/' . md5(sha1($scope . '|' . $fileId)) . '.part' . $index);
        }
        foreach ($this->assembled as $path) @unlink($path);
        foreach (array_unique($keys) as $key) {
            @unlink(self::RESULTS . "$key.lock");
            @unlink(self::RESULTS . "$key.json");
        }
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
     * @param array $fields додаткові поля (remember)
     * @param array $files  додаткові файли: поле => байти (other)
     * @return array{0:int, 1:array} статус і JSON
     */
    private function send(string $scope, int $max, array $meta, ?string $bytes, array $fields = [], array $files = []): array
    {
        $ch = $this->request($scope, $max, $meta, $bytes, $fields, $files, $tmps);
        $body = (string)curl_exec($ch);
        return $this->response($ch, $body, $tmps);
    }

    /** Готовий curl-запит частини; $tmps — тимчасові файли, які прибирає response() */
    private function request(string $scope, int $max, array $meta, ?string $bytes, array $fields, array $files, ?array &$tmps)
    {
        $post = ['scope' => $scope, 'max' => (string)$max] + $fields;
        foreach ($meta as $k => $v) $post["meta[$k]"] = (string)$v;
        if ($bytes !== null) $files = ['file' => $bytes] + $files;
        $tmps = [];
        foreach ($files as $field => $content) {
            $tmp = $tmps[] = tempnam(sys_get_temp_dir(), 'chunk');
            file_put_contents($tmp, $content);
            $post[$field] = new CURLFile($tmp, 'application/octet-stream', 'blob');
        }
        $this->sent[] = [$scope, (string)$meta['fileId'], (int)$meta['chunkIndex']];
        $ch = curl_init('http://127.0.0.1:' . self::$port . '/');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
        return $ch;
    }

    /** @return array{0:int, 1:array} */
    private function response($ch, string $body, array $tmps): array
    {
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        foreach ($tmps as $tmp) unlink($tmp);
        $json = json_decode($body, true);
        $this->assertIsArray($json, "Не JSON: $body");
        if (!empty($json['path'])) $this->assembled[] = $json['path'];
        return [$code, $json];
    }

    /** Файли в tmp_uppy і chunks/ — щоб переконатися, що нічого не лишилось */
    private static function listing(): array
    {
        $files = array_filter(array_merge(glob(self::TMP . '*') ?: [], glob(self::TMP . 'chunks/*') ?: []), 'is_file');
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
        $this->assertSame(sha1("$scope|$id"), $json['key']);
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
        foreach (['0', '-1', 'x', '52'] as $bad) {   // 50 МБ: не більше ceil(50/1)+1 = 51 частини (частина в браузері — від 1 МБ)
            $this->assertSame(self::ERR_META, $this->error(self::meta(['totalChunks' => $bad])), "totalChunks $bad");
        }
        $this->assertSame(self::ERR_META, $this->error(self::meta(['totalSize' => 'x'])));
        // Від’ємні цілі (не з форми, а int) — так само
        $this->assertSame(self::ERR_META, $this->error(self::meta(['chunkIndex' => -1])));
        $this->assertSame(self::ERR_META, $this->error(self::meta(['totalChunks' => -1])));
        $this->assertSame(self::ERR_META, $this->error(self::meta(['totalSize' => -5])));
        $this->assertSame('Файл порожній', $this->error(self::meta(['totalSize' => '0'])));
        // 51 частина — ще можна (на dev upload_max_filesize 2M: частини ~1,9 МБ): далі перевіряється сама частина
        $this->assertSame('Файл не надіслано', $this->error(self::meta(['totalChunks' => '51', 'chunkIndex' => '50'])));
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

    public function test_rejects_size_mismatch_after_assembly(): void
    {
        $before = self::listing();
        $meta = ['fileId' => self::fileId(), 'totalChunks' => 2, 'totalSize' => 6, 'originalName' => 'a.pdf'];
        $this->send('29:1:layout', 50 * self::MB, ['chunkIndex' => 0] + $meta, 'AAA');
        // Заявлено 6 байтів, склеєно 5
        [$code, $json] = $this->send('29:1:layout', 50 * self::MB, ['chunkIndex' => 1] + $meta, 'BB');
        $this->assertSame([422, 'Файл пошкоджено під час завантаження — спробуйте ще раз'], [$code, $json['error'] ?? null]);
        $this->assertSame($before, self::listing());
    }

    public function test_retry_of_final_part_returns_remembered_result(): void
    {
        $scope = '29:1:preview';
        $meta = ['fileId' => self::fileId(), 'totalChunks' => 2, 'totalSize' => 6, 'originalName' => 'a.pdf'];
        $page = ['done' => true, 'preview' => ['id' => 7, 'num' => 'v1']];
        $this->send($scope, 50 * self::MB, ['chunkIndex' => 0] + $meta, 'AAA');
        [$code, $json] = $this->send($scope, 50 * self::MB, ['chunkIndex' => 1] + $meta, 'BBB', ['remember' => json_encode($page)]);
        $this->assertSame(200, $code);
        $this->assertTrue($json['done']);
        $this->assertArrayHasKey('path', $json);

        // Відповідь загубилась (CF 524) — браузер повторює останню частину: та сама відповідь сторінки, нічого не склеюється знову
        $before = self::listing();
        [$code, $json] = $this->send($scope, 50 * self::MB, ['chunkIndex' => 1] + $meta, 'BBB');
        $this->assertSame([200, ['done' => true, 'recalled' => $page]], [$code, $json]);
        $this->assertSame($before, self::listing());

        // Без remember (сторінка впала) повтор лише кладе частину — файл не збирається вдруге
        $meta['fileId'] = self::fileId();
        $this->send($scope, 50 * self::MB, ['chunkIndex' => 0] + $meta, 'AAA');
        $this->assertTrue($this->send($scope, 50 * self::MB, ['chunkIndex' => 1] + $meta, 'BBB')[1]['done']);
        $this->assertSame(['done' => false], $this->send($scope, 50 * self::MB, ['chunkIndex' => 1] + $meta, 'BBB')[1]);
    }

    public function test_remember_and_recall(): void
    {
        $key = $this->keys[] = sha1('test|' . bin2hex(random_bytes(8)));
        $this->assertNull(ChunkUpload::recall($key));
        ChunkUpload::remember($key, ['done' => true, 'layout' => ['id' => 5, 'label' => 'Візитка']]);
        $this->assertSame(['done' => true, 'layout' => ['id' => 5, 'label' => 'Візитка']], ChunkUpload::recall($key));
        // Повторно — те саме (повторів може бути кілька)
        $this->assertSame(['done' => true, 'layout' => ['id' => 5, 'label' => 'Візитка']], ChunkUpload::recall($key));
        $this->assertSame('0600', substr(sprintf('%o', fileperms(self::RESULTS . "$key.json")), -4));
        $this->assertSame('0700', substr(sprintf('%o', fileperms(self::RESULTS)), -4));

        // Старше години — видаляється при читанні
        touch(self::RESULTS . "$key.json", time() - 3601);
        $this->assertNull(ChunkUpload::recall($key));
        $this->assertFileDoesNotExist(self::RESULTS . "$key.json");

        $this->expectException(InvalidArgumentException::class);
        ChunkUpload::recall('../../etc/passwd');
    }

    public function test_remember_rejects_unencodable_result_and_releases_lock(): void
    {
        $key = $this->keys[] = sha1('test|' . bin2hex(random_bytes(8)));
        try {
            ChunkUpload::remember($key, ['error' => "\xff\xfe"]);
            $this->fail('Очікувався JsonException');
        } catch (JsonException) {
        }
        $this->assertFileDoesNotExist(self::RESULTS . "$key.json");
        // Блокування знято: інший власник бере його одразу
        $fh = fopen(self::RESULTS . "$key.lock", 'c');
        $this->assertTrue(flock($fh, LOCK_EX | LOCK_NB));
        fclose($fh);
    }

    public function test_part_size_follows_php_limits(): void
    {
        $this->assertSame(2097152, ChunkUpload::iniBytes('2M'));
        $this->assertSame(524288, ChunkUpload::iniBytes('512k'));
        $this->assertSame(1073741824, ChunkUpload::iniBytes('1G'));
        $this->assertSame(8388608, ChunkUpload::iniBytes('8388608'));
        $this->assertSame(0, ChunkUpload::iniBytes('0'));
        $this->assertSame(0, ChunkUpload::iniBytes('lots'));
        $expect = ChunkUpload::CHUNK;
        foreach (['upload_max_filesize', 'post_max_size'] as $ini) {
            $v = ChunkUpload::iniBytes((string)ini_get($ini));
            if ($v > 0) $expect = min($expect, $v);
        }
        $this->assertSame($expect - 65536, ChunkUpload::partSize());
        $this->assertLessThanOrEqual(ChunkUpload::CHUNK - 65536, ChunkUpload::partSize());
    }

    public function test_lock_wait_is_bounded(): void
    {
        $key = $this->keys[] = sha1('test|' . bin2hex(random_bytes(8)));
        ChunkUpload::recall($key);   // каталог результатів
        $fh = fopen(self::RESULTS . "$key.lock", 'c');
        $this->assertTrue(flock($fh, LOCK_EX));
        $old = ChunkUpload::$lockWait;
        ChunkUpload::$lockWait = 1;
        $started = microtime(true);
        try {
            ChunkUpload::recall($key);
            $this->fail('Очікувалась DesignerException');
        } catch (DesignerException $e) {
            $this->assertSame('Сервер зайнятий — спробуйте ще раз', $e->getMessage());
            $this->assertSame(503, $e->getCode());
        } finally {
            ChunkUpload::$lockWait = $old;
            flock($fh, LOCK_UN);
            fclose($fh);
        }
        $this->assertGreaterThanOrEqual(0.9, microtime(true) - $started);
        $this->assertLessThan(5, microtime(true) - $started);
        $this->assertNull(ChunkUpload::recall($key), 'після звільнення — звичайна робота');
    }

    public function test_concurrent_request_with_same_id_waits_for_lock(): void
    {
        $scope = '29:1:layout';
        $meta = ['fileId' => self::fileId(), 'totalChunks' => 2, 'totalSize' => 6, 'originalName' => 'a.pdf'];
        $key = sha1($scope . '|' . $meta['fileId']);
        $this->send($scope, 50 * self::MB, ['chunkIndex' => 0] + $meta, 'AAA');

        // Інший запит із тим самим id (перша спроба ще обробляє файл) тримає блокування
        $lock = fopen(self::RESULTS . "$key.lock", 'c');
        $this->assertTrue(flock($lock, LOCK_EX));
        $ch = $this->request($scope, 50 * self::MB, ['chunkIndex' => 1] + $meta, 'BBB', [], [], $tmps);
        $mh = curl_multi_init();
        curl_multi_add_handle($mh, $ch);
        $started = microtime(true);
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh, 0.1);
        } while ($running && microtime(true) - $started < 1.0);
        $this->assertSame(1, $running, 'Запит не чекав на блокування');

        flock($lock, LOCK_UN);
        fclose($lock);
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh, 0.1);
        } while ($running && microtime(true) - $started < 30);
        $body = (string)curl_multi_getcontent($ch);
        curl_multi_remove_handle($mh, $ch);
        curl_multi_close($mh);
        [$code, $json] = $this->response($ch, $body, $tmps);
        $this->assertSame(200, $code, $body);
        $this->assertTrue($json['done']);
        $this->assertSame('AAABBB', file_get_contents($json['path']));
    }

    public function test_files_must_be_the_global_files(): void
    {
        // receive() перевірив би одну частину, а UppyChunkGlobal узяв би іншу з $_FILES — помилка програміста
        [$code, $json] = $this->send('29:1:layout', 50 * self::MB,
            ['fileId' => self::fileId(), 'chunkIndex' => 0, 'totalChunks' => 1, 'totalSize' => 3, 'originalName' => 'a.pdf'], 'abc', [], ['other' => 'xyz']);
        $this->assertSame(500, $code);
        $this->assertStringStartsWith('LogicException: ', $json['error']);
    }
}
