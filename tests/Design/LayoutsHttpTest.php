<?php

use Design\Designers;
use Design\DesignerStatus;
use Design\Layouts;
use Design\OfferStatus;
use Design\OrderStatus;
use Design\Paths;
use Design\Products;
use Design\Sql;
use Design\View;
use PHPUnit\Framework\TestCase;

/**
 * Макети на сторінці, шапка замовлення й «чий хід» по-справжньому через nginx dev.design.wolf.ua (етап 4, Task 7):
 * панель «Макети для друку» в обох сторін, POST /orders/{id}/layouts/{fileId} (rename | delete), GET /orders/{id}/layouts,
 * workspace в опитуванні листування, шапки, «чий хід» на сторінках і в «Мої замовлення» / «Мої пропозиції».
 * Клієнт — DESIGN_TEST_CUSTOMER_ID (29), дизайнер — DESIGN_TEST_DESIGNER_ID (25): тест робить 25 схваленим дизайнером (якщо
 * в нього вже є профіль — пропуск) і обраним на своєму замовленні «В роботі». Файли пише php-fpm у справжній Paths::root()
 * (на dev — /mnt/work/source.wolf.ua/design). Прибирання — як у WorkspaceHttpTest: рядки, папки замовлень, відповіді й частини
 * в /mnt/work/tmp/tmp_uppy, каталоги, яких до тесту не було, посилання i.fastcdn на свої прев'ю. Частини — менші за 1,5 МБ.
 *
 * @group http
 */
final class LayoutsHttpTest extends TestCase
{
    private const UA = 'DesignTest/1';
    private const DESIGNER_NAME = 'Тест Дизайнер';
    private const UPPY = '/mnt/work/tmp/tmp_uppy/';
    private const LINKS = '/home/connect/cloud-links/ci';
    private const STRANGER = 999999721;          // чужий клієнт (рядка в customers немає)
    private const STRANGER_DESIGNER = 999999722; // чужий дизайнер

    /** @var list<int> */
    private array $orders = [];
    private bool $ownsDesigner = false;
    /** @var list<string> */
    private array $keys = [];
    /** @var array<string, ?string> налаштування design_settings до тесту (null — рядка не було) */
    private array $settings = [];
    /** @var array<int, string> */
    private static array $csrf = [];
    /** @var list<string> */
    private static array $newDirs = [];
    /** @var array<string, true> */
    private static array $linkDirs = [];

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
        foreach ($this->settings as $key => $value) {
            $value === null
                ? Sql::exec('DELETE FROM design_settings WHERE `key` = ?', [$key])
                : Sql::exec('UPDATE design_settings SET `value` = ? WHERE `key` = ?', [$value, $key]);
        }
        $this->settings = [];
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
                foreach ($out as $l) unlink($l);
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

