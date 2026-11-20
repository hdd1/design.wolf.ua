<?php

use Design\Csrf;
use Design\Designers;
use Design\DesignerStatus;
use Design\Layouts;
use Design\OfferStatus;
use Design\OrderStatus;
use Design\Products;
use Design\Sql;
use PHPUnit\Framework\TestCase;

/**
 * Кінець останньої частини в pages/order-upload.php: спершу remember (і зняти блокування), потім відповідь браузеру, і лише
 * тоді сповіщення клієнту (api2 через CalcApiGlobal без тайм-ауту). Справжня сторінка на справжньому multipart — через
 * вбудований `php -S` з tests/fixtures/upload-page.php (кілька воркерів, щоб повтор частини йшов паралельно з «зависанням»
 * api2); сповіщення ловить Notify::$transport фікстури й пише в журнал, що бачив у ту мить.
 * Клієнт — DESIGN_TEST_CUSTOMER_ID (29), дизайнер — DESIGN_TEST_DESIGNER_ID (25; є профіль дизайнера — пропуск).
 * DESIGN_WORK_ROOT — тимчасова папка; відповіді й частини в /mnt/work/tmp/tmp_uppy і каталоги, яких не було, — прибираються
 * @group db
 */
final class UploadPageTest extends TestCase
{
    private const UA = 'DesignUploadPageTest/1';
    private const UPPY = '/mnt/work/tmp/tmp_uppy/';

    /** @var resource|null */
    private static $server = null;
    private static int $port = 0;
    private static string $root = '';
    private static string $log = '';
    private static string|false $oldRoot = false;
    /** @var list<string> */
    private static array $newDirs = [];

    /** @var list<int> */
    private array $orders = [];
    /** @var list<string> */
    private array $keys = [];
    private bool $ownsDesigner = false;

    public static function setUpBeforeClass(): void
    {
        foreach (['/mnt/work/tmp', '/mnt/work/tmp/tmp_uppy', self::UPPY . 'chunks', self::UPPY . 'design-results'] as $dir) {
            if (!is_dir($dir)) self::$newDirs[] = $dir;
        }
        self::$root = sys_get_temp_dir() . '/design-upload-page-' . bin2hex(random_bytes(4));
        mkdir(self::$root, 0777, true);
        self::$log = self::$root . '/notify.log';
        self::$oldRoot = getenv('DESIGN_WORK_ROOT');
        // Сервер успадковує середовище тесту: та сама тимчасова папка, журнал сповіщень, кілька воркерів
        putenv('DESIGN_WORK_ROOT=' . self::$root);
        putenv('DESIGN_TEST_NOTIFY_LOG=' . self::$log);
        putenv('PHP_CLI_SERVER_WORKERS=3');
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::$port = (int)substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);
        $null = ['file', '/dev/null', 'w'];
        self::$server = proc_open(
            [PHP_BINARY, '-d', 'upload_max_filesize=8M', '-d', 'post_max_size=16M', '-S', '127.0.0.1:' . self::$port, 'upload-page.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => $null, 2 => $null], $pipes, dirname(__DIR__) . '/fixtures'
        );
        putenv('PHP_CLI_SERVER_WORKERS');
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
            // Воркери php -S — дочірні процеси: зупиняємо всю групу
            $pid = proc_get_status(self::$server)['pid'] ?? 0;
            if ($pid > 0) exec('pkill -TERM -P ' . (int)$pid);
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        putenv(self::$oldRoot === false ? 'DESIGN_WORK_ROOT' : 'DESIGN_WORK_ROOT=' . self::$oldRoot);
        putenv('DESIGN_TEST_NOTIFY_LOG');
        exec('rm -rf ' . escapeshellarg(self::$root));
        foreach (array_reverse(self::$newDirs) as $dir) {
            if (is_dir($dir) && count(scandir($dir)) === 2) @rmdir($dir);
        }
    }

    protected function setUp(): void
    {
        @unlink(self::$log);
    }

