<?php

use Design\Designers;
use Design\DesignerStatus;
use Design\Messages;
use Design\OfferStatus;
use Design\OrderStatus;
use Design\Paths;
use Design\Products;
use Design\Sql;
use PHPUnit\Framework\TestCase;

/**
 * Робочий простір по-справжньому через nginx dev.design.wolf.ua: прев'ю частинами (POST /orders/{id}/upload), приховування
 * (POST /orders/{id}/previews/{pid}), відповіді з цитатою (reply_to), картки прев'ю і цитати на сторінках клієнта й дизайнера.
 * Клієнт — DESIGN_TEST_CUSTOMER_ID (29), дизайнер — DESIGN_TEST_DESIGNER_ID (25): тест робить 25 схваленим дизайнером (якщо
 * в нього вже є профіль — пропуск, справжні дані не чіпаємо) і обраним на своєму замовленні «В роботі».
 * Файли пише php-fpm (www-data) у справжній Paths::root() (на dev — локальна папка /mnt/work/source.wolf.ua/design):
 * тимчасова DESIGN_WORK_ROOT для php-fpm неможлива. Тест прибирає все створене: рядки, папки замовлень, відповіді й частини
 * в /mnt/work/tmp/tmp_uppy, каталоги, яких до тесту не було, і посилання i.fastcdn (cloud-links/ci) на свої прев'ю.
 * Частини — справжній multipart, менші за 1,5 МБ (на dev upload_max_filesize 2M).
 * Співрозмовник перед кожним записом «в чаті» (seen_at = NOW()) — сповіщення не йдуть.
 *
 * @group http
 */
final class WorkspaceHttpTest extends TestCase
{
    private const UA = 'DesignTest/1';
    private const DESIGNER_NAME = 'Тест <b>Дизайнер</b>';
    private const UPPY = '/mnt/work/tmp/tmp_uppy/';
    private const LINKS = '/home/connect/cloud-links/ci';

    /** @var list<int> */
    private array $orders = [];
    private bool $ownsDesigner = false;
    /** @var list<string> ключі ChunkUpload (scope|fileId) цього тесту */
    private array $keys = [];
    /** @var array<int, string> */
    private static array $csrf = [];
    /** @var list<string> каталоги, яких не було до тестів */
    private static array $newDirs = [];
    /** @var array<string, true> каталоги cloud-links до тестів */
    private static array $linkDirs = [];
    /** Скільки посилань i.fastcdn на прев'ю тестів створив php-fpm (для звіту) */
    public static int $linksRemoved = 0;

    public static function setUpBeforeClass(): void
    {
        $root = Paths::root();
        foreach (['/mnt/work/tmp', '/mnt/work/tmp/tmp_uppy', self::UPPY . 'chunks', self::UPPY . 'design-results',
                     $root, "$root/previews", "$root/layouts"] as $dir) {
            if (!is_dir($dir)) self::$newDirs[] = $dir;
        }
        exec('find ' . escapeshellarg(self::LINKS) . ' -mindepth 1 -maxdepth 3 -type d', $dirs);
        self::$linkDirs = array_fill_keys($dirs, true);
    }

    public static function tearDownAfterClass(): void
    {
        // Порожні каталоги шардів cloud-links, створені php-fpm для посилань тестів
        exec('find ' . escapeshellarg(self::LINKS) . ' -mindepth 1 -maxdepth 3 -type d', $dirs);
        $new = array_filter($dirs, static fn(string $d): bool => !isset(self::$linkDirs[$d]));
        usort($new, static fn($a, $b) => substr_count($b, '/') <=> substr_count($a, '/'));
        foreach ($new as $d) if (count(scandir($d)) === 2) @rmdir($d);
        foreach (array_reverse(self::$newDirs) as $dir) {
            if (is_dir($dir) && count(scandir($dir)) === 2) @rmdir($dir);
        }
    }

