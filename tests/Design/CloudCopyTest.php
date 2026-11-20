<?php

use Design\CloudCopy;
use Design\DesignerException;
use Design\OfferStatus;
use Design\OrderStatus;
use Design\Products;
use Design\Sql;
use Design\Workspace;
use PHPUnit\Framework\TestCase;

/**
 * Копія купленого макета в хмару wolf.ua (етап 5, Task 5): CloudCopy::request ставить у чергу, CloudCopy::run (крон) копіює.
 * DESIGN_WORK_ROOT і DESIGN_CLOUD_ROOT — тимчасові папки: справжніх /mnt/work і /mnt/cloud тест не чіпає.
 * Клієнт — DESIGN_TEST_CUSTOMER_ID (29; customers_cloud має FK на customers), дизайнер — DESIGN_TEST_DESIGNER_ID (25).
 * Замовлення, покупку (design_purchases без грошей) і макети тест вставляє сам; рядки customers_cloud — лише з іменами своїх
 * випадкових файлів — видаляє в tearDown. HTTP-перевірка (POST /orders/{id}/layouts/{fileId}/cloud) — через nginx dev
 * @group db
 */
final class CloudCopyTest extends TestCase
{
    private const UA = 'DesignTest/1';

    private int $client;
    private int $designer;
    private string $work;
    private string $cloud;
    private string|false $oldWork;
    private string|false $oldCloud;
    private int $maxCloud = 0;
    /** @var list<int> */
    private array $orders = [];
    /** @var list<string> імена файлів у хмарі, які міг створити тест */
    private array $names = [];
    /** @var array<int, string> */
    private static array $csrf = [];
    /** @var array<int, string> вміст макетів HTTP-тесту */
    private array $bytes = [];

    protected function setUp(): void
    {
        $this->client = (int)getenv('DESIGN_TEST_CUSTOMER_ID');
        $this->designer = (int)getenv('DESIGN_TEST_DESIGNER_ID');
        if ($this->client <= 0 || $this->designer <= 0) self::markTestSkipped('DESIGN_TEST_CUSTOMER_ID / DESIGN_TEST_DESIGNER_ID не задані');
        $this->maxCloud = (int)Sql::value('SELECT COALESCE(MAX(id), 0) FROM customers_cloud');
        $base = sys_get_temp_dir() . '/design-cloud-' . bin2hex(random_bytes(4));
        $this->work = "$base/work";
        $this->cloud = "$base/cloud";
        mkdir($this->work, 0777, true);
        mkdir($this->cloud, 0777, true);
        file_put_contents($this->cloud . '/is_mount.txt', 'This file verify mount');   // хмару «змонтовано»
        $this->oldWork = getenv('DESIGN_WORK_ROOT');
        $this->oldCloud = getenv('DESIGN_CLOUD_ROOT');
        putenv('DESIGN_WORK_ROOT=' . $this->work);
        putenv('DESIGN_CLOUD_ROOT=' . $this->cloud);
    }

    /** Кожен крок прибирання — окремо: збій одного (навіть «немає класу») не лишає решту */
    protected function tearDown(): void
    {
        $steps = [
            function (): void {
                putenv($this->oldWork === false ? 'DESIGN_WORK_ROOT' : 'DESIGN_WORK_ROOT=' . $this->oldWork);
                putenv($this->oldCloud === false ? 'DESIGN_CLOUD_ROOT' : 'DESIGN_CLOUD_ROOT=' . $this->oldCloud);
            },
            function (): void {
                if (!$this->names) return;
                Sql::exec('DELETE FROM customers_cloud WHERE id > ? AND customer_id = ? AND file IN (' . implode(',', array_fill(0, count($this->names), '?')) . ')',
                    [$this->maxCloud, $this->client, ...$this->names]);
            },
            function (): void {
                if (!$this->orders) return;
                $in = implode(',', array_map('intval', $this->orders));
                Sql::exec("DELETE FROM design_purchases WHERE order_id IN ($in)");
                Sql::exec("DELETE FROM design_files WHERE order_id IN ($in)");
                Sql::exec("DELETE FROM design_offers WHERE order_id IN ($in)");
                Sql::exec("DELETE FROM design_orders WHERE id IN ($in)");
            },
            fn() => exec('rm -rf ' . escapeshellarg(dirname($this->work))),
            static function (): void {
                CloudCopy::$timeout = 20;
                CloudCopy::$copyBase = 60;
                CloudCopy::$command = null;
            },
        ];
        $error = null;
        foreach ($steps as $step) {
            try {
                $step();
            } catch (\Throwable $e) {
                $error ??= $e;
            }
        }
        if ($error !== null) throw $error;
    }