    protected function tearDown(): void
    {
        if ($this->orders) {
            $in = implode(',', array_map('intval', $this->orders));
            Sql::exec("DELETE FROM design_files WHERE order_id IN ($in)");
            Sql::exec("DELETE m FROM design_messages m JOIN design_offers f ON f.id = m.offer_id WHERE f.order_id IN ($in)");
            Sql::exec("DELETE FROM design_offers WHERE order_id IN ($in)");
            Sql::exec("DELETE FROM design_orders WHERE id IN ($in)");
            $this->orders = [];
        }
        foreach ($this->keys as $key) {
            @unlink(self::UPPY . "design-results/$key.json");
            @unlink(self::UPPY . "design-results/$key.lock");
            foreach (glob(self::UPPY . 'chunks/' . md5($key) . '.part*') ?: [] as $p) @unlink($p);
        }
        $this->keys = [];
        if ($this->ownsDesigner) {
            Sql::exec('DELETE FROM design_designers WHERE customer_id = ?', [self::id('DESIGN_TEST_DESIGNER_ID')]);
            $this->ownsDesigner = false;
        }
    }

    private static function id(string $env): int
    {
        $id = (int)getenv($env);
        if ($id <= 0) self::markTestSkipped("$env не задан");
        return $id;
    }

    /** Замовлення клієнта «В роботі» з обраним дизайнером 25 (стає схваленим на час тесту) → [orderId, designerId, clientId] */
    private function inWork(): array
    {
        $client = self::id('DESIGN_TEST_CUSTOMER_ID');
        $designer = self::id('DESIGN_TEST_DESIGNER_ID');
        if (!$this->ownsDesigner) {
            if (Designers::get($designer) !== null) {
                $this->markTestSkipped("у клієнта $designer вже є профіль дизайнера — тест не чіпає справжні дані");
            }
            Sql::insert('design_designers', ['customer_id' => $designer, 'status' => DesignerStatus::APPROVED, 'name' => 'Тест Дизайнер']);
            $this->ownsDesigner = true;
        }
        $orderId = Sql::insert('design_orders', [
            'customer_id' => $client, 'product_id' => Products::visible()[0]['id'], 'mode' => 1,
            'status' => OrderStatus::IN_WORK, 'title' => 'Тест сповіщень', 'answers' => '[]',
        ]);
        $this->orders[] = $orderId;
        $offerId = Sql::insert('design_offers', [
            'order_id' => $orderId, 'designer_id' => $designer, 'status' => OfferStatus::CHOSEN,
            'text' => 'Зроблю', 'price_designer' => 1000, 'markup_percent' => 20, 'price_client' => 1200,
        ]);
        Sql::exec('UPDATE design_orders SET chosen_offer_id = ?, chosen_at = NOW() WHERE id = ?', [$offerId, $orderId]);
        return [$orderId, $designer, $client];
    }