    protected function tearDown(): void
    {
        $root = Paths::root();
        if ($this->orders) {
            $in = implode(',', array_map('intval', $this->orders));
            Sql::exec("DELETE FROM design_previews WHERE order_id IN ($in)");
            Sql::exec("DELETE FROM design_files WHERE order_id IN ($in)");
            Sql::exec("DELETE m FROM design_messages m JOIN design_offers f ON f.id = m.offer_id WHERE f.order_id IN ($in)");
            Sql::exec("DELETE FROM design_offers WHERE order_id IN ($in)");
            Sql::exec("DELETE FROM design_orders WHERE id IN ($in)");
            foreach ($this->orders as $id) {
                $out = [];
                exec('find ' . escapeshellarg(self::LINKS) . ' -type l -lname ' . escapeshellarg("$root/previews/$id/*"), $out);
                foreach ($out as $l) if (unlink($l)) self::$linksRemoved++;
                foreach (["$root/previews/$id", "$root/layouts/$id"] as $dir) {
                    if (is_dir($dir)) exec('rm -rf ' . escapeshellarg($dir));
                }
            }
            $this->orders = [];
        }
        foreach ($this->keys as $key) {
            @unlink(self::UPPY . "design-results/$key.json");
            @unlink(self::UPPY . "design-results/$key.lock");
            foreach (glob(self::UPPY . 'chunks/' . md5($key) . '.part*') ?: [] as $p) @unlink($p);
        }
        $this->keys = [];
        if ($this->ownsDesigner) {
            Sql::exec('DELETE FROM design_designers WHERE customer_id = ?', [self::designerId()]);
            $this->ownsDesigner = false;
        }
    }

    private static function clientId(): int
    {
        $id = (int)getenv('DESIGN_TEST_CUSTOMER_ID');
        if ($id <= 0) self::markTestSkipped('DESIGN_TEST_CUSTOMER_ID не задан');
        return $id;
    }

    private static function designerId(): int
    {
        $id = (int)getenv('DESIGN_TEST_DESIGNER_ID');
        if ($id <= 0) self::markTestSkipped('DESIGN_TEST_DESIGNER_ID не задан');
        return $id;
    }

    /** 25 стає схваленим дизайнером на час тесту; вже є свій рядок — пропуск */
    private function designer(): int
    {
        $id = self::designerId();
        if (Designers::get($id) !== null) {
            $this->markTestSkipped("у клієнта $id вже є профіль дизайнера — тест не чіпає справжні дані");
        }
        Sql::insert('design_designers', ['customer_id' => $id, 'status' => DesignerStatus::APPROVED, 'name' => self::DESIGNER_NAME]);
        $this->ownsDesigner = true;
        return $id;
    }

    /** Замовлення клієнта «В роботі» з обраною пропозицією дизайнера → [orderId, offerId] */
    private function inWork(int $client, int $designer, int $status = OrderStatus::IN_WORK): array
    {
        $orderId = Sql::insert('design_orders', [
            'customer_id' => $client,
            'product_id' => Products::visible()[0]['id'],
            'mode' => 1,
            'status' => $status,
            'title' => 'Візитка для кавʼярні «Зерно»',
            'answers' => json_encode([['q' => 'Текст', 'a' => 'Тест робочого простору']], JSON_UNESCAPED_UNICODE),
        ]);
        $this->orders[] = $orderId;
        Sql::exec('UPDATE design_orders SET published_at = NOW() WHERE id = ?', [$orderId]);
        $offerId = Sql::insert('design_offers', [
            'order_id' => $orderId, 'designer_id' => $designer, 'status' => OfferStatus::CHOSEN,
            'text' => 'Зроблю', 'price_designer' => 1000, 'markup_percent' => 20, 'price_client' => 1200,
            'deadline' => date('Y-m-d', strtotime('+3 day')),
        ]);
        Sql::exec('UPDATE design_orders SET chosen_offer_id = ?, chosen_at = NOW() WHERE id = ?', [$offerId, $orderId]);
        self::inChat($offerId);
        return [$orderId, $offerId];
    }

    /** Обидві сторони «в чаті» — сповіщення не піде */
    private static function inChat(int $offerId): void
    {
        Sql::exec('UPDATE design_offers SET client_seen_at = NOW(), designer_seen_at = NOW() WHERE id = ?', [$offerId]);
    }

    private static function cookie(int $id): string
    {
        $pass = (string)Sql::value('SELECT customer_pass FROM customers WHERE id = ?', [$id]);
        return 'wolfid=' . $id . '.' . hash_hmac('md5', $pass . self::UA, (string)$id);
    }

