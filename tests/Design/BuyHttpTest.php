<?php

use Design\Designers;
use Design\DesignerStatus;
use Design\OfferStatus;
use Design\OrderStatus;
use Design\Paths;
use Design\Products;
use Design\Sql;
use PHPUnit\Framework\TestCase;

/**
 * «Купую» по-справжньому через nginx dev.design.wolf.ua (етап 5, Task 4): вікно оплати на сторінці клієнта,
 * GET /orders/{id}/buy (свіжа сума для вікна), POST /orders/{id}/buy, куплені макети в панелі («Завантажити» —
 * FileGlobal::encode, cloud-links/cf), заборона змінювати куплене.
 * Клієнт — DESIGN_TEST_CUSTOMER_ID (29), дизайнер — DESIGN_TEST_DESIGNER_ID (25): тест робить 25 схваленим дизайнером (якщо
 * в нього вже є профіль — пропуск) і обраним на своєму замовленні «В роботі» (1 000 на руки, 1 200 для клієнта).
 * Справжні гроші на dev: тест ставить 29/25 потрібні inv_sum / credit / overdraft_*, а в tearDown (і при фатальній помилці —
 * функцією завершення) повертає inv_sum, credit, credit_ra, temp_credit_ra, parent_id, overdraft_start, overdraft_days як
 * було й видаляє свої рядки transaction (id > max до тесту, paymethod '[design]%').
 * Файли макетів пише php-fpm (www-data) у справжній Paths::root(); симлінки cloud-links/cf на них — теж php-fpm: тест
 * прибирає симлінки на макети своїх замовлень і порожні каталоги шардів, яких до тесту не було. Сповіщення «клієнт купив»
 * php-fpm кладе в dev api2 (n_smser) — тест прибирає ці рядки своїх замовлень. Частини — менші за 1,5 МБ.
 *
 * @group http
 */
final class BuyHttpTest extends TestCase
{
    private const UA = 'DesignTest/1';
    private const DESIGNER_NAME = 'Тест Дизайнер купівлі';
    private const UPPY = '/mnt/work/tmp/tmp_uppy/';
    private const LINKS = ['/home/connect/cloud-links/ci', '/home/connect/cloud-links/cf'];
    private const CF = '/home/connect/cloud-links/cf';
    private const MONEY_COLS = ['inv_sum', 'credit', 'credit_ra', 'temp_credit_ra', 'parent_id', 'overdraft_start', 'overdraft_days'];
    private const NB = "\u{00A0}";