    /** Замовлення клієнта «Куплено» з обраною пропозицією дизайнера і покупкою (без грошей) → [orderId, purchaseId] */
    private function order(): array
    {
        $orderId = Sql::insert('design_orders', [
            'customer_id' => $this->client,
            'product_id' => Products::visible()[0]['id'],
            'mode' => 1,
            'status' => OrderStatus::PURCHASED,
            'title' => 'Візитка для хмари',
            'answers' => json_encode([['q' => 'Текст', 'a' => 'Тест хмари']], JSON_UNESCAPED_UNICODE),
        ]);
        $this->orders[] = $orderId;
        $offerId = Sql::insert('design_offers', [
            'order_id' => $orderId, 'designer_id' => $this->designer, 'status' => OfferStatus::CHOSEN,
            'text' => 'Зроблю', 'price_designer' => 1000, 'markup_percent' => 20, 'price_client' => 1200,
            'deadline' => date('Y-m-d', strtotime('+3 day')),
        ]);
        Sql::exec('UPDATE design_orders SET chosen_offer_id = ?, chosen_at = NOW(), published_at = NOW() WHERE id = ?', [$offerId, $orderId]);
        $purchaseId = Sql::insert('design_purchases', ['order_id' => $orderId, 'offer_id' => $offerId, 'payer_id' => $this->client, 'status' => 2]);
        return [$orderId, $purchaseId];
    }

    /** Макет із файлом на диску (випадкові байти); purchaseId null — некуплений → [fileId, bytes] */
    private function layout(int $orderId, ?int $purchaseId, string $label = 'Візитка', string $ext = 'pdf', ?string $bytes = null): array
    {
        $bytes ??= '%PDF-1.4 ' . random_bytes(3000);
        $id = Sql::insert('design_files', [
            'order_id' => $orderId, 'label' => $label, 'original_name' => "$label.$ext", 'size' => strlen($bytes),
            'md5' => md5($bytes), 'purchase_id' => $purchaseId,
        ]);
        $file = $id . ($ext === '' ? '' : ".$ext");
        Sql::exec('UPDATE design_files SET file = ? WHERE id = ?', [$file, $id]);
        $dir = "{$this->work}/layouts/$orderId";
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        file_put_contents("$dir/$file", $bytes);
        $this->names[] = $this->name($bytes, $ext);
        return [$id, $bytes];
    }

    private function name(string $bytes, string $ext): string
    {
        return hash_hmac('sha256', md5($bytes), (string)$this->client) . ($ext === '' ? '' : ".$ext");
    }

    private function cloudFile(string $name): string
    {
        return $this->cloud . '/f/' . substr($name, 0, 2) . '/' . substr($name, 2, 2) . '/' . substr($name, 4, 2) . '/' . substr($name, 6);
    }

    private static function row(int $fileId): array
    {
        return Sql::one('SELECT cloud_status, cloud_id, cloud_error FROM design_files WHERE id = ?', [$fileId]);
    }