    /**
     * @param array|null $post поля; CURLFile — multipart
     * @return array{0:int,1:string,2:string} код, заголовки, тіло
     */
    private static function fetch(string $path, int $as, ?array $post = null, bool $multipart = false): array
    {
        $ch = curl_init('https://dev.design.wolf.ua' . $path);
        $opts = [
            CURLOPT_RESOLVE        => ['dev.design.wolf.ua:443:127.0.0.1'],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_USERAGENT      => self::UA,
            CURLOPT_HTTPHEADER     => ['Cookie: ' . self::cookie($as)],
            CURLOPT_TIMEOUT        => 60,
        ];
        if ($post !== null) {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = $multipart ? $post : http_build_query($post);
        }
        curl_setopt_array($ch, $opts);
        $raw = (string)curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        return [$code, substr($raw, 0, $hs), substr($raw, $hs)];
    }

    private static function csrf(int $as): string
    {
        if (!isset(self::$csrf[$as])) {
            [, , $html] = self::fetch('/brief/' . Products::visible()[0]['id'] . '/', $as);
            self::$csrf[$as] = preg_match('/name="csrf" value="([0-9a-f]{64})"/', $html, $m) ? $m[1] : '';
        }
        return self::$csrf[$as];
    }

    /**
     * Одна частина завантаження справжнім multipart
     * @param array $meta fileId, chunkIndex, totalChunks, totalSize, originalName
     * @return array{0:int,1:array} код і JSON
     */
    private function part(int $orderId, int $as, string $purpose, array $meta, string $bytes, array $fields = [], ?string $csrf = null): array
    {
        $this->keys[] = sha1("$as:$orderId:$purpose|" . $meta['fileId']);
        $tmp = tempnam(sys_get_temp_dir(), 'wsp');
        file_put_contents($tmp, $bytes);
        $post = ['purpose' => $purpose, 'csrf' => $csrf ?? self::csrf($as)] + $fields;
        foreach ($meta as $k => $v) $post["meta[$k]"] = (string)$v;
        $post['file'] = new CURLFile($tmp, 'application/octet-stream', 'blob');
        try {
            [$code, $h, $body] = self::fetch("/orders/$orderId/upload", $as, $post, true);
        } finally {
            unlink($tmp);
        }
        if ($code !== 401) $this->assertMatchesRegularExpression('#^content-type:\s*application/json#mi', $h, $body);
        return [$code, (array)json_decode($body, true)];
    }

    /**
     * Увесь файл частинами по $partSize байт (кожна < 1,5 МБ) → [код, JSON] останньої частини
     * @return array{0:int,1:array,2:array} код, JSON, meta (для повтору)
     */
    private function upload(int $orderId, int $as, string $purpose, string $bytes, string $name, array $fields = [], int $parts = 2): array
    {
        $size = strlen($bytes);
        $partSize = (int)ceil($size / $parts);
        $this->assertLessThan(1572864, $partSize);
        $meta = ['fileId' => 'test' . bin2hex(random_bytes(8)), 'totalChunks' => $parts, 'totalSize' => $size, 'originalName' => $name];
        for ($i = 0; $i < $parts; $i++) {
            [$code, $json] = $this->part($orderId, $as, $purpose, ['chunkIndex' => $i] + $meta, substr($bytes, $i * $partSize, $partSize), $fields);
            if ($i < $parts - 1) {
                if ($code !== 200) return [$code, $json, $meta];
                $this->assertSame(['done' => false], $json);
            }
        }
        return [$code, $json, $meta];
    }

    /** PNG $w×$h з написом */
    private static function png(int $w = 600, int $h = 400, string $color = '#3b2a1f'): string
    {
        $im = new Imagick();
        $im->newImage($w, $h, new ImagickPixel($color));
        $im->setImageFormat('png');
        $draw = new ImagickDraw();
        $draw->setFillColor('#f5e9d6');
        $draw->setFontSize(48);
        $im->annotateImage($draw, 40, 200, 0, 'Zerno ' . bin2hex(random_bytes(2)));
        // Шум — щоб PNG важив кілька сотень КБ і справді йшов кількома частинами
        $noise = new Imagick();
        $noise->newPseudoImage($w, 60, 'plasma:');
        $im->compositeImage($noise, Imagick::COMPOSITE_OVER, 0, $h - 60);
        $blob = $im->getImageBlob();
        $im->clear();
        $noise->clear();
        return $blob;
    }

    /** Прев'ю від дизайнера через HTTP → JSON останньої частини */
    private function preview(int $orderId, int $offerId, int $designer, string $caption = '', ?int $basedOn = null): array
    {
        self::inChat($offerId);
        [$code, $json] = $this->upload($orderId, $designer, 'preview', self::png(), 'zerno.png',
            ['caption' => $caption, 'based_on' => $basedOn === null ? '' : (string)$basedOn]);
        $this->assertSame(200, $code, json_encode($json, JSON_UNESCAPED_UNICODE));
        return $json;
    }