    /** @var list<int> */
    private array $orders = [];
    /** @var list<int> замовлення, де могло піти сповіщення «клієнт купив» */
    private array $bought = [];
    private bool $ownsDesigner = false;
    /** @var list<string> */
    private array $keys = [];
    /** @var array<int, array> рядки customers 29/25 до тесту */
    private array $saved = [];
    private int $maxTransaction = 0;
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
        self::$linkDirs = array_fill_keys(self::linkDirs(), true);
    }

    public static function tearDownAfterClass(): void
    {
        // Порожні каталоги шардів cloud-links, створені php-fpm для посилань тестів
        $new = array_filter(self::linkDirs(), static fn(string $d): bool => !isset(self::$linkDirs[$d]));
        usort($new, static fn($a, $b) => substr_count($b, '/') <=> substr_count($a, '/'));
        foreach ($new as $d) if (count(scandir($d)) === 2) @rmdir($d);
        foreach (array_reverse(self::$newDirs) as $dir) {
            if (is_dir($dir) && count(scandir($dir)) === 2) @rmdir($dir);
        }
    }

    /** @return list<string> */
    private static function linkDirs(): array
    {
        $dirs = [];
        foreach (self::LINKS as $root) exec('find ' . escapeshellarg($root) . ' -mindepth 1 -maxdepth 3 -type d', $dirs);
        return $dirs;
    }

    protected function setUp(): void
    {
        $client = self::clientId();
        $designer = self::designerId();
        $this->maxTransaction = (int)Sql::value('SELECT COALESCE(MAX(id), 0) FROM transaction');
        foreach ([$client, $designer] as $id) {
            $row = Sql::one('SELECT ' . implode(', ', self::MONEY_COLS) . ' FROM customers WHERE id = ?', [$id]);
            if ($row === null) self::markTestSkipped("Клієнта $id немає в customers");
            $this->saved[$id] = $row;
        }
        // Фатальна помилка PHP не дійде до tearDown — гроші 29/25 повертає ще й завершення процесу (після tearDown — нічого)
        register_shutdown_function(function (): void {
            if (!$this->saved) return;
            try {
                $this->restoreMoney();
            } catch (\Throwable) {
            }
        });
    }

    private function restoreMoney(): void
    {
        if (!$this->saved) return;
        foreach ($this->saved as $id => $row) {
            Sql::exec('UPDATE customers SET ' . implode(', ', array_map(static fn($c) => "$c = ?", self::MONEY_COLS)) . ' WHERE id = ?',
                [...array_values($row), $id]);
        }
        Sql::exec("DELETE FROM transaction WHERE id > ? AND paymethod LIKE '[design]%' AND customer_id IN (?, ?)",
            [$this->maxTransaction, self::clientId(), self::designerId()]);
        $this->saved = [];
    }

    protected function tearDown(): void
    {
        $steps = [
            fn() => $this->restoreMoney(),
            function (): void {
                // Сповіщення йде після відповіді (fastcgi_finish_request) — може з'явитися за мить
                foreach ($this->bought as $id) {
                    $like = 'Майстерня дизайну: Клієнт купив макети №' . $id . '%';
                    for ($i = 0; $i < 20 && !Sql::value('SELECT id FROM n_smser WHERE customer_id = ? AND messenger_text LIKE ?', [self::designerId(), $like]); $i++) {
                        usleep(100000);
                    }
                    Sql::exec('DELETE FROM n_smser WHERE customer_id = ? AND messenger_text LIKE ?', [self::designerId(), $like]);
                }
                $this->bought = [];
            },
            function (): void {
                $root = Paths::root();
                if (!$this->orders) return;
                $in = implode(',', array_map('intval', $this->orders));
                Sql::exec("DELETE FROM design_purchases WHERE order_id IN ($in)");
                Sql::exec("DELETE FROM design_previews WHERE order_id IN ($in)");
                Sql::exec("DELETE FROM design_files WHERE order_id IN ($in)");
                Sql::exec("DELETE m FROM design_messages m JOIN design_offers f ON f.id = m.offer_id WHERE f.order_id IN ($in)");
                Sql::exec("DELETE FROM design_offers WHERE order_id IN ($in)");
                Sql::exec("DELETE FROM design_orders WHERE id IN ($in)");
                foreach ($this->orders as $id) {
                    foreach (self::links($id) as $l) unlink($l);
                    foreach (["$root/previews/$id", "$root/layouts/$id"] as $dir) {
                        if (is_dir($dir)) exec('rm -rf ' . escapeshellarg($dir));
                    }
                }
                $this->orders = [];
            },
            function (): void {
                foreach ($this->keys as $key) {
                    @unlink(self::UPPY . "design-results/$key.json");
                    @unlink(self::UPPY . "design-results/$key.lock");
                    foreach (glob(self::UPPY . 'chunks/' . md5($key) . '.part*') ?: [] as $p) @unlink($p);
                }
                $this->keys = [];
            },
            function (): void {
                if ($this->ownsDesigner) Sql::exec('DELETE FROM design_designers WHERE customer_id = ?', [self::designerId()]);
                $this->ownsDesigner = false;
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

    /** Гроші на час тесту (повертає tearDown) */
    private function money(int $id, int $invSum, int $credit = 0): void
    {
        Sql::exec('UPDATE customers SET inv_sum = ?, credit = ?, credit_ra = 0, temp_credit_ra = 0, parent_id = 0,
                   overdraft_start = NULL, overdraft_days = NULL WHERE id = ?', [$invSum, $credit, $id]);
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
            'answers' => json_encode([['q' => 'Текст', 'a' => 'Тест купівлі']], JSON_UNESCAPED_UNICODE),
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

    /** @return array<string, mixed> опції curl для запиту від $as */
    private static function opts(string $path, int $as, ?array $post = null, bool $multipart = false): array
    {
        $opts = [
            CURLOPT_URL            => 'https://dev.design.wolf.ua' . $path,
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
        return $opts;
    }

    /** @return array{0:int,1:string,2:string} код, заголовки, тіло */
    private static function fetch(string $path, int $as, ?array $post = null, bool $multipart = false): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, self::opts($path, $as, $post, $multipart));
        $raw = (string)curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        return [$code, substr($raw, 0, $hs), substr($raw, $hs)];
    }

    /** @return array{0:int,1:array} */
    private static function getJson(string $path, int $as): array
    {
        [$code, $h, $body] = self::fetch($path, $as);
        return [$code, (array)json_decode($body, true), $h];
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
        $tmp = tempnam(sys_get_temp_dir(), 'buy');
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

    /** Макет від дизайнера (одна частина) → [layout з відповіді, байти] */
    private function layout(int $orderId, int $offerId, int $designer, string $name, int $size = 3000): array
    {
        self::inChat($offerId);
        $bytes = random_bytes($size);
        $meta = ['fileId' => 'test' . bin2hex(random_bytes(8)), 'chunkIndex' => 0, 'totalChunks' => 1, 'totalSize' => $size, 'originalName' => $name];
        [$code, $json] = $this->part($orderId, $designer, 'layout', $meta, $bytes);
        $this->assertSame(200, $code, json_encode($json, JSON_UNESCAPED_UNICODE));
        return [$json['layout'], $bytes];
    }

    /** POST /orders/{id}/buy → [код, JSON] */
    private function buy(int $orderId, int $as, string|int $amount, ?string $csrf = null): array
    {
        $this->bought[] = $orderId;
        [$code, $h, $body] = self::fetch("/orders/$orderId/buy", $as, ['amount' => (string)$amount, 'csrf' => $csrf ?? self::csrf($as)]);
        $this->assertMatchesRegularExpression('#^content-type:\s*application/json#mi', $h, $body);
        return [$code, (array)json_decode($body, true)];
    }

    /** POST /orders/{id}/layouts/{fileId} → [код, JSON] */
    private static function act(int $orderId, int $fileId, int $as, array $post): array
    {
        [$code, , $body] = self::fetch("/orders/$orderId/layouts/$fileId", $as, $post + ['csrf' => self::csrf($as)]);
        return [$code, (array)json_decode($body, true)];
    }

    /** Симлінки cloud-links (ci і cf) на макети замовлення */
    private static function links(int $orderId): array
    {
        $out = [];
        foreach (self::LINKS as $root) {
            exec('find ' . escapeshellarg($root) . ' -type l -lname ' . escapeshellarg(Paths::root() . "/layouts/$orderId/*"), $out);
        }
        return $out;
    }

    private static function inv(int $id): string
    {
        return (string)Sql::value('SELECT inv_sum FROM customers WHERE id = ?', [$id]);
    }

    private function transactions(): array
    {
        return Sql::all("SELECT customer_id, payment_done, paymethod FROM transaction WHERE id > ? AND paymethod LIKE '[design]%'
                         AND customer_id IN (?, ?) ORDER BY id", [$this->maxTransaction, self::clientId(), self::designerId()]);
    }

    /** cf-посилання в тексті (HTML / JSON; у JSON «/» може бути з «\») */
    private static function cfUrls(string $out): array
    {
        preg_match_all('~https:(?:\\\\?/){2}(?:dev\.)?i\.fastcdn\.kiev\.ua(?:\\\\?/)cf(?:\\\\?/)[^"\'\s<>]+~', $out, $m);
        return $m[0];
    }

    public function test_buy_from_balance_opens_layouts(): void
    {
        $client = self::clientId();
        $designer = $this->designer();
        [$orderId, $offerId] = $this->inWork($client, $designer);
        [$a] = $this->layout($orderId, $offerId, $designer, 'Візитка.pdf');
        [$b] = $this->layout($orderId, $offerId, $designer, 'Зворот.ai');
        $this->money($client, 5000);
        $this->money($designer, 70);

        // Сторінка клієнта: «Купую» працює, вікно «Оплата: звідки?» з балансу; тимчасового тексту етапу 4 немає
        [$code, , $html] = self::fetch("/orders/$orderId/", $client);
        $this->assertSame(200, $code);
        $this->assertMatchesRegularExpression('~<button[^>]*data-ws-buy(?![^>]*disabled)[^>]*>~', $html);
        $text = self::text($html);
        foreach (['Купую', 'Оплата: звідки?', 'З балансу', 'Visa/Mastercard', 'Виставити рахунок', 'На балансі 5 000 грн', 'Сума 1 200 грн',
                     'Залишок після оплати 3 800 грн', 'Оплатити', 'Ваш хід: перевірте макети і натисніть «Купую»'] as $s) {
            $this->assertStringContainsString($s, $text, $s);
        }
        $this->assertStringNotContainsString('Ваш кредит', $text, 'кредит 0 — рядка немає');
        $this->assertStringNotContainsString('Купівля — незабаром', $text);
        $this->assertMatchesRegularExpression('~<button[^>]*data-pay-submit(?![^>]*disabled)[^>]*data-amount="1200"~', $html);
        $this->assertSame([], self::cfUrls($html));

        // Свіжа сума для вікна
        [$code, $quote] = self::getJson("/orders/$orderId/buy", $client);
        $this->assertSame(200, $code);
        $this->assertSame([1200, 5000, 3800, true, ''], [$quote['quote']['amount_client'], $quote['quote']['balance'], $quote['quote']['after'],
            $quote['quote']['can'], $quote['quote']['reason']]);
        $this->assertStringContainsString('Залишок після оплати', self::text($quote['html']));
        $this->assertSame(404, self::getJson("/orders/$orderId/buy", $designer)[0], 'дизайнер суми клієнта не бачить');

        // Оплата
        [$code, $json] = $this->buy($orderId, $client, 1200);
        $this->assertSame(200, $code, json_encode($json, JSON_UNESCAPED_UNICODE));
        $this->assertTrue($json['ok']);
        $this->assertSame([$a['id'], $b['id']], array_column($json['layouts'], 'id'));
        $this->assertSame([true, true], array_column($json['layouts'], 'purchased'));
        foreach ($json['layouts'] as $l) $this->assertMatchesRegularExpression('~^https://dev\.i\.fastcdn\.kiev\.ua/cf/~', $l['download']);
        $this->assertSame(['mine' => false, 'text' => 'Макети ваші'], array_intersect_key($json['turn'], ['mine' => 1, 'text' => 1]));
        $this->assertStringContainsString('Куплено', $json['status_html']);
        $this->assertSame('3' . self::NB . '800' . self::NB . 'грн', $json['balance_text'], 'баланс для шапки');

        // Гроші й рядки
        $this->assertEquals(3800, (float)self::inv($client));
        $this->assertEquals(1070, (float)self::inv($designer));
        $this->assertSame([
            ['customer_id' => $client, 'payment_done' => '-1200', 'paymethod' => "[design] Оплата замовлення №$orderId"],
            ['customer_id' => $designer, 'payment_done' => '1000', 'paymethod' => "[design] Гонорар за замовлення №$orderId"],
        ], array_map(static fn($r) => ['customer_id' => (int)$r['customer_id'], 'payment_done' => (string)(int)$r['payment_done'],
            'paymethod' => $r['paymethod']], $this->transactions()));
        $this->assertSame(OrderStatus::PURCHASED, (int)Sql::value('SELECT status FROM design_orders WHERE id = ?', [$orderId]));
        $this->assertCount(2, self::links($orderId), 'симлінки — обом купленим');

        // Після купівлі: клієнт — «Завантажити» і «Макети ваші», без «Купую»; дизайнер — «Готово», без посилань
        [, , $html] = self::fetch("/orders/$orderId/", $client);
        $text = self::text($html);
        $this->assertStringContainsString('Макети ваші', $text);
        $this->assertStringContainsString('Завантажити', $text);
        $this->assertMatchesRegularExpression('~<span data-balance>3' . self::NB . '800' . self::NB . 'грн</span>~u', $html);
        $this->assertStringNotContainsString('data-ws-buy', $html);
        $this->assertStringNotContainsString('Оплата: звідки?', $text);
        $this->assertCount(2, self::cfUrls($html));
        $this->assertStringContainsString('Клієнт купив макети за 1 200 грн', $text, 'системне повідомлення в стрічці');
        [, , $html] = self::fetch("/feed/$orderId/", $designer);
        $text = self::text($html);
        $this->assertStringContainsString('Готово', $text);
        $this->assertStringContainsString('Куплено', $text);
        $this->assertSame([], self::cfUrls($html));
        $this->assertStringNotContainsString('data-lp-delete', $html);
        $this->assertStringNotContainsString('data-ws-upload', $html);
        // Опитування клієнта бачить нову версію списку
        [, $poll] = self::getJson("/offers/$offerId/messages?after=0", $client);
        $this->assertSame($json['rev'], $poll['workspace']['layouts_rev']);
        $this->assertSame('Макети ваші', $poll['workspace']['turn']['text']);
    }

    public function test_buy_short_balance_400(): void
    {
        $client = self::clientId();
        $designer = $this->designer();
        [$orderId, $offerId] = $this->inWork($client, $designer);
        $this->layout($orderId, $offerId, $designer, 'Візитка.pdf');
        $this->money($client, 100, 50);
        $this->money($designer, 70);

        [, , $html] = self::fetch("/orders/$orderId/", $client);
        $text = self::text($html);
        foreach (['На балансі 100 грн', 'Ваш кредит 50 грн', 'Сума 1 200 грн', 'Залишок після оплати -1 100 грн', 'Не вистачає 1 050 грн'] as $s) {
            $this->assertStringContainsString($s, $text, $s);
        }
        $this->assertMatchesRegularExpression('~<button[^>]*data-pay-submit[^>]*disabled~', $html);
        $this->assertMatchesRegularExpression('~class="[^"]*text-danger[^"]*"[^>]*>-1' . self::NB . '100' . self::NB . 'грн<~u', $html, 'залишок < 0 — червоним');

        // Сервер однаково відмовляє: 400 {error} і свіжий вміст вікна
        [$code, $json] = $this->buy($orderId, $client, 1200);
        $this->assertSame(400, $code);
        $this->assertSame('Не вистачає 1' . self::NB . '050 грн', $json['error']);
        $this->assertStringContainsString('Не вистачає', self::text($json['html']));

        // Інші відмови: сума не та, CSRF, сміття в сумі, не клієнт
        [$code, $json] = $this->buy($orderId, $client, 1100);
        $this->assertSame([400, 'Ціна змінилася — перевірте суму'], [$code, $json['error']]);
        $this->money($client, 5000);
        [$code, $json] = $this->buy($orderId, $client, 1200, str_repeat('0', 64));
        $this->assertSame(403, $code);
        $this->assertSame('Сесія застаріла — оновіть сторінку', $json['error']);
        foreach (['', '12a', '-1200', '1200.5', '99999999999'] as $bad) {
            [$code, $json] = $this->buy($orderId, $client, $bad);
            $this->assertSame([400, 'Ціна змінилася — перевірте суму'], [$code, $json['error'] ?? null], "amount '$bad'");
        }
        [$code, $json] = $this->buy($orderId, $designer, 1200);
        $this->assertSame([404, 'Замовлення не знайдено'], [$code, $json['error']]);

        $this->assertEquals(5000, (float)self::inv($client));
        $this->assertEquals(70, (float)self::inv($designer));
        $this->assertSame([], $this->transactions());
        $this->assertSame(OrderStatus::IN_WORK, (int)Sql::value('SELECT status FROM design_orders WHERE id = ?', [$orderId]));
        $this->assertSame(0, (int)Sql::value('SELECT COUNT(*) FROM design_purchases WHERE order_id = ?', [$orderId]));
        $this->assertSame([], self::links($orderId));
    }

    /**
     * Посилання cf — лише купленим і лише клієнту: до купівлі — ніде; після часткової (купили 1-й, потім доопрацювання й 2-й
     * макет) — у клієнта рівно одне на 1-й, у дизайнера жодного; симлінк — лише на 1-й. Доплата 0 — «Відкрити макети» без грошей
     */
    public function test_no_cf_link_for_unpurchased(): void
    {
        $client = self::clientId();
        $designer = $this->designer();
        [$orderId, $offerId] = $this->inWork($client, $designer);
        [$a] = $this->layout($orderId, $offerId, $designer, 'Перший.pdf');
        $this->money($client, 5000);
        $this->money($designer, 70);
        $outputs = function () use ($orderId, $offerId, $client, $designer): array {
            return [
                'client html' => self::fetch("/orders/$orderId/", $client)[2],
                'designer html' => self::fetch("/feed/$orderId/", $designer)[2],
                'client layouts' => self::fetch("/orders/$orderId/layouts", $client)[2],
                'designer layouts' => self::fetch("/orders/$orderId/layouts", $designer)[2],
                'client poll' => self::fetch("/offers/$offerId/messages?after=0", $client)[2],
                'designer poll' => self::fetch("/offers/$offerId/messages?after=0", $designer)[2],
                'client quote' => self::fetch("/orders/$orderId/buy", $client)[2],
            ];
        };
        foreach ($outputs() as $where => $out) $this->assertSame([], self::cfUrls($out), $where);
        $this->assertSame([], self::links($orderId));

        [$code, $json] = $this->buy($orderId, $client, 1200);
        $this->assertSame(200, $code);

        // Доопрацювання без доплати (ціну не змінено): дизайнер працює далі й вантажить 2-й макет
        Sql::exec('UPDATE design_orders SET status = ? WHERE id = ?', [OrderStatus::IN_WORK, $orderId]);
        [$b] = $this->layout($orderId, $offerId, $designer, 'Другий.pdf');
        $root = Paths::root();
        foreach ($outputs() as $where => $out) {
            $urls = self::cfUrls($out);
            if (str_starts_with($where, 'client') && $where !== 'client poll' && $where !== 'client quote') {
                $this->assertCount(1, $urls, $where);
            } else {
                $this->assertSame([], $urls, $where);
            }
            $this->assertStringNotContainsString("$root/layouts", str_replace('\/', '/', $out), $where);
        }
        $this->assertSame(["$root/layouts/$orderId/{$a['id']}.pdf"], array_map('readlink', self::links($orderId)));
        $this->assertStringContainsString('Перший.pdf', $outputs()['client layouts']);

        // Сума 0 — «Відкрити макети», без руху грошей і рядків transaction
        [, , $html] = self::fetch("/orders/$orderId/", $client);
        $this->assertStringContainsString('Відкрити макети', self::text($html));
        $this->assertMatchesRegularExpression('~data-pay-submit[^>]*data-amount="0"~', $html);
        $before = $this->transactions();
        [$code, $json] = $this->buy($orderId, $client, 0);
        $this->assertSame(200, $code, json_encode($json, JSON_UNESCAPED_UNICODE));
        $this->assertSame([true, true], array_column($json['layouts'], 'purchased'));
        $this->assertSame($before, $this->transactions());
        $this->assertEquals(3800, (float)self::inv($client));
        $this->assertSame(2, (int)Sql::value('SELECT COUNT(*) FROM design_purchases WHERE order_id = ?', [$orderId]));
        $this->assertEqualsCanonicalizing(["$root/layouts/$orderId/{$a['id']}.pdf", "$root/layouts/$orderId/{$b['id']}.pdf"],
            array_map('readlink', self::links($orderId)));
    }

    public function test_designer_cannot_touch_purchased(): void
    {
        $client = self::clientId();
        $designer = $this->designer();
        [$orderId, $offerId] = $this->inWork($client, $designer);
        [$a] = $this->layout($orderId, $offerId, $designer, 'Куплений.pdf');
        $this->money($client, 5000);
        $this->money($designer, 70);
        [$code] = $this->buy($orderId, $client, 1200);
        $this->assertSame(200, $code);

        // «Куплено»: дизайнер уже нічого не змінює
        [$code] = self::act($orderId, $a['id'], $designer, ['action' => 'rename', 'label' => 'Нове']);
        $this->assertSame(403, $code);
        [$code] = self::act($orderId, $a['id'], $designer, ['action' => 'delete']);
        $this->assertSame(403, $code);

        // Знову в роботі (доопрацювання): куплений однаково не змінити — ні дією, ні заміною; новий — можна
        Sql::exec('UPDATE design_orders SET status = ? WHERE id = ?', [OrderStatus::IN_WORK, $orderId]);
        [$b] = $this->layout($orderId, $offerId, $designer, 'Новий.pdf');
        $bought = 'Куплений макет змінити не можна';
        foreach ([['action' => 'rename', 'label' => 'Нове'], ['action' => 'delete']] as $post) {
            [$code, $json] = self::act($orderId, $a['id'], $designer, $post);
            $this->assertSame([422, $bought], [$code, $json['error'] ?? null], $post['action']);
        }
        $meta = ['fileId' => 'test' . bin2hex(random_bytes(8)), 'chunkIndex' => 0, 'totalChunks' => 1, 'totalSize' => 3, 'originalName' => 'x.pdf'];
        [$code, $json] = $this->part($orderId, $designer, 'replace', $meta, 'abc', ['file_id' => (string)$a['id']]);
        $this->assertSame([422, $bought], [$code, $json['error'] ?? null], 'заміна');
        $row = Sql::one('SELECT status, label, purchase_id FROM design_files WHERE id = ?', [$a['id']]);
        $this->assertSame([1, 'Куплений'], [(int)$row['status'], $row['label']]);
        $this->assertNotNull($row['purchase_id']);
        $this->assertFileExists(Paths::root() . "/layouts/$orderId/{$a['id']}.pdf");

        // У панелі дизайнера в купленого немає ✎, «Замінити», «Видалити»; у нового — є
        [, , $html] = self::fetch("/feed/$orderId/", $designer);
        $this->assertMatchesRegularExpression('~<li class="lp-item is-bought" data-lp-id="' . $a['id'] . '">(?:(?!</li>).)*Куплено(?:(?!</li>).)*</li>~su', $html);
        preg_match('~<li[^>]*data-lp-id="' . $a['id'] . '">.*?</li>~su', $html, $m);
        foreach (['data-lp-rename', 'data-lp-replace', 'data-lp-delete'] as $attr) $this->assertStringNotContainsString($attr, $m[0]);
        preg_match('~<li[^>]*data-lp-id="' . $b['id'] . '">.*?</li>~su', $html, $m);
        foreach (['data-lp-rename', 'data-lp-replace', 'data-lp-delete'] as $attr) $this->assertStringContainsString($attr, $m[0]);
        [, $json] = self::act($orderId, $b['id'], $designer, ['action' => 'rename', 'label' => 'Новий 2']);
        $this->assertSame(['Куплений', 'Новий 2'], array_column($json['layouts'], 'label'));
    }

    public function test_buy_twice_second_fails(): void
    {
        $client = self::clientId();
        $designer = $this->designer();
        [$orderId, $offerId] = $this->inWork($client, $designer);
        $this->layout($orderId, $offerId, $designer, 'Візитка.pdf');
        $this->money($client, 5000);
        $this->money($designer, 70);

        // Подвійний клік / дві вкладки: два однакові запити одночасно — рівно одне списання
        $this->bought[] = $orderId;
        $mh = curl_multi_init();
        $handles = [];
        for ($i = 0; $i < 2; $i++) {
            $ch = curl_init();
            curl_setopt_array($ch, self::opts("/orders/$orderId/buy", $client, ['amount' => '1200', 'csrf' => self::csrf($client)]));
            curl_multi_add_handle($mh, $ch);
            $handles[] = $ch;
        }
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) curl_multi_select($mh, 1.0);
        } while ($running && $status === CURLM_OK);
        $codes = [];
        $errors = [];
        foreach ($handles as $ch) {
            $codes[] = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $raw = (string)curl_multi_getcontent($ch);
            $json = (array)json_decode(substr($raw, curl_getinfo($ch, CURLINFO_HEADER_SIZE)), true);
            if (isset($json['error'])) $errors[] = $json['error'];
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        sort($codes);
        $this->assertSame([200, 400], $codes);
        $this->assertSame(['Макети вже куплено'], $errors);

        // І ще раз — після
        [$code, $json] = $this->buy($orderId, $client, 1200);
        $this->assertSame([400, 'Макети вже куплено'], [$code, $json['error']]);
        [$code] = self::getJson("/orders/$orderId/buy", $client);
        $this->assertSame(404, $code, 'вікно оплати для купленого — немає');

        $this->assertEquals(3800, (float)self::inv($client));
        $this->assertEquals(1070, (float)self::inv($designer));
        $this->assertCount(2, $this->transactions());
        $this->assertSame(1, (int)Sql::value('SELECT COUNT(*) FROM design_purchases WHERE order_id = ?', [$orderId]));
    }

    /** «Завантажити»: dev.i.fastcdn віддає саме цей файл на скачування з іменем «Дизайн №N — {підпис}.{розширення}» */
    public function test_download_link_has_name(): void
    {
        $client = self::clientId();
        $designer = $this->designer();
        [$orderId, $offerId] = $this->inWork($client, $designer);
        [$a, $bytes] = $this->layout($orderId, $offerId, $designer, 'Візитка «Зерно».PDF', 5000);
        $this->money($client, 5000);
        $this->money($designer, 70);
        [$code, $json] = $this->buy($orderId, $client, 1200);
        $this->assertSame(200, $code);
        $url = $json['layouts'][0]['download'];
        $name = "Дизайн №$orderId — Візитка «Зерно».pdf";
        $this->assertMatchesRegularExpression('~^https://dev\.i\.fastcdn\.kiev\.ua/cf/[0-9a-f]{2}/[0-9a-f]{2}/[0-9a-f]{2}/[0-9a-f]+\.pdf\?download=1&name=[^+&]+$~', $url);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame(['download' => '1', 'name' => $name], $q);

        // Та сама адреса — на сторінці клієнта (екранована в атрибуті)
        [, , $html] = self::fetch("/orders/$orderId/", $client);
        $this->assertStringContainsString('href="' . htmlspecialchars($url, ENT_QUOTES) . '"', $html);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RESOLVE => ['dev.i.fastcdn.kiev.ua:443:127.0.0.1'],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        $raw = (string)curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $this->assertSame(200, $code);
        $this->assertSame($bytes, substr($raw, $hs), 'той самий файл');
        $this->assertMatchesRegularExpression('~^content-disposition:\s*attachment;\s*filename\*=UTF-8\'\'(\S+)~mi', substr($raw, 0, $hs));
        preg_match('~^content-disposition:\s*attachment;\s*filename\*=UTF-8\'\'(\S+)~mi', substr($raw, 0, $hs), $m);
        // filename* (RFC 5987) браузер розкодовує лише %XX: «+» лишився б у імені файла замість пробілу
        $this->assertStringNotContainsString('+', $m[1]);
        $this->assertSame($name, rawurldecode($m[1]));
    }
}