    /** @return list<string> усі файли під коренем хмари (відносні шляхи), крім маркера is_mount.txt */
    private function cloudTree(): array
    {
        $out = [];
        exec('cd ' . escapeshellarg($this->cloud) . ' && find . -mindepth 1 \( -type f -o -type p \) | sort', $out);
        return array_values(array_diff($out, ['./is_mount.txt']));
    }

    public function test_cloud_root_and_path(): void
    {
        putenv('DESIGN_CLOUD_ROOT');
        $this->assertSame('/mnt/cloud/', CloudCopy::cloudRoot());
        putenv('DESIGN_CLOUD_ROOT=' . $this->cloud);
        $this->assertSame($this->cloud . '/', CloudCopy::cloudRoot());
        // Як Path::cloudPath(name, 1) wolf.ua
        $this->assertSame('f/ab/cd/ef/0123.pdf', CloudCopy::cloudPath('abcdef0123.pdf'));
    }

    public function test_request_only_owner_purchased(): void
    {
        [$orderId, $purchaseId] = $this->order();
        [$bought] = $this->layout($orderId, $purchaseId);
        [$closed] = $this->layout($orderId, null, 'Чернетка');

        foreach ([[$bought, $this->designer], [$bought, 999999901], [999999999, $this->client]] as [$fileId, $who]) {
            try {
                CloudCopy::request($fileId, $who);
                $this->fail("request($fileId, $who) мав відмовити");
            } catch (DesignerException $e) {
                $this->assertSame('Макет не знайдено', $e->getMessage());
            }
        }
        try {
            CloudCopy::request($closed, $this->client);
            $this->fail('некуплений макет — відмова');
        } catch (DesignerException $e) {
            $this->assertSame('Скопіювати в хмару можна лише куплений макет', $e->getMessage());
        }
        $this->assertSame(0, (int)self::row($bought)['cloud_status']);
        $this->assertSame(0, (int)self::row($closed)['cloud_status']);

        $this->assertSame(CloudCopy::QUEUED, CloudCopy::request($bought, $this->client));
        $this->assertSame(CloudCopy::QUEUED, CloudCopy::request($bought, $this->client), 'повторний натиск — те саме');
        // Помилка → знову в черзі, текст помилки стерто
        Sql::exec('UPDATE design_files SET cloud_status = 3, cloud_error = ? WHERE id = ?', ['збій', $bought]);
        $this->assertSame(CloudCopy::QUEUED, CloudCopy::request($bought, $this->client));
        $this->assertNull(self::row($bought)['cloud_error']);
        $this->assertSame([], $this->cloudTree(), 'request хмари не торкається');

        // Статус у панелі — лише клієнту й лише купленим
        $panel = Workspace::layouts($orderId, true)['layouts'];
        $this->assertSame([CloudCopy::QUEUED, 0], array_column($panel, 'cloud_status'));
        $this->assertSame([0, 0], array_column(Workspace::layouts($orderId, false)['layouts'], 'cloud_status'));
    }