    /** @return array{0:int,1:array} */
    private static function send(int $offerId, int $as, string $text, string $replyTo = ''): array
    {
        self::inChat($offerId);
        [$code, , $body] = self::fetch("/offers/$offerId/messages", $as, ['text' => $text, 'reply_to' => $replyTo, 'csrf' => self::csrf($as)]);
        return [$code, (array)json_decode($body, true)];
    }

    /** @return array{0:int,1:array} */
    private static function hide(int $orderId, int $previewId, int $as, ?string $csrf = null, string $action = 'hide'): array
    {
        [$code, , $body] = self::fetch("/orders/$orderId/previews/$previewId", $as, ['action' => $action, 'csrf' => $csrf ?? self::csrf($as)]);
        return [$code, (array)json_decode($body, true)];
    }

    /** @return list<string> файли картинок прев'ю на диску */
    private static function previewFiles(int $orderId, int $previewId): array
    {
        return glob(Paths::root() . "/previews/$orderId/$previewId-p*.webp") ?: [];
    }

    private static function chunkParts(string $key): array
    {
        return glob(self::UPPY . 'chunks/' . md5($key) . '.part*') ?: [];
    }

    public function test_designer_uploads_preview_in_chunks(): void
    {
        $client = self::clientId();
        $designer = $this->designer();
        [$orderId, $offerId] = $this->inWork($client, $designer);

        $png = self::png();
        $this->assertGreaterThan(20000, strlen($png));
        [$code, $json, $meta] = $this->upload($orderId, $designer, 'preview', $png, 'zerno.png',
            ['caption' => 'Темний <b>фон</b>, тонка рамка', 'based_on' => '']);
        $this->assertSame(200, $code, json_encode($json, JSON_UNESCAPED_UNICODE));
        $this->assertTrue($json['done']);
        $p = $json['preview'];
        $this->assertSame(1, $p['num']);
        $this->assertSame(1, $p['pages']);
        $this->assertFalse($p['hidden']);
        $this->assertNull($p['based_on_num']);
        $this->assertSame('Темний <b>фон</b>, тонка рамка', $p['caption']);
        $this->assertCount(1, $p['urls']);
        $this->assertMatchesRegularExpression('#^https://[^/]+/.+\.webp$#', $p['urls'][0]['view']);
        $this->assertMatchesRegularExpression('#^https://[^/]+/.+\.webp$#', $p['urls'][0]['card']);
        // Повідомлення прев'ю — у вигляді стрічки
        $m = $json['message'];
        $this->assertTrue($m['mine']);
        $this->assertSame($p, $m['preview']);
        $this->assertSame('Темний <b>фон</b>, тонка рамка', $m['text']);
        $row = Sql::one('SELECT * FROM design_previews WHERE order_id = ?', [$orderId]);
        $this->assertSame($p['id'], (int)$row['id']);
        $this->assertSame($m['id'], (int)$row['message_id']);
        $this->assertSame($offerId, (int)$row['offer_id']);

        // Файли: view і card з правами для nginx; тимчасового нічого
        $files = self::previewFiles($orderId, $p['id']);
        $this->assertCount(2, $files);
        $this->assertSame('0755', substr(sprintf('%o', fileperms(Paths::root() . "/previews/$orderId")), -4));
        foreach ($files as $f) $this->assertSame('0644', substr(sprintf('%o', fileperms($f)), -4), $f);
        $key = sha1("$designer:$orderId:preview|" . $meta['fileId']);
        $this->assertSame([], self::chunkParts($key));
        $this->assertSame([], glob(Paths::root() . "/previews/$orderId/tmp-*") ?: []);

        // Повтор останньої частини (обрив) — та сама відповідь, друге прев'ю не з'являється
        [$code, $again] = $this->part($orderId, $designer, 'preview', ['chunkIndex' => 1] + $meta, substr($png, (int)ceil(strlen($png) / 2)));
        $this->assertSame(200, $code);
        $this->assertSame($json, $again);
        $this->assertSame(1, (int)Sql::value('SELECT COUNT(*) FROM design_previews WHERE order_id = ?', [$orderId]));

        // Друге прев'ю на основі першого — v2
        $second = $this->preview($orderId, $offerId, $designer, '', $p['id']);
        $this->assertSame(2, $second['preview']['num']);
        $this->assertSame(1, $second['preview']['based_on_num']);
        $this->assertSame('', $second['message']['text']);

        // «На основі» — лише версія цього замовлення
        [$code, $json] = $this->upload($orderId, $designer, 'preview', self::png(), 'x.png', ['based_on' => '999999999']);
        $this->assertSame(422, $code);
        $this->assertSame("Версію, на основі якої зроблено прев'ю, не знайдено", $json['error']);
        [$code, $json] = $this->upload($orderId, $designer, 'preview', self::png(), 'x.png', ['based_on' => '1 OR 1']);
        $this->assertSame(422, $code);

        // Не картинка — зрозуміла помилка, нічого не створено
        [$code, $json] = $this->upload($orderId, $designer, 'preview', str_repeat('не картинка ', 400), 'notes.txt');
        $this->assertSame(422, $code);
        $this->assertNotSame('', $json['error']);
        $this->assertSame(2, (int)Sql::value('SELECT COUNT(*) FROM design_previews WHERE order_id = ?', [$orderId]));

        // Заявлено більше за ліміт прев'ю — відмова вже на першій частині, частина не збережена
        $meta = ['fileId' => 'test' . bin2hex(random_bytes(8)), 'chunkIndex' => 0, 'totalChunks' => 40,
            'totalSize' => \Design\Settings::previewMaxBytes() + 1, 'originalName' => 'big.png'];
        [$code, $json] = $this->part($orderId, $designer, 'preview', $meta, 'abc');
        $this->assertSame(422, $code);
        $this->assertStringContainsString('МБ', $json['error']);
        $this->assertSame([], self::chunkParts(sha1("$designer:$orderId:preview|" . $meta['fileId'])));

        // Без CSRF — на будь-якій частині
        $meta = ['fileId' => 'test' . bin2hex(random_bytes(8)), 'totalChunks' => 2, 'totalSize' => 6, 'originalName' => 'a.png'];
        [$code] = $this->part($orderId, $designer, 'preview', ['chunkIndex' => 0] + $meta, 'abc');
        $this->assertSame(200, $code);
        [$code, $json] = $this->part($orderId, $designer, 'preview', ['chunkIndex' => 1] + $meta, 'def', [], '');
        $this->assertSame(403, $code);
        $this->assertSame('Сесія застаріла — оновіть сторінку', $json['error']);
        // Невідоме призначення
        [$code] = $this->part($orderId, $designer, 'avatar', ['chunkIndex' => 1] + $meta, 'def');
        $this->assertSame(422, $code);
    }