    /** Замовлення клієнта «В роботі» з обраною пропозицією дизайнера (1000 на руки, 1200 для клієнта) → [orderId, offerId] */
    private function inWork(int $client, int $designer): array
    {
        $orderId = Sql::insert('design_orders', [
            'customer_id' => $client,
            'product_id' => Products::visible()[0]['id'],
            'mode' => 1,
            'status' => OrderStatus::IN_WORK,
            'title' => 'Візитка для кавʼярні «Зерно»',
            'answers' => json_encode([['q' => 'Текст', 'a' => 'Тест макетів']], JSON_UNESCAPED_UNICODE),
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

    private static function inChat(int $offerId): void
    {
        Sql::exec('UPDATE design_offers SET client_seen_at = NOW(), designer_seen_at = NOW() WHERE id = ?', [$offerId]);
    }

    private static function cookie(int $id): string
    {
        $pass = (string)Sql::value('SELECT customer_pass FROM customers WHERE id = ?', [$id]);
        return 'wolfid=' . $id . '.' . hash_hmac('md5', $pass . self::UA, (string)$id);
    }

    /** @return array{0:int,1:string,2:string} код, заголовки, тіло */
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

    /** GET JSON → [код, масив] */
    private static function getJson(string $path, int $as): array
    {
        [$code, , $body] = self::fetch($path, $as);
        return [$code, (array)json_decode($body, true)];
    }

    /** Текст сторінки без тегів, з одинарними пробілами (НБСП — пробіл) */
    private static function text(string $html): string
    {
        $html = preg_replace('~<(script|style)\b.*?</\1>~su', ' ', $html);
        $t = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $t));
    }

    private static function csrf(int $as): string
    {
        if (!isset(self::$csrf[$as])) {
            [, , $html] = self::fetch('/brief/' . Products::visible()[0]['id'] . '/', $as);
            self::$csrf[$as] = preg_match('/name="csrf" value="([0-9a-f]{64})"/', $html, $m) ? $m[1] : '';
        }
        return self::$csrf[$as];
    }

    /** @return array{0:int,1:array} */
    private function part(int $orderId, int $as, string $purpose, array $meta, string $bytes, array $fields = []): array
    {
        $this->keys[] = sha1("$as:$orderId:$purpose|" . $meta['fileId']);
        $tmp = tempnam(sys_get_temp_dir(), 'lyt');
        file_put_contents($tmp, $bytes);
        $post = ['purpose' => $purpose, 'csrf' => self::csrf($as)] + $fields;
        foreach ($meta as $k => $v) $post["meta[$k]"] = (string)$v;
        $post['file'] = new CURLFile($tmp, 'application/octet-stream', 'blob');
        try {
            [$code, , $body] = self::fetch("/orders/$orderId/upload", $as, $post, true);
        } finally {
            unlink($tmp);
        }
        return [$code, (array)json_decode($body, true)];
    }

    /** Увесь файл частинами (кожна < 1,5 МБ) → [код, JSON останньої частини] */
    private function upload(int $orderId, int $as, string $purpose, string $bytes, string $name, array $fields = [], int $parts = 2): array
    {
        $size = strlen($bytes);
        $partSize = (int)ceil($size / $parts);
        $this->assertLessThan(1572864, $partSize);
        $meta = ['fileId' => 'test' . bin2hex(random_bytes(8)), 'totalChunks' => $parts, 'totalSize' => $size, 'originalName' => $name];
        for ($i = 0; $i < $parts; $i++) {
            [$code, $json] = $this->part($orderId, $as, $purpose, ['chunkIndex' => $i] + $meta, substr($bytes, $i * $partSize, $partSize), $fields);
            if ($i < $parts - 1 && $code !== 200) return [$code, $json];
        }
        return [$code, $json];
    }

    /** Макет від дизайнера → layout з відповіді */
    private function layout(int $orderId, int $offerId, int $designer, string $name, int $size = 200000): array
    {
        self::inChat($offerId);
        [$code, $json] = $this->upload($orderId, $designer, 'layout', random_bytes($size), $name);
        $this->assertSame(200, $code, json_encode($json, JSON_UNESCAPED_UNICODE));
        return $json['layout'];
    }

    /** Прев'ю (PNG) від дизайнера → JSON останньої частини */
    private function preview(int $orderId, int $offerId, int $designer): array
    {
        $im = new Imagick();
        $im->newImage(400, 300, new ImagickPixel('#3b2a1f'));
        $im->setImageFormat('png');
        $png = $im->getImageBlob();
        $im->clear();
        self::inChat($offerId);
        [$code, $json] = $this->upload($orderId, $designer, 'preview', $png, 'zerno.png', ['caption' => 'Перший', 'based_on' => ''], 1);
        $this->assertSame(200, $code, json_encode($json, JSON_UNESCAPED_UNICODE));
        return $json;
    }

    /** POST /orders/{id}/layouts/{fileId} → [код, JSON] */
    private static function act(int $orderId, int $fileId, int $as, array $post): array
    {
        [$code, , $body] = self::fetch("/orders/$orderId/layouts/$fileId", $as, $post + ['csrf' => self::csrf($as)]);
        return [$code, (array)json_decode($body, true)];
    }

    private static function send(int $offerId, int $as, string $text): array
    {
        self::inChat($offerId);
        [$code, , $body] = self::fetch("/offers/$offerId/messages", $as, ['text' => $text, 'csrf' => self::csrf($as)]);
        return [$code, (array)json_decode($body, true)];
    }

    /** Тимчасово змінює налаштування design_settings (відновлює tearDown) */
    private function setting(string $key, string $value): void
    {
        if (!array_key_exists($key, $this->settings)) {
            $old = Sql::value('SELECT `value` FROM design_settings WHERE `key` = ?', [$key]);
            $this->settings[$key] = $old === null ? null : (string)$old;
        }
        Sql::exec('INSERT INTO design_settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)', [$key, $value]);
    }

    public function test_upload_layout_and_client_sees_list(): void
    {
        $client = self::clientId();
        $designer = $this->designer();
        [$orderId, $offerId] = $this->inWork($client, $designer);

        // Порожня панель: у клієнта — «ще не завантажив», «Купую» неактивна з «Незабаром»
        [$code, , $html] = self::fetch("/orders/$orderId/", $client);
        $this->assertSame(200, $code);
        $this->assertStringContainsString('data-layouts', $html);
        $this->assertStringContainsString('Дизайнер ще не завантажив макети', $html);
        $this->assertMatchesRegularExpression('~<button[^>]*class="[^"]*ws-buy[^"]*"[^>]*disabled~', $html);
        $text = self::text($html);
        $this->assertStringContainsString('Купую Незабаром', $text);
        $this->assertStringContainsString('Ціна 1 200 грн', $text);
        $this->assertStringContainsString('Термін до ' . View::date(date('Y-m-d', strtotime('+3 day'))), $text);
        $this->assertStringContainsString('Дизайнер ' . self::DESIGNER_NAME, $text);
        $this->assertStringContainsString('Макети відкриються після покупки. Зараз видно назву, розмір і підпис.', $text);
        foreach (['data-lp-pick', 'data-lp-delete', 'data-lp-rename', 'data-lp-replace', 'data-ws-upload', 'Завантажити макети'] as $s) {
            $this->assertStringNotContainsString($s, $html, "клієнт не має $s");
        }

        // Дизайнер завантажує два макети; у його панелі — керування і підказка
        $a = $this->layout($orderId, $offerId, $designer, 'Візитка 1 — лицьова.pdf', 1258291);
        $b = $this->layout($orderId, $offerId, $designer, 'zerno-back.ai', 300000);
        [$code, , $html] = self::fetch("/feed/$orderId/", $designer);
        $this->assertSame(200, $code);
        foreach (['data-lp-pick', 'data-lp-drop', 'data-lp-delete', 'data-lp-rename', 'data-lp-replace', 'data-ws-upload'] as $s) {
            $this->assertStringContainsString($s, $html, "дизайнер має $s");
        }
        $text = self::text($html);
        $this->assertStringContainsString('Макети для друку', $text);
        $this->assertStringContainsString('Перетягніть файли сюди або Обрати файли', $text);
        $this->assertStringContainsString('До покупки можна замінити чи видалити файл. Клієнт бачить лише назву, розмір і підпис.', $text);
        $this->assertStringContainsString('Візитка 1 — лицьова', $text);

        // Клієнт бачить список: підпис, ім'я файла, розмір; без дій
        [, , $html] = self::fetch("/orders/$orderId/", $client);
        $text = self::text($html);
        $this->assertStringContainsString('Візитка 1 — лицьова Візитка 1 — лицьова.pdf · 1,2 МБ', $text);
        $this->assertStringContainsString('zerno-back zerno-back.ai · 293 КБ', $text);
        $this->assertStringContainsString('Макети · 2', $text);
        $this->assertStringContainsString('data-lp-empty hidden', $html);
        $this->assertStringNotContainsString('data-lp-delete', $html);
        // Слід у стрічці
        $this->assertStringContainsString('Завантажено макет «zerno-back» · 293 КБ', $text);

        // GET /orders/{id}/layouts — той самий список і версія, що в опитуванні листування
        [$code, $panel] = self::getJson("/orders/$orderId/layouts", $client);
        $this->assertSame(200, $code);
        $this->assertSame(2, $panel['count']);
        $this->assertSame([$a['id'], $b['id']], array_column($panel['layouts'], 'id'));
        $this->assertSame(['id', 'label', 'original_name', 'size', 'size_text', 'ext'], array_keys($panel['layouts'][0]));
        $this->assertSame('293 КБ', str_replace("\u{00A0}", ' ', $panel['layouts'][1]['size_text']));
        $this->assertSame('Макети завантажено — перегляньте список. Купівля — незабаром', $panel['turn']['text']);
        [, $poll] = self::getJson("/offers/$offerId/messages?after=0", $client);
        $this->assertSame($panel['rev'], $poll['workspace']['layouts_rev']);
        $this->assertSame('Макети завантажено — перегляньте список. Купівля — незабаром', $poll['workspace']['turn']['text']);
        $this->assertSame('Дизайнер завантажив 2 макети. Файли відкриються після покупки', $poll['workspace']['turn']['sub']);
        [$code, $d] = self::getJson("/orders/$orderId/layouts", $designer);
        $this->assertSame(200, $code);
        $this->assertSame($panel['layouts'], $d['layouts']);
        // Чуже замовлення (інший клієнт і дизайнер) — 404 без даних і для клієнта, і для дизайнера
        [$foreign] = $this->inWork(self::STRANGER, self::STRANGER_DESIGNER);
        foreach ([$client, $designer] as $as) {
            [$code, $none] = self::getJson("/orders/$foreign/layouts", $as);
            $this->assertSame(404, $code);
            $this->assertSame(['error' => 'Замовлення не знайдено'], $none);
        }

        // Новий макет змінює версію — клієнт перечитає панель без перезавантаження
        $this->layout($orderId, $offerId, $designer, 'third.pdf', 1000);
        [, $poll2] = self::getJson("/offers/$offerId/messages?after=0", $client);
        $this->assertNotSame($poll['workspace']['layouts_rev'], $poll2['workspace']['layouts_rev']);
        $this->assertSame(3, self::getJson("/orders/$orderId/layouts", $client)[1]['count']);
    }

    public function test_rename_replace_delete(): void
    {
        $client = self::clientId();
        $designer = $this->designer();
        [$orderId, $offerId] = $this->inWork($client, $designer);
        $l = $this->layout($orderId, $offerId, $designer, 'Візитка.pdf');
        $messages = static fn(): int => (int)Sql::value('SELECT COUNT(*) FROM design_messages WHERE offer_id = ?', [$offerId]);
        $before = $messages();
        $rev = self::getJson("/orders/$orderId/layouts", $designer)[1]['rev'];

        // Перейменування: новий підпис і версія, без повідомлення в стрічці
        [$code, $json] = self::act($orderId, $l['id'], $designer, ['action' => 'rename', 'label' => '  Візитка 1 — <b>лицьова</b>  ']);
        $this->assertSame(200, $code, json_encode($json, JSON_UNESCAPED_UNICODE));
        $this->assertSame('Візитка 1 — <b>лицьова</b>', $json['layouts'][0]['label']);
        $this->assertNotSame($rev, $json['rev']);
        $this->assertSame("Ваш хід: покажіть перше прев'ю", $json['turn']['text']);
        $this->assertSame($before, $messages());
        [, , $html] = self::fetch("/orders/$orderId/", $client);
        $this->assertStringContainsString('Візитка 1 — &lt;b&gt;лицьова&lt;/b&gt;', $html);
        // Невірні підписи, чужі руки, без CSRF, невідома дія
        foreach (['', '   ', str_repeat('я', Layouts::LABEL_MAX + 1)] as $bad) {
            [$code, $json] = self::act($orderId, $l['id'], $designer, ['action' => 'rename', 'label' => $bad]);
            $this->assertSame(422, $code);
            $this->assertSame('Підпис макета — від 1 до 120 символів', $json['error']);
        }
        [$code, $json] = self::act($orderId, $l['id'], $client, ['action' => 'rename', 'label' => 'Клієнт']);
        $this->assertSame(403, $code);
        $this->assertSame('Змінювати макети може лише обраний дизайнер замовлення в роботі', $json['error']);
        [$code, $json] = self::act($orderId, $l['id'], $designer, ['action' => 'rename', 'label' => 'x', 'csrf' => '']);
        $this->assertSame(403, $code);
        $this->assertSame('Сесія застаріла — оновіть сторінку', $json['error']);
        [$code, $json] = self::act($orderId, $l['id'], $designer, ['action' => 'download']);
        $this->assertSame(422, $code);
        $this->assertSame('Невідома дія', $json['error']);
        // Макет іншого замовлення (того ж дизайнера) через адресу цього — «не знайдено», нічого не змінено
        [$other, $otherOffer] = $this->inWork($client, $designer);
        $foreign = $this->layout($other, $otherOffer, $designer, 'Чужий.pdf', 1000);
        foreach (['rename', 'delete'] as $action) {
            [$code, $json] = self::act($orderId, $foreign['id'], $designer, ['action' => $action, 'label' => 'Зламано']);
            $this->assertSame(422, $code, $action);
            $this->assertSame('Макет не знайдено: його вже замінено або видалено', $json['error']);
        }
        $row = Sql::one('SELECT label, status FROM design_files WHERE id = ?', [$foreign['id']]);
        $this->assertSame(['Чужий', Layouts::ACTIVE], [$row['label'], (int)$row['status']]);
        $this->assertSame('Візитка 1 — <b>лицьова</b>', Sql::value('SELECT label FROM design_files WHERE id = ?', [$l['id']]));

        // Заміна — через той самий endpoint завантаження: новий рядок з тим самим підписом, старий — «замінено», файл видалено
        $oldFile = Paths::root() . "/layouts/$orderId/{$l['id']}.pdf";
        $this->assertFileExists($oldFile);
        self::inChat($offerId);
        [$code, $json] = $this->upload($orderId, $designer, 'replace', random_bytes(5000), 'Візитка v2.pdf', ['file_id' => (string)$l['id']]);
        $this->assertSame(200, $code, json_encode($json, JSON_UNESCAPED_UNICODE));
        $new = $json['layout'];
        $this->assertSame('Візитка 1 — <b>лицьова</b>', $new['label']);
        $this->assertSame(Layouts::REPLACED, (int)Sql::value('SELECT status FROM design_files WHERE id = ?', [$l['id']]));
        $this->assertFileDoesNotExist($oldFile);
        [, $panel] = self::getJson("/orders/$orderId/layouts", $client);
        $this->assertSame([$new['id']], array_column($panel['layouts'], 'id'));
        $this->assertSame('Візитка v2.pdf', $panel['layouts'][0]['original_name']);
        // Дія із заміненим — «не знайдено»
        [$code, $json] = self::act($orderId, $l['id'], $designer, ['action' => 'delete']);
        $this->assertSame(422, $code);
        $this->assertSame('Макет не знайдено: його вже замінено або видалено', $json['error']);

        // Видалення: status 3, файл з диска, слід у стрічці, порожня панель
        self::inChat($offerId);
        [$code, $json] = self::act($orderId, $new['id'], $designer, ['action' => 'delete']);
        $this->assertSame(200, $code, json_encode($json, JSON_UNESCAPED_UNICODE));
        $this->assertSame(['layouts' => [], 'count' => 0], array_intersect_key($json, ['layouts' => 1, 'count' => 1]));
        $this->assertSame(Layouts::DELETED, (int)Sql::value('SELECT status FROM design_files WHERE id = ?', [$new['id']]));
        $this->assertFileDoesNotExist(Paths::root() . "/layouts/$orderId/{$new['id']}.pdf");
        [, $poll] = self::getJson("/offers/$offerId/messages?after=0", $client);
        $texts = array_column($poll['messages'], 'text');
        $this->assertContains('Макет «Візитка 1 — <b>лицьова</b>» замінено', $texts);
        $this->assertContains('Макет «Візитка 1 — <b>лицьова</b>» видалено', $texts);
        $this->assertSame('Дизайнер працює над макетами', $poll['workspace']['turn']['text']);
        [$code] = self::act($orderId, $new['id'], $designer, ['action' => 'delete']);
        $this->assertSame(422, $code);
    }

    public function test_layout_has_no_public_link(): void
    {
        $client = self::clientId();
        $designer = $this->designer();
        [$orderId, $offerId] = $this->inWork($client, $designer);
        self::inChat($offerId);
        [$code, $upload] = $this->upload($orderId, $designer, 'layout', random_bytes(4000), 'Секрет.pdf');
        $this->assertSame(200, $code);
        $id = $upload['layout']['id'];
        $root = Paths::root();
        [$code, $renamed] = self::act($orderId, $id, $designer, ['action' => 'rename', 'label' => 'Секрет 2']);
        $this->assertSame(200, $code);
        $outputs = [
            'rename' => json_encode($renamed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'upload' => json_encode($upload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'client html' => self::fetch("/orders/$orderId/", $client)[2],
            'designer html' => self::fetch("/feed/$orderId/", $designer)[2],
            'client layouts' => self::fetch("/orders/$orderId/layouts", $client)[2],
            'designer layouts' => self::fetch("/orders/$orderId/layouts", $designer)[2],
            'client poll' => self::fetch("/offers/$offerId/messages?after=0", $client)[2],
            'designer poll' => self::fetch("/offers/$offerId/messages?after=0", $designer)[2],
        ];
        foreach ($outputs as $where => $out) {
            $this->assertStringContainsString('Секрет', $out, $where);
            foreach (['fastcdn', '/layouts/' . $orderId, "$id.pdf", $root, 'href="/orders/' . $orderId . '/layouts'] as $needle) {
                $this->assertStringNotContainsString($needle, $out, "$where: $needle");
                $this->assertStringNotContainsString(str_replace('/', '\/', $needle), $out, "$where: $needle (JSON)");
            }
        }
        // Жодних посилань i.fastcdn на папку макетів
        exec('find ' . escapeshellarg(self::LINKS) . ' -type l -lname ' . escapeshellarg("$root/layouts/*"), $links);
        $this->assertSame([], $links);
        // Звичайний файл 0644 (посилання на нього все одно немає — перевірено вище)
        $this->assertSame('0644', substr(sprintf('%o', fileperms("$root/layouts/$orderId/$id.pdf")), -4));
    }

    public function test_limits_messages(): void
    {
        $client = self::clientId();
        $designer = $this->designer();
        [$orderId, $offerId] = $this->inWork($client, $designer);

        // Один макет більший за layout_max_mb — відмова на першій частині (за заявленим розміром)
        $this->setting('layout_max_mb', '1');
        $meta = ['fileId' => 'test' . bin2hex(random_bytes(8)), 'chunkIndex' => 0, 'totalChunks' => 2, 'totalSize' => 1048577, 'originalName' => 'big.tif'];
        [$code, $json] = $this->part($orderId, $designer, 'layout', $meta, 'abc');
        $this->assertSame(422, $code);
        $this->assertSame('Файл більший за 1 МБ — це максимум для одного макета', str_replace("\u{00A0}", ' ', $json['error']));
        [, , $html] = self::fetch("/feed/$orderId/", $designer);
        $this->assertStringContainsString('data-max="1048576"', $html);
        $this->assertStringContainsString('до 1 МБ кожен', self::text($html));

        // Кількість: layout_max_files
        $this->setting('layout_max_files', '1');
        $this->layout($orderId, $offerId, $designer, 'one.pdf', 1000);
        $meta = ['fileId' => 'test' . bin2hex(random_bytes(8)), 'chunkIndex' => 0, 'totalChunks' => 1, 'totalSize' => 3, 'originalName' => 'two.pdf'];
        [$code, $json] = $this->part($orderId, $designer, 'layout', $meta, 'abc');
        $this->assertSame(422, $code);
        $this->assertSame('Макетів уже 1 — це максимум', $json['error']);

        // Сума: layout_max_total_mb
        $this->setting('layout_max_files', '20');
        $this->setting('layout_max_total_mb', '1');
        $meta = ['fileId' => 'test' . bin2hex(random_bytes(8)), 'chunkIndex' => 0, 'totalChunks' => 1, 'totalSize' => 1048000, 'originalName' => 'two.pdf'];
        [$code, $json] = $this->part($orderId, $designer, 'layout', $meta, 'abc');
        $this->assertSame(422, $code);
        $this->assertSame('Разом не більше 1 МБ на замовлення', str_replace("\u{00A0}", ' ', $json['error']));
        $this->assertSame(1, (int)Sql::value('SELECT COUNT(*) FROM design_files WHERE order_id = ?', [$orderId]));
    }

    public function test_turn_texts(): void
    {
        $client = self::clientId();
        $designer = $this->designer();
        [$orderId, $offerId] = $this->inWork($client, $designer);
        $designerTurn = function () use ($offerId, $orderId, $designer): string {
            [, $poll] = self::getJson("/offers/$offerId/messages?after=0", $designer);
            [, , $html] = self::fetch("/feed/$orderId/", $designer);
            $this->assertStringContainsString($poll['workspace']['turn']['text'], self::text($html));
            [, , $list] = self::fetch('/my-offers/?tab=work', $designer);
            $this->assertStringContainsString($poll['workspace']['turn']['text'], self::text($list));
            return $poll['workspace']['turn']['text'];
        };
        $clientTurn = function () use ($offerId, $orderId, $client): string {
            [, $poll] = self::getJson("/offers/$offerId/messages?after=0", $client);
            [, , $html] = self::fetch("/orders/$orderId/", $client);
            $this->assertStringContainsString($poll['workspace']['turn']['text'], self::text($html));
            [, , $list] = self::fetch('/orders/', $client);
            $this->assertMatchesRegularExpression('~href="/orders/' . $orderId . '/".*?' . preg_quote(View::e($poll['workspace']['turn']['text']), '~') . '~s', $list);
            return $poll['workspace']['turn']['text'];
        };

        // Шапка дизайнера: «Ви отримаєте D грн · клієнт побачить P грн», «Термін до …», без «Змінити» і «Заробіток»
        [, , $html] = self::fetch("/feed/$orderId/", $designer);
        $text = self::text($html);
        $this->assertStringContainsString('Ви отримаєте 1 000 грн · клієнт побачить 1 200 грн', $text);
        $this->assertStringContainsString('Термін до ' . View::date(date('Y-m-d', strtotime('+3 day'))), $text);
        $this->assertStringContainsString('Завантажити макети', $text);
        $this->assertStringContainsString('Клієнт ', $text);
        foreach (['Змінити', 'Заробіток', '★'] as $s) $this->assertStringNotContainsString($s, $text);

        $this->assertSame("Ваш хід: покажіть перше прев'ю", $designerTurn());
        $this->assertSame('Дизайнер працює над макетами', $clientTurn());

        [$code, $sent] = self::send($offerId, $client, 'Добрий день');
        $this->assertSame(200, $code);
        $this->assertSame('Ваш хід: клієнт написав · відповідайте', $designerTurn());

        $pv = $this->preview($orderId, $offerId, $designer);
        $this->assertSame("Коли клієнт погодить прев'ю — завантажте макети", $designerTurn());

        self::send($offerId, $client, 'Подобається');
        $this->assertSame('Ваш хід: клієнт написав · відповідайте', $designerTurn());
        self::send($offerId, $designer, 'Дякую, вантажу');
        $this->layout($orderId, $offerId, $designer, 'Візитка.pdf', 1000);
        $this->assertSame('Макети завантажено · чекаємо, поки клієнт натисне «Купую»', $designerTurn());
        $this->assertSame('Макети завантажено — перегляньте список. Купівля — незабаром', $clientTurn());

        // Приховане прев'ю доходить до клієнта опитуванням; без видимих прев'ю — знову «покажіть перше прев'ю»
        [, $poll] = self::getJson("/offers/$offerId/messages?after=0", $client);
        $this->assertSame([], $poll['workspace']['hidden_previews']);
        [$code] = self::fetch("/orders/$orderId/previews/{$pv['preview']['id']}", $designer, ['action' => 'hide', 'csrf' => self::csrf($designer)]);
        $this->assertSame(200, $code);
        [, $poll] = self::getJson("/offers/$offerId/messages?after=" . $sent['message']['id'], $client);
        $this->assertSame([$pv['preview']['id']], $poll['workspace']['hidden_previews']);
        $this->assertSame("Ваш хід: покажіть перше прев'ю", $designerTurn());
    }
}