    public function test_run_copies_and_inserts_row(): void
    {
        [$orderId, $purchaseId] = $this->order();
        [$fileId, $bytes] = $this->layout($orderId, $purchaseId, 'Візитка <b>"Зерно"</b> & 🎨');
        [$other] = $this->layout($orderId, $purchaseId, 'Не просили');
        CloudCopy::request($fileId, $this->client);

        $this->assertSame(1, CloudCopy::run());
        $name = $this->name($bytes, 'pdf');
        $dest = $this->cloudFile($name);
        $this->assertFileExists($dest);
        $this->assertSame($bytes, file_get_contents($dest));
        $this->assertSame(['./f/' . substr($name, 0, 2) . '/' . substr($name, 2, 2) . '/' . substr($name, 4, 2) . '/' . substr($name, 6)],
            $this->cloudTree(), 'лише готовий файл, без тимчасових');

        $r = self::row($fileId);
        $this->assertSame(CloudCopy::DONE, (int)$r['cloud_status']);
        $this->assertNull($r['cloud_error']);
        $cloud = Sql::one('SELECT * FROM customers_cloud WHERE id = ?', [$r['cloud_id']]);
        $this->assertSame($this->client, (int)$cloud['customer_id']);
        $this->assertSame(1, (int)$cloud['type_id']);
        $this->assertSame($name, $cloud['file']);
        // Хмара wolf.ua виводить original_name без екранування (uploadStorage кладе html::encode) — кладемо так само;
        // utf8mb3: 4-байтові символи (емодзі) прибрано
        $this->assertSame('Дизайн №' . $orderId . ' — Візитка &lt;b&gt;&quot;Зерно&quot;&lt;/b&gt; &amp; .pdf', $cloud['original_name']);
        // Превʼю: 10 «готово або не треба» — api2 cloud/update-preview не везе файл у Київ, хмара показує значок розширення
        $this->assertSame(10, (int)$cloud['is_preview']);
        $this->assertNull($cloud['preview']);
        $this->assertSame(0, (int)self::row($other)['cloud_status'], 'без запиту — не копіюється');
        $this->assertSame(CloudCopy::DONE, Workspace::layouts($orderId, true)['layouts'][0]['cloud_status']);

        // Клієнт видалив файл у хмарі wolf.ua (рядок зник) — у панелі знову «Скопіювати», запит знову ставить у чергу
        Sql::exec('DELETE FROM customers_cloud WHERE id = ?', [$r['cloud_id']]);
        unlink($dest);
        $this->assertSame(0, Workspace::layouts($orderId, true)['layouts'][0]['cloud_status']);
        $this->assertSame(CloudCopy::QUEUED, CloudCopy::request($fileId, $this->client));
        $this->assertSame(1, CloudCopy::run());
        $this->assertFileExists($dest);
        $this->assertSame(CloudCopy::DONE, (int)self::row($fileId)['cloud_status']);
    }

    public function test_rerun_and_second_request_no_duplicate(): void
    {
        [$orderId, $purchaseId] = $this->order();
        $bytes = '%PDF-1.4 ' . random_bytes(2000);
        [$a] = $this->layout($orderId, $purchaseId, 'Перший', 'pdf', $bytes);
        [$b] = $this->layout($orderId, $purchaseId, 'Той самий файл', 'pdf', $bytes);
        CloudCopy::request($a, $this->client);
        $this->assertSame(1, CloudCopy::run());
        $this->assertSame(0, CloudCopy::run(), 'повторний прогін — нічого');
        $this->assertSame(CloudCopy::DONE, CloudCopy::request($a, $this->client), 'уже в хмарі — без змін');
        $this->assertSame(0, CloudCopy::run());

        // Інший макет з тим самим вмістом — та сама назва в хмарі: наявний рядок, без дубля
        CloudCopy::request($b, $this->client);
        $this->assertSame(1, CloudCopy::run());
        $name = $this->name($bytes, 'pdf');
        $rows = Sql::all('SELECT id FROM customers_cloud WHERE customer_id = ? AND file = ?', [$this->client, $name]);
        $this->assertCount(1, $rows);
        $this->assertSame((int)$rows[0]['id'], (int)self::row($a)['cloud_id']);
        $this->assertSame((int)$rows[0]['id'], (int)self::row($b)['cloud_id']);
        $this->assertCount(1, $this->cloudTree());
    }

