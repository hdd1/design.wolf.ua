<?php

use Design\DesignerException;
use Design\DesignerStatus;
use Design\Layouts;
use Design\Messages;
use Design\Notify;
use Design\OfferStatus;
use Design\OrderStatus;
use Design\Products;
use Design\Settings;
use Design\Sql;
use Design\View;
use PHPUnit\Framework\TestCase;

/**
 * Макети: файли 0600 у папці 0700, ліміти числа й суми, заміна/видалення зі слідом у листуванні, доступ лише обраного дизайнера.
 * Клієнт і дизайнери — фіктивні customer_id 999999901+; замовлення й пропозиції тест вставляє сам (id — автоінкремент).
 * DESIGN_WORK_ROOT — тимчасова папка; файли — випадкові байти. Ліміти в МБ підміняються в кеші Settings (БД не чіпаємо:
 * 5 ГБ не пишемо). Макети через i.fastcdn не віддаються — тест перевіряє, що дерево симлінків CDN не змінилося
 * @group db
 */
final class LayoutsTest extends TestCase
{
    private const CLIENT = 999999901;
    private const A = 999999902;          // обраний дизайнер
    private const B = 999999903;          // інший схвалений дизайнер
    private const IDS = [self::CLIENT, self::A, self::B];
    private const NB = "\u{00A0}";

    private string $root;
    private string|false $oldRoot;
    private int $oldUmask;
    private array $links;