    public function test_client_cannot_upload(): void
    {
        $client = self::clientId();
        $designer = $this->designer();
        [$orderId, $offerId] = $this->inWork($client, $designer);
        $error = "Завантажувати прев'ю й макети може лише обраний дизайнер замовлення в роботі";

        foreach (['preview', 'layout'] as $purpose) {
            $meta = ['fileId' => 'test' . bin2hex(random_bytes(8)), 'chunkIndex' => 0, 'totalChunks' => 1, 'totalSize' => 3, 'originalName' => 'a.png'];
            [$code, $json] = $this->part($orderId, $client, $purpose, $meta, 'abc');
            $this->assertSame(403, $code, $purpose);
            $this->assertSame($error, $json['error']);
            $this->assertSame([], self::chunkParts(sha1("$client:$orderId:$purpose|" . $meta['fileId'])));
        }
        // Чуже замовлення (дизайнер не обраний) і замовлення не «В роботі» — так само
        [$foreign] = $this->inWork($client, 999999731);
        [$done] = $this->inWork($client, $designer, OrderStatus::PURCHASED);
        foreach ([$foreign, $done, 999999999] as $id) {
            $meta = ['fileId' => 'test' . bin2hex(random_bytes(8)), 'chunkIndex' => 0, 'totalChunks' => 1, 'totalSize' => 3, 'originalName' => 'a.png'];
            [$code, $json] = $this->part($id, $designer, 'preview', $meta, 'abc');
            $this->assertSame(403, $code, "замовлення $id");
            $this->assertSame($error, $json['error']);
        }
        // Дизайнер, якого заблокували посеред завантаження: друга частина — відмова
        $meta = ['fileId' => 'test' . bin2hex(random_bytes(8)), 'totalChunks' => 2, 'totalSize' => 6, 'originalName' => 'a.png'];
        [$code] = $this->part($orderId, $designer, 'preview', ['chunkIndex' => 0] + $meta, 'abc');
        $this->assertSame(200, $code);
        Sql::exec('UPDATE design_designers SET status = ? WHERE customer_id = ?', [DesignerStatus::BLOCKED, $designer]);
        [$code, $json] = $this->part($orderId, $designer, 'preview', ['chunkIndex' => 1] + $meta, 'def');
        $this->assertSame(403, $code);
        $this->assertSame($error, $json['error']);
        $this->assertSame(0, (int)Sql::value('SELECT COUNT(*) FROM design_previews WHERE order_id IN (?, ?, ?)', [$orderId, $foreign, $done]));
        $this->assertNull(Sql::value('SELECT id FROM design_messages WHERE offer_id = ?', [$offerId]));
    }