    public function test_existing_cloud_row_reused(): void
    {
        [$orderId, $purchaseId] = $this->order();
        [$fileId, $bytes] = $this->layout($orderId, $purchaseId);
        $name = $this->name($bytes, 'pdf');
        // Клієнт сам колись завантажив цей файл у хмару wolf.ua
        $own = Sql::insert('customers_cloud', ['type_id' => 1, 'customer_id' => $this->client, 'original_name' => 'моя.pdf',
            'file' => $name, 'file_size' => strlen($bytes), 'is_preview' => 3, 'created' => '2025-01-01 10:00:00']);
        CloudCopy::request($fileId, $this->client);
        $this->assertSame(1, CloudCopy::run());
        $this->assertSame($own, (int)self::row($fileId)['cloud_id']);
        $row = Sql::one('SELECT original_name, is_preview FROM customers_cloud WHERE id = ?', [$own]);
        $this->assertSame(['original_name' => 'моя.pdf', 'is_preview' => 3], ['original_name' => $row['original_name'], 'is_preview' => (int)$row['is_preview']]);
        $this->assertSame(1, (int)Sql::value('SELECT COUNT(*) FROM customers_cloud WHERE customer_id = ? AND file = ?', [$this->client, $name]));
        // Як повторне завантаження в uploadStorage.php: рядок піднімається нагору хмари (сортування created DESC)
        $this->assertGreaterThan('2026-01-01', (string)Sql::value('SELECT created FROM customers_cloud WHERE id = ?', [$own]));
    }

    public function test_missing_source_marks_error_then_retry(): void
    {
        [$orderId, $purchaseId] = $this->order();
        [$fileId, $bytes] = $this->layout($orderId, $purchaseId);
        $src = "{$this->work}/layouts/$orderId/$fileId.pdf";
        rename($src, "$src.away");
        CloudCopy::request($fileId, $this->client);

        $this->assertSame(0, CloudCopy::run());
        $r = self::row($fileId);
        $this->assertSame(CloudCopy::FAILED, (int)$r['cloud_status']);
        $this->assertNotEmpty($r['cloud_error']);
        $this->assertNull($r['cloud_id']);
        $this->assertSame([], $this->cloudTree());
        $this->assertSame(CloudCopy::FAILED, Workspace::layouts($orderId, true)['layouts'][0]['cloud_status']);
        $this->assertSame(0, CloudCopy::run(), 'помилка сама не повторюється');

        rename("$src.away", $src);
        $this->assertSame(CloudCopy::QUEUED, CloudCopy::request($fileId, $this->client), '«Спробуйте ще»');
        $this->assertSame(1, CloudCopy::run());
        $this->assertSame(CloudCopy::DONE, (int)self::row($fileId)['cloud_status']);
        $this->assertFileExists($this->cloudFile($this->name($bytes, 'pdf')));
    }

    public function test_unwritable_cloud_marks_error_no_partial_file(): void
    {
        [$orderId, $purchaseId] = $this->order();
        [$a, $bytesA] = $this->layout($orderId, $purchaseId, 'Перший');
        [$b, $bytesB] = $this->layout($orderId, $purchaseId, 'Другий');

        // 1) Хмара недоступна: замість папки f — файл, mkdir падає
        file_put_contents($this->cloud . '/f', 'x');
        CloudCopy::request($a, $this->client);
        $this->assertSame(0, CloudCopy::run());
        $this->assertSame(CloudCopy::FAILED, (int)self::row($a)['cloud_status']);
        $this->assertSame(['./f'], $this->cloudTree());
        unlink($this->cloud . '/f');

        // 2) Копія вдалась, перейменування — ні (на місці файла папка): тимчасового файла не лишилось
        $dest = $this->cloudFile($this->name($bytesB, 'pdf'));
        mkdir($dest, 0755, true);
        CloudCopy::request($b, $this->client);
        $this->assertSame(0, CloudCopy::run());
        $r = self::row($b);
        $this->assertSame(CloudCopy::FAILED, (int)$r['cloud_status']);
        $this->assertNotEmpty($r['cloud_error']);
        $this->assertSame([], $this->cloudTree(), 'без напівфайла');
        $this->assertSame(0, (int)Sql::value('SELECT COUNT(*) FROM customers_cloud WHERE customer_id = ? AND file = ?',
            [$this->client, $this->name($bytesB, 'pdf')]));
        rmdir($dest);

        // Повтор обох — успіх
        CloudCopy::request($a, $this->client);
        CloudCopy::request($b, $this->client);
        $this->assertSame(2, CloudCopy::run());
        $this->assertSame($bytesA, file_get_contents($this->cloudFile($this->name($bytesA, 'pdf'))));
        $this->assertCount(2, $this->cloudTree());
    }