    protected function setUp(): void
    {
        Notify::$sent = [];
        Settings::reset();
        $this->links = CdnLinksGuard::snapshot();
        $this->cleanup();
        Sql::insert('design_designers', ['customer_id' => self::A, 'status' => DesignerStatus::APPROVED, 'name' => 'Петро Дизайнер']);
        Sql::insert('design_designers', ['customer_id' => self::B, 'status' => DesignerStatus::APPROVED, 'name' => 'Оксана']);
        $this->oldRoot = getenv('DESIGN_WORK_ROOT');
        $this->root = sys_get_temp_dir() . '/design-layouts-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/tmp', 0777, true);
        putenv('DESIGN_WORK_ROOT=' . $this->root);
        $this->oldUmask = umask(022);
    }

    protected function tearDown(): void
    {
        umask($this->oldUmask);
        Layouts::$beforeTransaction = null;
        Settings::reset();
        putenv($this->oldRoot === false ? 'DESIGN_WORK_ROOT' : 'DESIGN_WORK_ROOT=' . $this->oldRoot);
        self::rmTree($this->root);
        $this->cleanup();
        CdnLinksGuard::cleanup($this->links);
        Notify::$sent = [];
    }

    private function cleanup(): void
    {
        $in = implode(',', self::IDS);
        Sql::exec("DELETE f FROM design_files f JOIN design_orders d ON d.id = f.order_id WHERE d.customer_id IN ($in)");
        Sql::exec("DELETE m FROM design_messages m JOIN design_offers o ON o.id = m.offer_id
                   LEFT JOIN design_orders d ON d.id = o.order_id WHERE o.designer_id IN ($in) OR d.customer_id IN ($in)");
        Sql::exec("DELETE o FROM design_offers o LEFT JOIN design_orders d ON d.id = o.order_id
                   WHERE o.designer_id IN ($in) OR d.customer_id IN ($in)");
        Sql::exec("DELETE FROM design_orders WHERE customer_id IN ($in)");
        Sql::exec("DELETE FROM design_designers WHERE customer_id IN ($in)");
    }

    /** Замовлення з обраною пропозицією дизайнера $chosen (і «обрано іншого» від $other) → [orderId, offerId] */
    private function inWork(int $status = OrderStatus::IN_WORK, int $chosen = self::A, int $other = self::B): array
    {
        $orderId = Sql::insert('design_orders', [
            'customer_id' => self::CLIENT,
            'product_id' => Products::visible()[0]['id'],
            'status' => $status,
            'answers' => '[]',
        ]);
        $offerId = Sql::insert('design_offers', ['order_id' => $orderId, 'designer_id' => $chosen, 'status' => OfferStatus::CHOSEN]);
        Sql::insert('design_offers', ['order_id' => $orderId, 'designer_id' => $other, 'status' => OfferStatus::OTHER_CHOSEN]);
        Sql::exec('UPDATE design_orders SET chosen_offer_id = ?, chosen_at = NOW() WHERE id = ?', [$offerId, $orderId]);
        return [$orderId, $offerId];
    }

    /** Тимчасовий файл «як після склеювання» з випадковими байтами → шлях */
    private function upload(int $bytes = 1000): string
    {
        $path = $this->root . '/tmp/up-' . bin2hex(random_bytes(4));
        file_put_contents($path, $bytes > 0 ? random_bytes($bytes) : '');
        chmod($path, 0644);
        return $path;
    }

    /** Імена файлів у папці макетів замовлення */
    private function files(int $orderId): array
    {
        $files = glob($this->root . "/layouts/$orderId/*") ?: [];
        return array_map('basename', $files);
    }

    private function tmpFiles(): array
    {
        return array_map('basename', glob($this->root . '/tmp/*') ?: []);
    }

    private function texts(int $offerId): array
    {
        return array_column(Messages::list($offerId), 'text');
    }

    /** Підміна налаштувань у кеші Settings (БД не чіпаємо) */
    private static function override(array $values): void
    {
        Settings::get('layout_max_mb');
        $cache = new \ReflectionProperty(Settings::class, 'cache');
        $cache->setAccessible(true);
        $cache->setValue(null, array_merge($cache->getValue(), $values));
    }

    private static function rmTree(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (array_diff(scandir($path), ['.', '..']) as $f) self::rmTree("$path/$f");
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }

    private static function otherConnection(): \PDO
    {
        return new \PDO(
            'mysql:host=' . \CdnConfig::get('db_host') . ';port=' . \CdnConfig::get('db_port') . ';dbname=w2p;charset=utf8mb4',
            \CdnConfig::get('db_username'), \CdnConfig::get('db_password'),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
    }

    private function assertDenied(callable $fn, string $msg = 'Макети може завантажувати лише обраний дизайнер'): void
    {
        try {
            $fn();
            $this->fail('очікувалась відмова: ' . $msg);
        } catch (DesignerException $e) {
            $this->assertSame($msg, $e->getMessage());
        }
    }

    public function test_add_moves_file_private_perms(): void
    {
        [$orderId, $offerId] = $this->inWork();
        $tmp = $this->upload(2516582);
        $content = file_get_contents($tmp);

        $r = Layouts::add($orderId, self::A, $tmp, 'Візитка 1.PDF');

        $this->assertSame('Візитка 1', $r['label']);
        $this->assertSame(2516582, $r['size']);
        $this->assertFileDoesNotExist($tmp);
        $this->assertSame([$r['id'] . '.pdf'], $this->files($orderId));
        $path = $this->root . "/layouts/$orderId/{$r['id']}.pdf";
        $this->assertSame($content, file_get_contents($path));
        clearstatcache();
        $this->assertSame(0600, fileperms($path) & 0777, 'файл макета закритий для «інших» навіть при umask 022');
        $this->assertSame(0700, fileperms($this->root . "/layouts/$orderId") & 0777);
        $this->assertSame(0700, fileperms($this->root . '/layouts') & 0777);

        $row = Sql::one('SELECT * FROM design_files WHERE id = ?', [$r['id']]);
        $this->assertSame($orderId, (int)$row['order_id']);
        $this->assertSame('Візитка 1', $row['label']);
        $this->assertSame('Візитка 1.PDF', $row['original_name']);
        $this->assertSame($r['id'] . '.pdf', $row['file']);
        $this->assertSame(2516582, (int)$row['size']);
        $this->assertSame(md5($content), $row['md5']);
        $this->assertSame(Layouts::ACTIVE, (int)$row['status']);
        $this->assertNull($row['purchase_id']);
        $this->assertNull($row['cloud_id']);

        // слід у стрічці: системне повідомлення з event і file_id
        $msgs = Messages::list($offerId);
        $this->assertCount(1, $msgs);
        $this->assertSame(Messages::ROLE_SYSTEM, $msgs[0]['role']);
        $this->assertSame('Завантажено макет «Візитка 1» · 2,4' . self::NB . 'МБ', $msgs[0]['text']);
        $this->assertSame(['event' => 'layout_add', 'file_id' => $r['id']], $msgs[0]['data']);

        // клієнт сповіщений
        $this->assertCount(1, Notify::$sent);
        $this->assertSame(self::CLIENT, Notify::$sent[0]['customer_id']);
        $this->assertStringContainsString('нове повідомлення від Петро Дизайнер по №' . $orderId, Notify::$sent[0]['msg']);

        // макети не кодуються в i.fastcdn: жодного нового симлінка/шарда
        $this->assertEquals($this->links, CdnLinksGuard::snapshot());
        $this->assertSame([], $this->tmpFiles());
    }

    public function test_add_fixes_loose_layouts_dir_perms(): void
    {
        [$orderId] = $this->inWork();
        mkdir($this->root . "/layouts/$orderId", 0755, true);
        chmod($this->root . "/layouts/$orderId", 0755);

        $r = Layouts::add($orderId, self::A, $this->upload(), 'a.psd');

        clearstatcache();
        $this->assertSame(0700, fileperms($this->root . "/layouts/$orderId") & 0777, 'Paths::layouts наявну папку не чіпає — Layouts доводить до 0700');
        $this->assertSame(0600, fileperms($this->root . "/layouts/$orderId/{$r['id']}.psd") & 0777);
    }

    public function test_name_parsing(): void
    {
        [$orderId] = $this->inWork();
        $long = str_repeat('Я', 200);
        $cases = [
            // ім'я → [підпис, ім'я на диску без id]
            ['Візитка 1.PDF', 'Візитка 1', '.pdf'],
            ['archive.tar.gz', 'archive.tar', '.gz'],
            ['noext', 'noext', ''],
            ['.htaccess', '.htaccess', ''],
            ['evil.p h p', 'evil.p h p', ''],
            ['макет.пдф', 'макет.пдф', ''],
            ['file.' . str_repeat('a', 11), 'file.' . str_repeat('a', 11), ''],
            ['file.' . str_repeat('a', 10), 'file', '.' . str_repeat('a', 10)],
            ['C:\\Users\\x\\..\\..\\Кліше.AI', 'Кліше', '.ai'],
            ['../../etc/passwd.php', 'passwd', '.php'],
            ["Рядок\n1\u{202E}.cdr", 'Рядок1', '.cdr'],
            [$long . '.tif', mb_substr($long, 0, 120), '.tif'],
            ['   .pdf', '.pdf', ''],
            ['', 'Макет', ''],
        ];
        foreach ($cases as [$name, $label, $suffix]) {
            $r = Layouts::add($orderId, self::A, $this->upload(10), $name);
            $this->assertSame($label, $r['label'], "підпис «{$name}»");
            $row = Sql::one('SELECT file, original_name FROM design_files WHERE id = ?', [$r['id']]);
            $this->assertSame($r['id'] . $suffix, $row['file'], "файл «{$name}»");
            $this->assertMatchesRegularExpression('/^\d+(\.[a-z0-9]{1,10})?$/', $row['file']);
            $this->assertLessThanOrEqual(255, mb_strlen($row['original_name']));
            $this->assertDoesNotMatchRegularExpression('~[/\\\\\p{C}]~u', $row['original_name']);
        }
        $this->assertSame(0, (int)Sql::value('SELECT COUNT(*) FROM design_files WHERE order_id = ? AND CHAR_LENGTH(label) > 120', [$orderId]));
        $this->assertSame(0, (int)Sql::value("SELECT COUNT(*) FROM design_files WHERE order_id = ? AND label = ''", [$orderId]));
    }

    public function test_limits_count_and_total(): void
    {
        [$orderId, $offerId] = $this->inWork();
        $ids = [];
        for ($i = 1; $i <= 20; $i++) {
            $ids[] = Layouts::add($orderId, self::A, $this->upload(10), "m$i.pdf")['id'];
        }
        $tmp = $this->upload(10);
        $this->assertDenied(fn() => Layouts::add($orderId, self::A, $tmp, 'm21.pdf'), 'Макетів уже 20 — це максимум');
        $this->assertFileDoesNotExist($tmp, 'відхилений файл не лишається в tmp');
        $this->assertSame(20, Layouts::count($orderId));
        $this->assertCount(20, $this->files($orderId));
        $this->assertCount(20, array_filter($this->texts($offerId), fn($t) => str_starts_with($t, 'Завантажено')), 'відмова повідомлення не лишає');

        // замінений і видалений макети ліміт не займають
        Layouts::remove($ids[0], self::A);
        $new = Layouts::replace($ids[1], self::A, $this->upload(10), 'm2b.pdf');
        $this->assertSame(19, Layouts::count($orderId));
        Layouts::add($orderId, self::A, $this->upload(10), 'm21.pdf');
        $this->assertSame(20, Layouts::count($orderId));
        // заміна при повному ліміті працює (старий виходить із підрахунку раніше, ніж рахується новий)
        $this->assertSame('m2', Layouts::replace($new['id'], self::A, $this->upload(10), 'm2c.pdf')['label']);
        $this->assertSame(20, Layouts::count($orderId));

        // сума: 1 МБ на замовлення, файли по 600 000 байт
        [$order2] = $this->inWork();
        self::override(['layout_max_total_mb' => '1']);
        $first = Layouts::add($order2, self::A, $this->upload(600000), 'a.pdf');
        $tmp = $this->upload(600000);
        $this->assertDenied(fn() => Layouts::add($order2, self::A, $tmp, 'b.pdf'), 'Разом не більше 1' . self::NB . 'МБ на замовлення');
        $this->assertFileDoesNotExist($tmp);
        $this->assertSame([$first['id'] . '.pdf'], $this->files($order2));
        $this->assertSame(1, Layouts::count($order2));
        // добирається рівно до ліміту (1 048 576), байт зверху — вже ні
        $rest = 1048576 - 600000;
        Layouts::add($order2, self::A, $this->upload($rest), 'c.pdf');
        $this->assertDenied(fn() => Layouts::add($order2, self::A, $this->upload(1), 'd.pdf'), 'Разом не більше 1' . self::NB . 'МБ на замовлення');
        // заміна більшим файлом, що не вміщується, — відмова, старий макет лишається активним
        $this->assertDenied(fn() => Layouts::replace($first['id'], self::A, $this->upload(600001), 'a2.pdf'), 'Разом не більше 1' . self::NB . 'МБ на замовлення');
        $this->assertSame(Layouts::ACTIVE, (int)Sql::value('SELECT status FROM design_files WHERE id = ?', [$first['id']]));
        $this->assertFileExists($this->root . "/layouts/$order2/{$first['id']}.pdf");
        // заміна меншим — проходить
        Layouts::replace($first['id'], self::A, $this->upload(599999), 'a3.pdf');
        $this->assertSame([], $this->tmpFiles());
    }

    public function test_file_size_limit_and_empty_file(): void
    {
        [$orderId] = $this->inWork();
        self::override(['layout_max_mb' => '1']);
        $big = $this->upload(1048577);
        $this->assertDenied(fn() => Layouts::add($orderId, self::A, $big, 'big.pdf'), 'Файл більший за 1' . self::NB . 'МБ — це максимум для одного макета');
        $this->assertFileDoesNotExist($big);
        Layouts::add($orderId, self::A, $this->upload(1048576), 'ok.pdf');
        $this->assertDenied(fn() => Layouts::add($orderId, self::A, $this->upload(0), 'empty.pdf'), 'Файл порожній');
        $this->assertDenied(fn() => Layouts::add($orderId, self::A, $this->root . '/tmp/немає', 'x.pdf'), 'Файл не знайдено — завантажте його ще раз');
        $this->assertSame(1, Layouts::count($orderId));
        $this->assertSame([], $this->tmpFiles());
    }

    public function test_replace_and_remove_delete_files_and_log(): void
    {
        [$orderId, $offerId] = $this->inWork();
        $a = Layouts::add($orderId, self::A, $this->upload(100), 'Візитка 1.pdf');
        $b = Layouts::add($orderId, self::A, $this->upload(100), 'Флаєр.ai');
        $this->assertCount(2, $this->files($orderId));

        $content = random_bytes(500);
        $tmp = $this->root . '/tmp/new';
        file_put_contents($tmp, $content);
        $new = Layouts::replace($a['id'], self::A, $tmp, 'Візитка-final.PDF');

        $this->assertNotSame($a['id'], $new['id']);
        $this->assertSame('Візитка 1', $new['label'], 'підпис лишається, навіть коли ім\'я файла інше');
        $this->assertSame(500, $new['size']);
        $this->assertSame(Layouts::REPLACED, (int)Sql::value('SELECT status FROM design_files WHERE id = ?', [$a['id']]));
        $this->assertSame(Layouts::ACTIVE, (int)Sql::value('SELECT status FROM design_files WHERE id = ?', [$new['id']]));
        $this->assertEqualsCanonicalizing([$b['id'] . '.ai', $new['id'] . '.pdf'], $this->files($orderId), 'старий файл видалено');
        $this->assertSame($content, file_get_contents($this->root . "/layouts/$orderId/{$new['id']}.pdf"));
        clearstatcache();
        $this->assertSame(0600, fileperms($this->root . "/layouts/$orderId/{$new['id']}.pdf") & 0777);
        $this->assertSame('Візитка-final.PDF', Sql::value('SELECT original_name FROM design_files WHERE id = ?', [$new['id']]));
        $this->assertSame(md5($content), Sql::value('SELECT md5 FROM design_files WHERE id = ?', [$new['id']]));

        Layouts::remove($b['id'], self::A);
        $this->assertSame(Layouts::DELETED, (int)Sql::value('SELECT status FROM design_files WHERE id = ?', [$b['id']]));
        $this->assertSame([$new['id'] . '.pdf'], $this->files($orderId));

        $msgs = Messages::list($offerId);
        $this->assertSame([
            'Завантажено макет «Візитка 1» · 100' . self::NB . 'Б',
            'Завантажено макет «Флаєр» · 100' . self::NB . 'Б',
            'Макет «Візитка 1» замінено',
            'Макет «Флаєр» видалено',
        ], array_column($msgs, 'text'));
        $this->assertSame(['event' => 'layout_replace', 'file_id' => $new['id']], $msgs[2]['data']);
        $this->assertSame(['event' => 'layout_delete', 'file_id' => $b['id']], $msgs[3]['data']);
        $this->assertSame(Messages::ROLE_SYSTEM, $msgs[3]['role']);

        // повторно заміна/видалення/перейменування неактивного — відмова, нічого не міняється
        $gone = 'Макет не знайдено: його вже замінено або видалено';
        $this->assertDenied(fn() => Layouts::remove($a['id'], self::A), $gone);
        $this->assertDenied(fn() => Layouts::remove($b['id'], self::A), $gone);
        $tmp = $this->upload();
        $this->assertDenied(fn() => Layouts::replace($a['id'], self::A, $tmp, 'x.pdf'), $gone);
        $this->assertFileDoesNotExist($tmp);
        $this->assertDenied(fn() => Layouts::rename($b['id'], self::A, 'Нове'), $gone);
        $this->assertCount(4, Messages::list($offerId));
        $this->assertSame([$new['id'] . '.pdf'], $this->files($orderId));
        $this->assertSame([], $this->tmpFiles());
    }

    public function test_rename_rules(): void
    {
        [$orderId, $offerId] = $this->inWork();
        $r = Layouts::add($orderId, self::A, $this->upload(), 'old.pdf');
        $before = count(Messages::list($offerId));
        Notify::$sent = [];

        Layouts::rename($r['id'], self::A, "  Нова \n назва\u{202E}  ");
        $this->assertSame('Нова  назва', Sql::value('SELECT label FROM design_files WHERE id = ?', [$r['id']]));
        Layouts::rename($r['id'], self::A, str_repeat('Ж', 120));
        $this->assertSame(120, mb_strlen(Sql::value('SELECT label FROM design_files WHERE id = ?', [$r['id']])));
        $this->assertSame('old.pdf', Sql::value('SELECT original_name FROM design_files WHERE id = ?', [$r['id']]));

        $err = 'Підпис макета — від 1 до 120 символів';
        $this->assertDenied(fn() => Layouts::rename($r['id'], self::A, str_repeat('Ж', 121)), $err);
        $this->assertDenied(fn() => Layouts::rename($r['id'], self::A, "   \n "), $err);
        $this->assertDenied(fn() => Layouts::rename($r['id'], self::A, ''), $err);
        $this->assertSame(120, mb_strlen(Sql::value('SELECT label FROM design_files WHERE id = ?', [$r['id']])));

        $this->assertCount($before, Messages::list($offerId), 'перейменування — без повідомлення');
        $this->assertSame([], Notify::$sent, 'і без сповіщення');
    }

    public function test_upload_access_matrix(): void
    {
        [$orderId, $offerId] = $this->inWork();
        $mine = Layouts::add($orderId, self::A, $this->upload(), 'mine.pdf');
        $msgs = count(Messages::list($offerId));
        $denied = 'Макети може завантажувати лише обраний дизайнер';

        $try = function (int $who, int $order, int $file) use ($denied): void {
            $tmp = $this->upload();
            $this->assertDenied(fn() => Layouts::add($order, $who, $tmp, 'x.pdf'), $denied);
            $this->assertFileDoesNotExist($tmp, 'відхилений файл не лишається в tmp');
            $this->assertDenied(fn() => Layouts::replace($file, $who, $this->upload(), 'x.pdf'), $denied);
            $this->assertDenied(fn() => Layouts::remove($file, $who), $denied);
            $this->assertDenied(fn() => Layouts::rename($file, $who, 'Чуже'), $denied);
        };

        $try(self::B, $orderId, $mine['id']);        // інший схвалений дизайнер (його пропозиція — «обрано іншого»)
        $try(self::CLIENT, $orderId, $mine['id']);   // клієнт замовлення
        $try(999999999, $orderId, $mine['id']);      // сторонній
        $this->assertDenied(fn() => Layouts::add(999999999, self::A, $this->upload(), 'x.pdf'), $denied);   // немає замовлення
        $this->assertDenied(fn() => Layouts::remove(999999999, self::A), $denied);      // немає макета — та сама відповідь

        // чужий макет за id: дизайнер A на замовленні, де він не обраний
        [$order2] = $this->inWork(OrderStatus::IN_WORK, self::B, self::A);
        $this->assertDenied(fn() => Layouts::add($order2, self::A, $this->upload(), 'x.pdf'), $denied);
        $foreign = Layouts::add($order2, self::B, $this->upload(), 'b.pdf');
        $this->assertDenied(fn() => Layouts::remove($foreign['id'], self::A), $denied);
        $this->assertDenied(fn() => Layouts::rename($foreign['id'], self::A, 'Моє'), $denied);
        $this->assertDenied(fn() => Layouts::replace($foreign['id'], self::A, $this->upload(), 'x.pdf'), $denied);
        $this->assertSame(Layouts::ACTIVE, (int)Sql::value('SELECT status FROM design_files WHERE id = ?', [$foreign['id']]));

        // дизайнер більше не схвалений
        Sql::exec('UPDATE design_designers SET status = ? WHERE customer_id = ?', [DesignerStatus::BLOCKED, self::A]);
        $try(self::A, $orderId, $mine['id']);
        Sql::exec('UPDATE design_designers SET status = ? WHERE customer_id = ?', [DesignerStatus::APPROVED, self::A]);

        // замовлення не «В роботі» (рахунок, куплено, скасовано, чернетка)
        foreach ([OrderStatus::AWAITING_INVOICE, OrderStatus::PURCHASED, OrderStatus::REWORK, OrderStatus::CANCELLED, OrderStatus::COLLECTING] as $status) {
            Sql::exec('UPDATE design_orders SET status = ? WHERE id = ?', [$status, $orderId]);
            $try(self::A, $orderId, $mine['id']);
        }
        Sql::exec('UPDATE design_orders SET status = ? WHERE id = ?', [OrderStatus::IN_WORK, $orderId]);

        // пропозицію не «обрано»
        Sql::exec('UPDATE design_offers SET status = ? WHERE id = ?', [OfferStatus::OTHER_CHOSEN, $offerId]);
        $try(self::A, $orderId, $mine['id']);
        Sql::exec('UPDATE design_offers SET status = ? WHERE id = ?', [OfferStatus::CHOSEN, $offerId]);

        // нічого не змінилось
        $this->assertSame([$mine['id'] . '.pdf'], $this->files($orderId));
        $this->assertSame('mine', Sql::value('SELECT label FROM design_files WHERE id = ?', [$mine['id']]));
        $this->assertSame(Layouts::ACTIVE, (int)Sql::value('SELECT status FROM design_files WHERE id = ?', [$mine['id']]));
        $this->assertSame(1, (int)Sql::value('SELECT COUNT(*) FROM design_files WHERE order_id = ?', [$orderId]));
        $this->assertCount($msgs, Messages::list($offerId));
        $this->assertSame([], $this->tmpFiles());

        // а обраний дизайнер працює
        $this->assertSame('ok', Layouts::add($orderId, self::A, $this->upload(), 'ok.pdf')['label']);
    }

    public function test_for_order_hides_replaced_deleted(): void
    {
        [$orderId] = $this->inWork();
        [$other] = $this->inWork();
        $this->assertSame([], Layouts::forOrder($orderId));
        $this->assertSame(0, Layouts::count($orderId));

        $a = Layouts::add($orderId, self::A, $this->upload(300), 'A.pdf');
        $b = Layouts::add($orderId, self::A, $this->upload(400), 'B.AI');
        $c = Layouts::add($orderId, self::A, $this->upload(500), 'C');
        $d = Layouts::add($orderId, self::A, $this->upload(600), 'D.cdr');
        Layouts::add($other, self::A, $this->upload(), 'чужий.pdf');
        $b2 = Layouts::replace($b['id'], self::A, $this->upload(450), 'B2.ai');
        Layouts::remove($d['id'], self::A);

        $list = Layouts::forOrder($orderId);
        $this->assertSame([$a['id'], $c['id'], $b2['id']], array_column($list, 'id'));
        $this->assertSame(3, Layouts::count($orderId));
        $this->assertSame(1, Layouts::count($other));
        $this->assertSame(['id', 'label', 'original_name', 'size', 'ext', 'created'], array_keys($list[0]));
        $this->assertSame(['A', 'A.pdf', 300, 'pdf'], [$list[0]['label'], $list[0]['original_name'], $list[0]['size'], $list[0]['ext']]);
        $this->assertSame(['', 'C'], [$list[1]['ext'], $list[1]['label']]);
        $this->assertSame(['ai', 'B', 450], [$list[2]['ext'], $list[2]['label'], $list[2]['size']]);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $list[0]['created']);
        $this->assertStringNotContainsString($this->root, json_encode($list));
    }

    public function test_one_notification_for_a_batch(): void
    {
        [$orderId, $offerId] = $this->inWork();
        $a = Layouts::add($orderId, self::A, $this->upload(), 'a.pdf');
        Layouts::add($orderId, self::A, $this->upload(), 'b.pdf');
        Layouts::remove($a['id'], self::A);
        $this->assertCount(1, Notify::$sent, 'пачка дій — одне сповіщення, поки клієнт не відкрив листування');
        $this->assertCount(3, Messages::list($offerId), 'але кожна дія залишає слід у стрічці');

        Sql::exec('UPDATE design_offers SET client_notified_at = NOW() - INTERVAL 120 SECOND, client_seen_at = NOW() - INTERVAL 60 SECOND WHERE id = ?', [$offerId]);
        Layouts::add($orderId, self::A, $this->upload(), 'c.pdf');
        $this->assertCount(2, Notify::$sent);
    }

    /** Повільне (md5) — до транзакції: під час нього рядок замовлення вільний, і паралельне завантаження не чекає */
    public function test_transaction_is_short(): void
    {
        [$orderId] = $this->inWork();
        $other = self::otherConnection();
        $seen = [];
        $nested = null;
        Layouts::$beforeTransaction = function (int $order, string $tmp) use (&$seen, &$nested, $other, $orderId): void {
            $seen[] = $order;
            $this->assertFalse(Sql::pdo()->inTransaction());
            $this->assertFileExists($tmp);
            clearstatcache();
            $this->assertSame(0600, fileperms($tmp) & 0777, 'tmp закритий ще до перенесення');
            $other->beginTransaction();
            $this->assertSame($orderId, (int)$other->query("SELECT id FROM design_orders WHERE id = $orderId FOR UPDATE NOWAIT")->fetchColumn());
            $other->rollBack();
            if ($nested === null) {
                $nested = false;
                $nested = Layouts::add($orderId, self::A, $this->upload(20), 'inner.pdf');
            }
        };
        $outer = Layouts::add($orderId, self::A, $this->upload(10), 'outer.pdf');
        $this->assertSame([$orderId, $orderId], $seen);
        $this->assertLessThan($outer['id'], $nested['id']);
        $this->assertSame(2, Layouts::count($orderId));
    }

    /** Не вдався rename — відкат: ні рядка, ні повідомлення, ні файла; старий макет при заміні лишається активним; tmp прибрано */
    public function test_rename_failure_rolls_back(): void
    {
        [$orderId, $offerId] = $this->inWork();
        $old = Layouts::add($orderId, self::A, $this->upload(), 'old.pdf');
        $rows = (int)Sql::value('SELECT COUNT(*) FROM design_files WHERE order_id = ?', [$orderId]);
        $msgs = count(Messages::list($offerId));
        Notify::$sent = [];
        // джерело зникає між перевірками й транзакцією — rename падає
        Layouts::$beforeTransaction = fn(int $o, string $tmp) => unlink($tmp);

        try {
            Layouts::add($orderId, self::A, $this->upload(), 'new.pdf');
            $this->fail('очікувався збій rename');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Не вдалося перенести макет', $e->getMessage());
        }
        try {
            Layouts::replace($old['id'], self::A, $this->upload(), 'new.pdf');
            $this->fail('очікувався збій rename');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Не вдалося перенести макет', $e->getMessage());
        }

        $this->assertFalse(Sql::pdo()->inTransaction());
        $this->assertSame($rows, (int)Sql::value('SELECT COUNT(*) FROM design_files WHERE order_id = ?', [$orderId]), 'осиротілого рядка немає');
        $this->assertSame(Layouts::ACTIVE, (int)Sql::value('SELECT status FROM design_files WHERE id = ?', [$old['id']]));
        $this->assertCount($msgs, Messages::list($offerId));
        $this->assertSame([$old['id'] . '.pdf'], $this->files($orderId));
        $this->assertSame([], $this->tmpFiles());
        $this->assertSame([], Notify::$sent, 'сповіщення лише після коммиту');
        // і після збою все працює
        Layouts::$beforeTransaction = null;
        $this->assertSame('ok', Layouts::add($orderId, self::A, $this->upload(), 'ok.pdf')['label']);
    }

    public function test_bytes_format(): void
    {
        $nb = self::NB;
        $this->assertSame("0{$nb}Б", View::bytes(0));
        $this->assertSame("1{$nb}Б", View::bytes(1));
        $this->assertSame("1023{$nb}Б", View::bytes(1023));
        $this->assertSame("1{$nb}КБ", View::bytes(1024));
        $this->assertSame("850{$nb}КБ", View::bytes(870400));
        $this->assertSame("850{$nb}КБ", View::bytes(870400 + 300));
        $this->assertSame("1{$nb}МБ", View::bytes(1023 * 1024 + 900), '1023,9 КБ → вже мегабайт');
        $this->assertSame("1{$nb}МБ", View::bytes(1048576));
        $this->assertSame("2,4{$nb}МБ", View::bytes(2516582));
        $this->assertSame("2{$nb}МБ", View::bytes(2097152));
        $this->assertSame("1023,9{$nb}МБ", View::bytes(1073741824 - 120 * 1024));
        $this->assertSame("1{$nb}ГБ", View::bytes(1073741824 - 1));
        $this->assertSame("1,1{$nb}ГБ", View::bytes(1181116006));
        $this->assertSame("5{$nb}ГБ", View::bytes(5368709120));
        $this->assertSame("1{$nb}ТБ", View::bytes(1099511627776));
    }
}