    public function test_layout_upload_and_replace_through_same_endpoint(): void
    {
        $client = self::clientId();
        $designer = $this->designer();
        [$orderId, $offerId] = $this->inWork($client, $designer);
        $bytes = random_bytes(300000);
        [$code, $json] = $this->upload($orderId, $designer, 'layout', $bytes, 'Візитка 1.pdf');
        $this->assertSame(200, $code, json_encode($json, JSON_UNESCAPED_UNICODE));
        $l = $json['layout'];
        $this->assertSame(['label' => 'Візитка 1', 'original_name' => 'Візитка 1.pdf', 'size' => 300000, 'ext' => 'pdf'],
            array_intersect_key($l, ['label' => 1, 'original_name' => 1, 'size' => 1, 'ext' => 1]));
        $file = Paths::root() . "/layouts/$orderId/{$l['id']}.pdf";
        $this->assertSame($bytes, file_get_contents($file));
        $this->assertSame('0600', substr(sprintf('%o', fileperms($file)), -4));
        $this->assertSame('0700', substr(sprintf('%o', fileperms(dirname($file))), -4));
        $this->assertStringNotContainsString('fastcdn', json_encode($json));

        // Заміна — з тим самим підписом; чужий / неіснуючий file_id — відмова на першій частині
        self::inChat($offerId);
        [$code, $json] = $this->upload($orderId, $designer, 'replace', random_bytes(1000), 'v2.pdf', ['file_id' => (string)$l['id']]);
        $this->assertSame(200, $code, json_encode($json, JSON_UNESCAPED_UNICODE));
        $this->assertSame('Візитка 1', $json['layout']['label']);
        $this->assertFileDoesNotExist($file);
        foreach ([(string)$l['id'], '999999999', '', 'abc'] as $bad) {
            $meta = ['fileId' => 'test' . bin2hex(random_bytes(8)), 'chunkIndex' => 0, 'totalChunks' => 2, 'totalSize' => 6, 'originalName' => 'a.pdf'];
            [$code, $json] = $this->part($orderId, $designer, 'replace', $meta, 'abc', ['file_id' => $bad]);
            $this->assertSame(422, $code, "file_id $bad");
            $this->assertSame('Макет не знайдено: його вже замінено або видалено', $json['error']);
            $this->assertSame([], self::chunkParts(sha1("$designer:$orderId:replace|" . $meta['fileId'])));
        }
        // Заявлено більше за ліміт макета — відмова на першій частині
        $meta = ['fileId' => 'test' . bin2hex(random_bytes(8)), 'chunkIndex' => 0, 'totalChunks' => 2,
            'totalSize' => \Design\Settings::layoutMaxBytes() + 1, 'originalName' => 'a.pdf'];
        [$code, $json] = $this->part($orderId, $designer, 'layout', $meta, 'abc');
        $this->assertSame(422, $code);
        $this->assertStringContainsString('це максимум для одного макета', $json['error']);
    }