    public function test_hung_cloud_times_out(): void
    {
        [$orderId, $purchaseId] = $this->order();
        [$fileId] = $this->layout($orderId, $purchaseId);
        [$next] = $this->layout($orderId, $purchaseId, 'Наступний');
        // «Зависла» хмара: cp не повертається (замість нього — sleep 30)
        CloudCopy::$command = static fn(array $cmd): array => $cmd[0] === 'cp' ? ['sleep', '30'] : $cmd;
        CloudCopy::$copyBase = 1;
        CloudCopy::request($fileId, $this->client);
        CloudCopy::request($next, $this->client);
        $t = microtime(true);
        $this->assertSame(0, CloudCopy::run());
        $this->assertLessThan(10, microtime(true) - $t, 'операцію перервано за тайм-аутом, решту не чекали');
        $r = self::row($fileId);
        $this->assertSame(CloudCopy::FAILED, (int)$r['cloud_status']);
        $this->assertStringContainsString('тайм-аут', $r['cloud_error']);
        $n = self::row($next);
        $this->assertSame([CloudCopy::QUEUED, null], [(int)$n['cloud_status'], $n['cloud_error']], 'після тайм-ауту прогін зупинився — наступний чекає');
        $this->assertSame([], $this->cloudTree(), 'тимчасове ім\'я прибрано');

        // Хмара «ожила» — наступний прогін бере те, що чекало
        CloudCopy::$command = null;
        $this->assertSame(1, CloudCopy::run());
        $this->assertSame(CloudCopy::DONE, (int)self::row($next)['cloud_status']);
    }

    public function test_no_mount_marker_nothing_copied(): void
    {
        [$orderId, $purchaseId] = $this->order();
        [$fileId] = $this->layout($orderId, $purchaseId);
        CloudCopy::request($fileId, $this->client);
        // sshfs відпав: /mnt/cloud — порожня локальна папка без маркера
        unlink($this->cloud . '/is_mount.txt');
        $this->assertSame(0, CloudCopy::run());
        $r = self::row($fileId);
        $this->assertSame([CloudCopy::QUEUED, null, null], [(int)$r['cloud_status'], $r['cloud_error'], $r['cloud_id']], 'лишається в черзі');
        $this->assertSame([], $this->cloudTree(), 'на локальний диск нічого не лягло');
        $this->assertSame([], glob($this->cloud . '/*'), 'навіть папок немає');

        file_put_contents($this->cloud . '/is_mount.txt', 'x');
        $this->assertSame(1, CloudCopy::run());
        $this->assertSame(CloudCopy::DONE, (int)self::row($fileId)['cloud_status']);
    }

    public function test_stale_part_removed_before_copy(): void
    {
        [$orderId, $purchaseId] = $this->order();
        [$fileId, $bytes] = $this->layout($orderId, $purchaseId);
        // Попередній прогін убили посеред копії: лишився {name}.part — тут FIFO, у яку cp повис би
        $dest = $this->cloudFile($this->name($bytes, 'pdf'));
        mkdir(dirname($dest), 0755, true);
        $this->assertTrue(posix_mkfifo($dest . '.part', 0644));
        CloudCopy::$copyBase = 1;
        CloudCopy::request($fileId, $this->client);
        $this->assertSame(1, CloudCopy::run());
        $this->assertSame($bytes, file_get_contents($dest));
        $this->assertFileDoesNotExist($dest . '.part');
        $this->assertSame(CloudCopy::DONE, (int)self::row($fileId)['cloud_status']);
    }