    /** Готовий curl-запит частини від дизайнера; $tmp — файл частини (прибирає виклик) */
    private function request(int $orderId, int $as, array $meta, string $bytes, array $fields, ?string &$tmp)
    {
        $this->keys[] = sha1("$as:$orderId:" . $fields['purpose'] . '|' . $meta['fileId']);
        $tmp = tempnam(sys_get_temp_dir(), 'upage');
        file_put_contents($tmp, $bytes);
        $post = $fields + ['csrf' => Csrf::token($as)];
        foreach ($meta as $k => $v) $post["meta[$k]"] = (string)$v;
        $post['file'] = new CURLFile($tmp, 'application/octet-stream', 'blob');
        $pass = (string)Sql::value('SELECT customer_pass FROM customers WHERE id = ?', [$as]);
        $ch = curl_init('http://127.0.0.1:' . self::$port . '/?id=' . $orderId);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => self::UA,
            CURLOPT_HTTPHEADER => ['Cookie: wolfid=' . $as . '.' . hash_hmac('md5', $pass . self::UA, (string)$as)],
        ]);
        return $ch;
    }

    /** @return array{0:int, 1:array} */
    private function part(int $orderId, int $as, array $meta, string $bytes, array $fields): array
    {
        $ch = $this->request($orderId, $as, $meta, $bytes, $fields, $tmp);
        $body = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        unlink($tmp);
        $json = json_decode($body, true);
        $this->assertIsArray($json, "Не JSON: $body");
        return [$code, $json];
    }

    /** @return list<array> записи журналу сповіщень фікстури */
    private static function log(): array
    {
        $lines = is_file(self::$log) ? file(self::$log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
        return array_map(static fn(string $l): array => json_decode($l, true), $lines);
    }

    private static function meta(string $bytes, string $name): array
    {
        return ['fileId' => 'test' . bin2hex(random_bytes(8)), 'totalChunks' => 2, 'totalSize' => strlen($bytes), 'originalName' => $name];
    }

    /**
     * api2 «висить» 3 с на сповіщенні: у цю мить відповідь уже запамʼятовано, блокування вільне, відповідь віддано.
     * Повтор останньої частини (браузер не дочекався) — одразу та сама відповідь, без 503 і без дубля макета
     */
    public function test_notify_after_remember_unlock_and_response(): void
    {
        [$orderId, $designer, $client] = $this->inWork();
        $bytes = random_bytes(3000);
        $meta = self::meta($bytes, 'Візитка.pdf');
        $fields = ['purpose' => 'layout', 'notify_sleep' => '3'];
        [$code, $json] = $this->part($orderId, $designer, ['chunkIndex' => 0] + $meta, substr($bytes, 0, 1500), $fields);
        $this->assertSame([200, ['done' => false]], [$code, $json]);

        // Остання частина — паралельно; чекаємо, поки сторінка дійде до сповіщення
        $last = ['chunkIndex' => 1] + $meta;
        $multi = curl_multi_init();
        $ch = $this->request($orderId, $designer, $last, substr($bytes, 1500), $fields, $tmp);
        curl_multi_add_handle($multi, $ch);
        $until = microtime(true) + 15;
        // Запит дочитується в будь-якому разі — інакше після збою перевірки він дописав би відповідь уже після tearDown
        $drain = static function () use ($multi, $until): void {
            do {
                curl_multi_exec($multi, $running);
                curl_multi_select($multi, 0.05);
            } while ($running && microtime(true) < $until);
        };
        try {
            $this->checkWhileNotifying($multi, $until, $orderId, $designer, $client, $last, substr($bytes, 1500), $fields, $remembered);
        } finally {
            $drain();
        }
        $first = json_decode((string)curl_multi_getcontent($ch), true);
        $firstCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);
        curl_multi_close($multi);
        unlink($tmp);
        $this->assertSame([200, $remembered['body']], [$firstCode, $first]);

        $this->assertSame(1, (int)Sql::value('SELECT COUNT(*) FROM design_files WHERE order_id = ?', [$orderId]), 'без дубля макета');
        $this->assertCount(1, array_filter(self::log(), static fn(array $e): bool => $e['event'] === 'notify'), 'одне сповіщення');
    }

    /** Поки сторінка «висить» на сповіщенні: що вона бачила в цю мить, і повтор останньої частини */
    private function checkWhileNotifying($multi, float $until, int $orderId, int $designer, int $client, array $last, string $bytes,
                                         array $fields, ?array &$remembered): void
    {
        do {
            curl_multi_exec($multi, $running);
            curl_multi_select($multi, 0.05);
            $notified = array_values(array_filter(self::log(), static fn(array $e): bool => $e['event'] === 'notify'));
        } while (!$notified && microtime(true) < $until);
        $this->assertCount(1, $notified, 'сторінка не дійшла до сповіщення');
        $this->assertTrue($notified[0]['lock_free'], 'на час сповіщення блокування завантаження вже знято');
        $this->assertTrue($notified[0]['headers_sent'], 'відповідь віддано до сповіщення');
        $this->assertSame($client, $notified[0]['customer_id']);
        $remembered = $notified[0]['remembered'];
        $this->assertSame(200, $remembered['status'] ?? null, 'відповідь запамʼятовано до сповіщення');
        $this->assertTrue($remembered['body']['done']);
        $this->assertSame('Візитка', $remembered['body']['layout']['label']);
        $this->assertArrayNotHasKey('notice', $remembered['body']['layout'], 'notice не йде в JSON');

        // Повтор останньої частини, поки api2 «висить», — та сама відповідь одразу, без очікування блокування
        $t = microtime(true);
        [$code, $retry] = $this->part($orderId, $designer, $last, $bytes, $fields);
        $this->assertLessThan(2.0, microtime(true) - $t, 'повтор не чекав сповіщення');
        $this->assertSame([200, $remembered['body']], [$code, $retry]);
    }

    /** api2 кидає виняток — відповідь та сама {done:true, layout}, повтор отримує її ж */
    public function test_throwing_notify_does_not_change_response(): void
    {
        [$orderId, $designer] = $this->inWork();
        $bytes = random_bytes(2000);
        $meta = self::meta($bytes, 'Флаєр.ai');
        $fields = ['purpose' => 'layout', 'notify_throw' => '1'];
        $this->part($orderId, $designer, ['chunkIndex' => 0] + $meta, substr($bytes, 0, 1000), $fields);
        [$code, $json] = $this->part($orderId, $designer, ['chunkIndex' => 1] + $meta, substr($bytes, 1000), $fields);
        $this->assertSame(200, $code, json_encode($json, JSON_UNESCAPED_UNICODE));
        $this->assertTrue($json['done']);
        $this->assertSame(['id', 'label', 'original_name', 'size', 'ext', 'created', 'purchased'], array_keys($json['layout']));
        $this->assertSame('Флаєр', $json['layout']['label']);
        $this->assertCount(1, array_filter(self::log(), static fn(array $e): bool => $e['event'] === 'notify'), 'сповіщення було');
        $this->assertSame([200, $json], $this->part($orderId, $designer, ['chunkIndex' => 1] + $meta, substr($bytes, 1000), $fields));
        $this->assertSame(1, Layouts::count($orderId));
    }

    /**
     * Помилка обробки після склеювання — {error, final:true}: файл уже спожито, браузер не повторює частину;
     * повтор отримує ту саму відповідь з тим самим кодом, сповіщення немає
     */
    public function test_processing_error_is_final_and_remembered(): void
    {
        [$orderId, $designer] = $this->inWork();
        $old = random_bytes(1000);
        $meta = self::meta($old, 'Стара.pdf');
        $this->part($orderId, $designer, ['chunkIndex' => 0] + $meta, substr($old, 0, 500), ['purpose' => 'layout']);
        [, $json] = $this->part($orderId, $designer, ['chunkIndex' => 1] + $meta, substr($old, 500), ['purpose' => 'layout']);
        $fileId = $json['layout']['id'];
        @unlink(self::$log);

        $new = random_bytes(1000);
        $meta = self::meta($new, 'Нова.pdf');
        $fields = ['purpose' => 'replace', 'file_id' => (string)$fileId];
        [$code] = $this->part($orderId, $designer, ['chunkIndex' => 0] + $meta, substr($new, 0, 500), $fields);
        $this->assertSame(200, $code);
        // Макет видалили в іншій вкладці між частинами
        Sql::exec('UPDATE design_files SET status = ? WHERE id = ?', [Layouts::DELETED, $fileId]);
        $expected = [422, ['error' => 'Макет не знайдено: його вже замінено або видалено', 'final' => true]];
        $this->assertSame($expected, $this->part($orderId, $designer, ['chunkIndex' => 1] + $meta, substr($new, 500), $fields));
        $this->assertSame($expected, $this->part($orderId, $designer, ['chunkIndex' => 1] + $meta, substr($new, 500), $fields));
        $this->assertSame([], self::log(), 'без сповіщення');
    }
}