    public function test_preview_card_and_quote_render(): void
    {
        $client = self::clientId();
        $designer = $this->designer();
        [$orderId, $offerId] = $this->inWork($client, $designer);
        $v1 = $this->preview($orderId, $offerId, $designer, 'Темний фон <script>alert(1)</script>');
        [$code, $json] = self::send($offerId, $client, 'Рамку краще прибрати', (string)$v1['message']['id']);
        $this->assertSame(200, $code, json_encode($json, JSON_UNESCAPED_UNICODE));

        [$code, , $html] = self::fetch("/orders/$orderId/", $client);
        $this->assertSame(200, $code);
        // Картка прев'ю: номер, картинка картки, підпис екранований, «Коментувати», «Відкрити»
        $this->assertStringContainsString('data-preview="' . $v1['preview']['id'] . '"', $html);
        $this->assertMatchesRegularExpression('#<span class="pv-num">v1</span>#u', $html);
        $this->assertStringContainsString('src="' . htmlspecialchars($v1['preview']['urls'][0]['card']) . '"', $html);
        $this->assertStringContainsString('Темний фон &lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringContainsString('Коментувати', $html);
        $this->assertStringContainsString('Відкрити', $html);
        // Цитата над відповіддю клієнта
        $this->assertMatchesRegularExpression('#class="quote"[^>]*>.*Коментар до v1#su', $html);
        // Перегляд, але без додавання прев'ю й без «Приховати» у клієнта
        $this->assertStringContainsString('data-pv-viewer', $html);
        $this->assertStringNotContainsString('data-pv-add', $html);
        $this->assertStringNotContainsString('data-pv-hide', $html);
        $this->assertStringContainsString('/assets/js/workspace.js', $html);

        // Дизайнер: кнопка «додати прев'ю», розмір частини, «Приховати» в перегляді
        [$code, , $html] = self::fetch("/feed/$orderId/", $designer);
        $this->assertSame(200, $code);
        $this->assertStringContainsString('data-pv-add', $html);
        $this->assertMatchesRegularExpression('#data-chunk="(\d+)"#', $html);
        preg_match('#data-chunk="(\d+)"#', $html, $mm);
        $this->assertGreaterThan(0, (int)$mm[1]);
        $this->assertLessThanOrEqual(5 * 1048576 - 65536, (int)$mm[1]);
        $this->assertStringContainsString('data-pv-hide', $html);
        $this->assertStringContainsString('Темний фон &lt;script&gt;', $html);
        $this->assertStringContainsString('Коментар до v1', $html);

        // Опитування віддає те саме: прев'ю й цитату
        [, , $body] = self::fetch("/offers/$offerId/messages?after=0", $client);
        $list = json_decode($body, true)['messages'];
        $this->assertSame($v1['preview']['id'], $list[0]['preview']['id']);
        $this->assertSame(['id' => $v1['message']['id'], 'author' => self::DESIGNER_NAME, 'text' => 'Темний фон <script>alert(1)</script>',
            'preview_num' => 1, 'thumb' => $v1['preview']['urls'][0]['card']], $list[1]['reply']);
    }

    public function test_hide_preview(): void
    {
        $client = self::clientId();
        $designer = $this->designer();
        [$orderId, $offerId] = $this->inWork($client, $designer);
        $v1 = $this->preview($orderId, $offerId, $designer, 'Перший ескіз');
        $id = $v1['preview']['id'];
        $this->assertCount(2, self::previewFiles($orderId, $id));

        // Клієнт, без CSRF, невідома дія, чуже замовлення — відмова; нічого не змінено
        [$code, $json] = self::hide($orderId, $id, $client);
        $this->assertSame(403, $code);
        $this->assertSame("Приховувати прев'ю може лише обраний дизайнер замовлення в роботі", $json['error']);
        [$code] = self::hide($orderId, $id, $designer, '');
        $this->assertSame(403, $code);
        [$code] = self::hide($orderId, $id, $designer, null, 'delete');
        $this->assertSame(422, $code);
        [$other] = $this->inWork($client, $designer);
        [$code] = self::hide($other, $id, $designer);
        $this->assertSame(403, $code, "прев'ю іншого замовлення");
        $this->assertNull(Sql::value('SELECT hidden_at FROM design_previews WHERE id = ?', [$id]));
        $this->assertCount(2, self::previewFiles($orderId, $id));

        [$code, $json] = self::hide($orderId, $id, $designer);
        $this->assertSame(200, $code, json_encode($json, JSON_UNESCAPED_UNICODE));
        $this->assertTrue($json['preview']['hidden']);
        $this->assertSame([], $json['preview']['urls']);
        $this->assertSame('Перший ескіз', $json['preview']['caption']);
        $this->assertNotNull(Sql::value('SELECT hidden_at FROM design_previews WHERE id = ?', [$id]));
        $this->assertSame([], self::previewFiles($orderId, $id));
        // Повтор — без змін
        [$code] = self::hide($orderId, $id, $designer);
        $this->assertSame(200, $code);

        [, , $html] = self::fetch("/orders/$orderId/", $client);
        $this->assertStringContainsString('Прев\'ю приховано дизайнером · v1', $html);
        $this->assertStringContainsString('Перший ескіз', $html);
        // На приховане не можна спертися як на «на основі»
        [$code, $json] = $this->upload($orderId, $designer, 'preview', self::png(), 'x.png', ['based_on' => (string)$id]);
        $this->assertSame(422, $code);
    }

    public function test_reply_to(): void
    {
        $client = self::clientId();
        $designer = $this->designer();
        [$orderId, $offerId] = $this->inWork($client, $designer);
        [, $q] = self::send($offerId, $client, 'А можна телефон зробити більшим?');
        [$code, $json] = self::send($offerId, $designer, 'Так, збільшив до 11 pt', (string)$q['message']['id']);
        $this->assertSame(200, $code);
        $this->assertSame(['id' => $q['message']['id'], 'author' => \Design\Customers::header($client)['short'],
            'text' => 'А можна телефон зробити більшим?', 'preview_num' => null, 'thumb' => null], $json['message']['reply']);
        $this->assertSame($q['message']['id'], (int)Sql::value('SELECT reply_to_id FROM design_messages WHERE id = ?', [$json['message']['id']]));

        // Довгий текст цитати — обрізаний
        [, $long] = self::send($offerId, $client, str_repeat('слово ', 60));
        [, $json] = self::send($offerId, $designer, 'Ок', (string)$long['message']['id']);
        $this->assertSame(120, mb_strlen($json['message']['reply']['text']));
        $this->assertStringEndsWith('…', $json['message']['reply']['text']);

        // Повідомлення іншого листування, неіснуюче, не число — відмова, нічого не записано
        [$otherOrder, $otherOffer] = $this->inWork($client, $designer);
        [, $foreign] = self::send($otherOffer, $client, 'Інше замовлення');
        $before = Messages::count($offerId);
        foreach ([(string)$foreign['message']['id'], '999999999', 'abc', '-1'] as $bad) {
            [$code, $json] = self::send($offerId, $client, 'Відповідь', $bad);
            $this->assertSame(422, $code, "reply_to $bad");
            $this->assertSame('Повідомлення, на яке ви відповідаєте, не знайдено', $json['error']);
        }
        $this->assertSame($before, Messages::count($offerId));

        // Сторінка: цитата повідомлення — ім'я автора й текст
        [, , $html] = self::fetch("/orders/$orderId/", $client);
        $this->assertMatchesRegularExpression('#class="quote"[^>]*>.*?А можна телефон зробити більшим\?#su', $html);
    }

    public function test_hidden_preview_urls_absent(): void
    {
        $client = self::clientId();
        $designer = $this->designer();
        [$orderId, $offerId] = $this->inWork($client, $designer);
        $v1 = $this->preview($orderId, $offerId, $designer, 'Перший');
        $v2 = $this->preview($orderId, $offerId, $designer, 'Другий', $v1['preview']['id']);
        self::send($offerId, $client, 'Коментар до першого', (string)$v1['message']['id']);
        $urls = array_merge(...array_map(static fn(array $u): array => array_values($u), $v1['preview']['urls']));
        $this->assertCount(2, $urls);
        [, , $html] = self::fetch("/orders/$orderId/", $client);
        foreach ($urls as $u) $this->assertStringContainsString($u, $html, 'до приховування адреси є');

        $this->assertSame(200, self::hide($orderId, $v1['preview']['id'], $designer)[0]);

        foreach ([$client, $designer] as $as) {
            [, , $html] = self::fetch($as === $client ? "/orders/$orderId/" : "/feed/$orderId/", $as);
            [, , $poll] = self::fetch("/offers/$offerId/messages?after=0", $as);
            foreach ($urls as $u) {
                $this->assertStringNotContainsString($u, $html, "HTML для $as");
                $this->assertStringNotContainsString(str_replace('/', '\/', $u), $html, "JSON у HTML для $as");
                $this->assertStringNotContainsString($u, $poll, "опитування для $as");
            }
            // v2 лишається з картинками; цитата прихованого — без мініатюри
            $this->assertStringContainsString($v2['preview']['urls'][0]['card'], $html);
            $list = json_decode($poll, true)['messages'];
            $this->assertSame([], $list[0]['preview']['urls']);
            $this->assertTrue($list[0]['preview']['hidden']);
            $this->assertNull($list[2]['reply']['thumb']);
            $this->assertSame(1, $list[2]['reply']['preview_num']);
        }
    }
}