    public function test_file_size_and_name(): void
    {
        [$orderId, $purchaseId] = $this->order();
        $bytes = random_bytes(12345);
        [$withExt] = $this->layout($orderId, $purchaseId, 'Банер 3×1', 'tiff', $bytes);
        [$noExt, $bytes2] = $this->layout($orderId, $purchaseId, 'Без розширення', '');
        CloudCopy::request($withExt, $this->client);
        CloudCopy::request($noExt, $this->client);
        $this->assertSame(2, CloudCopy::run());

        $c = Sql::one('SELECT * FROM customers_cloud WHERE id = ?', [self::row($withExt)['cloud_id']]);
        $this->assertSame(hash_hmac('sha256', md5_file("{$this->work}/layouts/$orderId/$withExt.tiff"), (string)$this->client) . '.tiff', $c['file']);
        $this->assertSame(12345, (int)$c['file_size']);
        $this->assertSame('Дизайн №' . $orderId . ' — Банер 3×1.tiff', $c['original_name']);
        $this->assertSame(12345, filesize($this->cloudFile($c['file'])));

        $c2 = Sql::one('SELECT * FROM customers_cloud WHERE id = ?', [self::row($noExt)['cloud_id']]);
        $this->assertSame($this->name($bytes2, ''), $c2['file']);
        $this->assertSame(64, strlen($c2['file']));
        $this->assertSame('Дизайн №' . $orderId . ' — Без розширення', $c2['original_name']);
        $this->assertSame(strlen($bytes2), (int)$c2['file_size']);
    }

    public function test_limit_and_lock(): void
    {
        [$orderId, $purchaseId] = $this->order();
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            [$ids[]] = $this->layout($orderId, $purchaseId, "Макет $i");
            CloudCopy::request(end($ids), $this->client);
        }
        // Інший прогін ще працює (замок зайнято) — цей нічого не робить
        // Файл замка міг створити інший користувач (крон): у /tmp (sticky, protected_regular) його відкривають без O_CREAT
        $lock = @fopen(CloudCopy::lockFile(), 'c') ?: fopen(CloudCopy::lockFile(), 'r');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        $this->assertSame(0, CloudCopy::run());
        flock($lock, LOCK_UN);
        fclose($lock);
        $this->assertSame([1, 1, 1], array_map(static fn($id) => (int)self::row($id)['cloud_status'], $ids));

        $this->assertSame(2, CloudCopy::run(2), 'не більше limit за прогін');
        $this->assertSame([2, 2, 1], array_map(static fn($id) => (int)self::row($id)['cloud_status'], $ids));
        $this->assertSame(1, CloudCopy::run(2));
    }

    public function test_stale_capture_is_retried(): void
    {
        [$orderId, $purchaseId] = $this->order();
        [$fileId] = $this->layout($orderId, $purchaseId);
        CloudCopy::request($fileId, $this->client);
        // Попередній прогін захопив рядок і помер (kill, OOM): позначка лишилась, замок вільний
        Sql::exec("UPDATE design_files SET cloud_error = 'copying' WHERE id = ?", [$fileId]);
        $this->assertSame(1, CloudCopy::run());
        $this->assertSame(CloudCopy::DONE, (int)self::row($fileId)['cloud_status']);
    }

    // ---------- HTTP: POST /orders/{id}/layouts/{fileId}/cloud ----------

    private static function cookie(int $id): string
    {
        $pass = (string)Sql::value('SELECT customer_pass FROM customers WHERE id = ?', [$id]);
        return 'wolfid=' . $id . '.' . hash_hmac('md5', $pass . self::UA, (string)$id);
    }

    /** @return array{0:int,1:array} код, JSON */
    private static function fetch(string $path, int $as, ?array $post = null): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://dev.design.wolf.ua' . $path,
            CURLOPT_RESOLVE => ['dev.design.wolf.ua:443:127.0.0.1'],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => self::UA,
            CURLOPT_HTTPHEADER => ['Cookie: ' . self::cookie($as)],
            CURLOPT_TIMEOUT => 30,
        ] + ($post === null ? [] : [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post)]));
        $body = (string)curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $json = json_decode($body, true);
        return [$code, is_array($json) ? $json : ['raw' => $body]];
    }

    private static function csrf(int $as): string
    {
        if (!isset(self::$csrf[$as])) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => 'https://dev.design.wolf.ua/brief/' . Products::visible()[0]['id'] . '/',
                CURLOPT_RESOLVE => ['dev.design.wolf.ua:443:127.0.0.1'],
                CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_USERAGENT => self::UA, CURLOPT_HTTPHEADER => ['Cookie: ' . self::cookie($as)], CURLOPT_TIMEOUT => 30,
            ]);
            $html = (string)curl_exec($ch);
            curl_close($ch);
            self::$csrf[$as] = preg_match('/name="csrf" value="([0-9a-f]{64})"/', $html, $m) ? $m[1] : '';
        }
        return self::$csrf[$as];
    }

    /** @group http */
    public function test_http_request_queues_for_client_only(): void
    {
        [$orderId, $purchaseId] = $this->order();
        [$bought, $boughtBytes] = $this->layout($orderId, $purchaseId);
        $this->bytes[$bought] = $boughtBytes;
        [$closed] = $this->layout($orderId, null, 'Чернетка');
        $url = "/orders/$orderId/layouts/$bought/cloud";

        [$code, $json] = self::fetch($url, $this->client, ['csrf' => 'bad']);
        $this->assertSame(403, $code);
        $this->assertSame('Сесія застаріла — оновіть сторінку', $json['error'] ?? null);

        [$code, $json] = self::fetch($url, $this->designer, ['csrf' => self::csrf($this->designer)]);
        $this->assertSame(404, $code, 'дизайнер у хмару клієнта не копіює');
        $this->assertSame(0, (int)self::row($bought)['cloud_status']);

        [$code, $json] = self::fetch("/orders/$orderId/layouts/$closed/cloud", $this->client, ['csrf' => self::csrf($this->client)]);
        $this->assertSame(422, $code);
        $this->assertSame('Скопіювати в хмару можна лише куплений макет', $json['error'] ?? null);

        // Макет іншого замовлення під адресою цього — «не знайдено»
        [$order2, $purchase2] = $this->order();
        [$foreign] = $this->layout($order2, $purchase2);
        [$code] = self::fetch("/orders/$orderId/layouts/$foreign/cloud", $this->client, ['csrf' => self::csrf($this->client)]);
        $this->assertSame(422, $code);
        $this->assertSame(0, (int)self::row($foreign)['cloud_status']);

        [$code, $json] = self::fetch($url, $this->client, ['csrf' => self::csrf($this->client)]);
        $this->assertSame(200, $code, json_encode($json, JSON_UNESCAPED_UNICODE));
        $this->assertSame(CloudCopy::QUEUED, (int)self::row($bought)['cloud_status']);
        $byId = array_column($json['layouts'], null, 'id');
        $this->assertSame(CloudCopy::QUEUED, $byId[$bought]['cloud_status']);
        $this->assertSame(0, $byId[$closed]['cloud_status']);
        // php-fpm працює зі справжньою /mnt/cloud dev (не з тимчасовою папкою тесту): файла з цим іменем там немає
        $this->assertFileDoesNotExist('/mnt/cloud/' . CloudCopy::cloudPath($this->name($this->bytes[$bought], 'pdf')), 'HTTP-запит не копіює');

        // Опитування панелі (GET) несе стан
        [$code, $json] = self::fetch("/orders/$orderId/layouts", $this->client);
        $this->assertSame(200, $code);
        $this->assertSame(CloudCopy::QUEUED, array_column($json['layouts'], null, 'id')[$bought]['cloud_status']);
    }
}
