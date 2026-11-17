<?php

use Design\Config;
use Design\Designers;
use Design\DesignerStatus;
use Design\Orders;
use Design\OrderStatus;
use Design\Products;
use Design\Sql;
use PHPUnit\Framework\TestCase;

/**
 * Страницы этапа 1 по-настоящему через nginx dev.design.wolf.ua.
 * Клиент — DESIGN_TEST_CUSTOMER_ID (на dev — 29, аккаунт владельца): удаляем только заказы, созданные тестом.
 *
 * @group http
 */
final class PagesHttpTest extends TestCase
{
    private const UA = 'DesignTest/1';
    private const OTHER = 999999002;
    /** @var list<int> */
    private static array $created = [];

    public static function tearDownAfterClass(): void
    {
        if (self::$created) {
            Sql::exec('DELETE FROM design_orders WHERE id IN (' . implode(',', array_map('intval', self::$created)) . ')');
        }
    }

    private static function me(): int
    {
        $id = (int)getenv('DESIGN_TEST_CUSTOMER_ID');
        if ($id <= 0) self::markTestSkipped('DESIGN_TEST_CUSTOMER_ID не задан');
        return $id;
    }

    private static function cookie(): string
    {
        $id = self::me();
        $pass = (string)Sql::value('SELECT customer_pass FROM customers WHERE id = ?', [$id]);
        return 'wolfid=' . $id . '.' . hash_hmac('md5', $pass . self::UA, (string)$id);
    }

    /** @return array{0:int,1:string,2:string} код, заголовки, тело. $multipart — multipart/form-data (з CURLFile) */
    private static function fetch(string $path, string $cookie = '', ?array $post = null, bool $multipart = false): array
    {
        $ch = curl_init('https://dev.design.wolf.ua' . $path);
        $opts = [
            CURLOPT_RESOLVE        => ['dev.design.wolf.ua:443:127.0.0.1'],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_USERAGENT      => self::UA,
            CURLOPT_HTTPHEADER     => $cookie !== '' ? ['Cookie: ' . $cookie] : [],
        ];
        if ($post !== null) {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = $multipart ? $post : http_build_query($post);
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        return [$code, substr($raw, 0, $hs), substr($raw, $hs)];
    }

    private static function csrf(string $html): string
    {
        return preg_match('/name="csrf" value="([0-9a-f]{64})"/', $html, $m) ? $m[1] : '';
    }

    private static function location(string $headers): string
    {
        return preg_match('/^location:\s*(\S+)/mi', $headers, $m) ? $m[1] : '';
    }

    /** Продукт и ответы: первый — $first, остальные пустые */
    private static function brief(string $first): array
    {
        $pid = Products::visible()[0]['id'];
        return [$pid, array_merge([$first], array_fill(0, count(Products::get($pid)['questions']) - 1, ''))];
    }

    /** Новый черновик через форму; возвращает id */
    private function postDraft(string $first, string $action = 'draft'): array
    {
        [$pid, $answers] = self::brief($first);
        [, , $form] = self::fetch("/brief/$pid/", self::cookie());
        [$code, $h] = self::fetch('/brief', self::cookie(), [
            'product_id' => $pid, 'title' => 'Тест HTTP', 'answers' => $answers, 'csrf' => self::csrf($form), 'action' => $action,
        ]);
        $this->assertSame(302, $code);
        $loc = self::location($h);
        $this->assertMatchesRegularExpression('#^/orders/(\d+)/#', $loc);
        preg_match('#^/orders/(\d+)/#', $loc, $m);
        self::$created[] = (int)$m[1];
        return [(int)$m[1], $loc];
    }

    public function test_guest_sees_login_required(): void
    {
        [$code, , $body] = self::fetch('/brief/');
        $this->assertSame(401, $code);
        $this->assertStringContainsString('увійдіть на wolf.ua', $body);
    }

    public function test_brief_products_page(): void
    {
        [$code, , $body] = self::fetch('/brief/', self::cookie());
        $this->assertSame(200, $code);
        $this->assertStringContainsString('Інше', $body);
        $this->assertStringContainsString('vizytka.webp', $body);
    }

    public function test_hidden_product_form_is_404(): void      // Review Focus 5
    {
        $this->assertSame(404, self::fetch('/brief/999999999/', self::cookie())[0]);
    }

    public function test_create_without_csrf_is_403(): void
    {
        [$pid, $answers] = self::brief('x');
        $this->assertSame(403, self::fetch('/brief', self::cookie(), ['product_id' => $pid, 'answers' => $answers, 'action' => 'draft'])[0]);
    }

    public function test_draft_then_mode_then_publish(): void
    {
        [$id, $loc] = $this->postDraft('А5');
        $this->assertSame("/orders/$id/?saved=1", $loc);
        [$code, , $body] = self::fetch($loc, self::cookie());
        $this->assertSame(200, $code);
        $this->assertStringContainsString('Чернетку збережено', $body);

        [$code, , $mode] = self::fetch("/orders/$id/mode/", self::cookie());
        $this->assertSame(200, $code);
        $this->assertStringContainsString('Незабаром', $mode);

        [$code, $h] = self::fetch("/orders/$id", self::cookie(), ['action' => 'publish', 'csrf' => self::csrf($mode)]);
        $this->assertSame(302, $code);
        $this->assertSame("/orders/$id/", self::location($h));
        $this->assertSame(OrderStatus::COLLECTING, Orders::get($id, self::me())['status']);

        // Final review: «Назад» після публікації — власне замовлення, не «404, інший акаунт»
        [$code, $h] = self::fetch("/orders/$id/mode/", self::cookie());
        $this->assertSame(302, $code);
        $this->assertSame("/orders/$id/", self::location($h));
        [$code, $h] = self::fetch("/orders/$id", self::cookie(), ['action' => 'draft', 'answers' => ['x'], 'csrf' => self::csrf($mode)]);
        $this->assertSame(302, $code);
        $this->assertSame("/orders/$id/", self::location($h));
    }

    public function test_stale_csrf_keeps_answers(): void   // Final review: прострочений токен не губить довгий бриф
    {
        [$pid, $answers] = self::brief('Довгий важливий текст брифу');
        $before = (int)Sql::value('SELECT COUNT(*) FROM design_orders WHERE customer_id = ?', [self::me()]);
        [$code, , $body] = self::fetch('/brief', self::cookie(), [
            'product_id' => $pid, 'title' => 'Мій заголовок', 'answers' => $answers, 'csrf' => str_repeat('0', 64), 'action' => 'draft',
        ]);
        $this->assertSame(403, $code);
        $this->assertStringContainsString('Довгий важливий текст брифу', $body);
        $this->assertStringContainsString('Мій заголовок', $body);
        $this->assertStringContainsString('Сесія застаріла', $body);
        $this->assertNotSame('', self::csrf($body));
        $this->assertSame($before, (int)Sql::value('SELECT COUNT(*) FROM design_orders WHERE customer_id = ?', [self::me()]));
    }

    public function test_next_goes_to_mode_step(): void
    {
        [$id, $loc] = $this->postDraft('А5', 'next');
        $this->assertSame("/orders/$id/mode/", $loc);
    }

    public function test_empty_answers_show_error(): void
    {
        [$pid, $answers] = self::brief('');
        [, , $form] = self::fetch("/brief/$pid/", self::cookie());
        $before = (int)Sql::value('SELECT COUNT(*) FROM design_orders WHERE customer_id = ?', [self::me()]);
        [$code, , $body] = self::fetch('/brief', self::cookie(), [
            'product_id' => $pid, 'title' => 'Збережений заголовок', 'answers' => $answers, 'csrf' => self::csrf($form), 'action' => 'draft',
        ]);
        $this->assertSame(200, $code);
        $this->assertStringContainsString('Заповніть хоча б одну відповідь', $body);
        $this->assertStringContainsString('Збережений заголовок', $body);
        $this->assertSame($before, (int)Sql::value('SELECT COUNT(*) FROM design_orders WHERE customer_id = ?', [self::me()]));
    }

    public function test_foreign_order_page_is_404(): void         // Review Focus 1
    {
        [$pid, $answers] = self::brief('А5');
        $foreign = Orders::create(self::OTHER, $pid, $answers);
        self::$created[] = $foreign;
        $this->assertSame(404, self::fetch("/orders/$foreign/", self::cookie())[0]);
        $this->assertSame(404, self::fetch("/orders/$foreign/mode/", self::cookie())[0]);
    }

    public function test_order_page_escapes_html(): void           // Review Focus 2
    {
        [$pid, $answers] = self::brief('<script>alert(1)</script>');
        $id = Orders::create(self::me(), $pid, $answers);
        self::$created[] = $id;
        Orders::publish($id, self::me());
        [$code, , $body] = self::fetch("/orders/$id/", self::cookie());
        $this->assertSame(200, $code);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $body);
        $this->assertStringNotContainsString('<script>alert(1)', $body);
    }

    public function test_orders_groups(): void
    {
        [$draft] = $this->postDraft('А5');
        [$pid, $answers] = self::brief('А5');
        $published = Orders::create(self::me(), $pid, $answers);
        self::$created[] = $published;
        Orders::publish($published, self::me());
        [$code, , $body] = self::fetch('/orders/', self::cookie());
        $this->assertSame(200, $code);
        $this->assertStringContainsString('Ваш хід', $body);
        $this->assertStringContainsString('Чекаємо іншу сторону', $body);
        $this->assertStringContainsString("№$draft", $body);
    }

    // ---- Етап 2: сторінки дизайнера. Клієнт 29 — справжній акаунт: тести, що пишуть, працюють лише з чистого місця ----

    /** Тест завів анкету клієнта з нуля — tearDown її прибирає */
    private bool $ownsDesigner = false;
    private string $tmp = '';
    /** @var list<int> дизайнери-двійники (999999401+) цього тесту: tearDown їх прибирає */
    private array $fakes = [];

    /**
     * Для тестів, що пишуть (анкета, фото, роботи, порядок, профіль): лише коли в клієнта ще нічого немає.
     * Є анкета, роботи чи папка робіт — пропуск: справжні дані власника не чіпаємо і не «відновлюємо».
     */
    private function designer(): int
    {
        $me = self::me();
        if (Designers::get($me) !== null
            || (int)Sql::value('SELECT COUNT(*) FROM design_portfolio WHERE designer_id = ?', [$me]) > 0
            || file_exists(Config::storageRoot() . "/portfolio/$me")) {
            $this->markTestSkipped("у клієнта $me вже є профіль дизайнера — тест не чіпає справжні дані");
        }
        $this->ownsDesigner = true;
        $this->tmp = sys_get_temp_dir() . '/design-http-' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0777, true);
        return $me;
    }

    /** @return list<int> усі рядки робіт, разом з тими, що в обробці */
    private static function workIds(int $me): array
    {
        return array_map('intval', array_column(Sql::all('SELECT id FROM design_portfolio WHERE designer_id = ?', [$me]), 'id'));
    }

    /** Прибирає лише те, що тест завів з нуля: анкету, роботи і папку portfolio/<id> */
    protected function tearDown(): void
    {
        if ($this->tmp !== '') exec('rm -rf ' . escapeshellarg($this->tmp));
        foreach ($this->fakes as $id) {
            Sql::exec('DELETE FROM design_portfolio WHERE designer_id = ?', [$id]);
            Sql::exec('DELETE FROM design_designers WHERE customer_id = ?', [$id]);
            exec('rm -rf ' . escapeshellarg(Config::storageRoot() . "/portfolio/$id"));
        }
        $this->fakes = [];
        if (!$this->ownsDesigner) return;
        $this->ownsDesigner = false;
        $me = self::me();
        Sql::exec('DELETE FROM design_portfolio WHERE designer_id = ?', [$me]);
        Sql::exec('DELETE FROM design_designers WHERE customer_id = ?', [$me]);
        exec('rm -rf ' . escapeshellarg(Config::storageRoot() . "/portfolio/$me"));
    }

    private function jpeg(string $name = 'work.jpg'): string
    {
        $im = new Imagick();
        $im->newPseudoImage(800, 600, 'gradient:orange-blue');
        $im->setImageFormat('jpeg');
        $im->writeImage("jpeg:{$this->tmp}/$name");
        $im->clear();
        return "{$this->tmp}/$name";
    }

    private static function setStatus(int $me, int $status, string $comment = ''): void
    {
        Sql::exec('UPDATE design_designers SET status = ?, reject_comment = ? WHERE customer_id = ?', [$status, $comment, $me]);
    }

    /** Сторінка /designer/ (заводить чернетку) і її CSRF */
    private function designerPage(string $query = ''): array
    {
        [$code, , $body] = self::fetch('/designer/' . $query, self::cookie());
        $this->assertSame(200, $code);
        return [$body, self::csrf($body)];
    }

    /** @return array{0:int,1:array} код і JSON */
    private static function upload(string $path, string $file, string $csrf, string $mime = 'image/jpeg'): array
    {
        $post = ['file' => new CURLFile($file, $mime, basename($file))];
        if ($csrf !== '') $post['csrf'] = $csrf;
        [$code, , $body] = self::fetch($path, self::cookie(), $post, true);
        return [$code, (array)json_decode($body, true)];
    }

    private function uploadWork(string $csrf): int
    {
        [$code, $json] = self::upload('/designer/works', $this->jpeg(), $csrf);
        $this->assertSame(200, $code, json_encode($json, JSON_UNESCAPED_UNICODE));
        return (int)$json['id'];
    }

    public function test_designer_page_requires_login(): void
    {
        [$code, , $body] = self::fetch('/designer/');
        $this->assertSame(401, $code);
        $this->assertStringContainsString('увійдіть на wolf.ua', $body);
        $this->assertSame(401, self::fetch('/designer/works', '', ['csrf' => 'x'])[0]);
    }

    public function test_apply_form_prefilled_and_has_no_city(): void
    {
        $me = $this->designer();
        [$body, $csrf] = $this->designerPage();
        $this->assertSame(DesignerStatus::DRAFT, Designers::status($me));
        $this->assertStringContainsString('Заявка в дизайнери Майстерні', $body);
        $name = Designers::get($me)['name'];
        if ($name !== '') $this->assertStringContainsString('value="' . htmlspecialchars($name, ENT_QUOTES) . '"', $body);
        $this->assertStringContainsString('name="about"', $body);
        $this->assertStringContainsString('name="link"', $body);
        $this->assertStringContainsString('Роботи для портфоліо', $body);
        $this->assertStringContainsString('data-csrf="' . $csrf . '"', $body);
        $this->assertStringContainsString('/assets/js/designer.js', $body);
        $this->assertStringContainsString('href="/designer/" class="active">Стати дизайнером', $body);
        $this->assertStringContainsString('href="/designers/"', $body);
        $this->assertStringNotContainsString('Місто', $body);
        $this->assertStringNotContainsString('city', $body);
        $this->assertNotSame('', $csrf);
    }

    public function test_upload_rejects_text_file(): void
    {
        $me = $this->designer();
        [, $csrf] = $this->designerPage();
        file_put_contents("{$this->tmp}/logo.jpg", 'це текст, а не картинка');
        [$code, $json] = self::upload('/designer/works', "{$this->tmp}/logo.jpg", $csrf);
        $this->assertSame(422, $code);
        $this->assertSame(['error' => 'Файл пошкоджений або це не картинка'], $json);
        $this->assertSame([], self::workIds($me));
    }

    public function test_upload_without_csrf_is_403(): void
    {
        $me = $this->designer();
        $this->designerPage();
        [$code, $json] = self::upload('/designer/works', $this->jpeg(), '');
        $this->assertSame(403, $code);
        $this->assertSame('Сесія застаріла — оновіть сторінку', $json['error']);
        $this->assertSame([], self::workIds($me));
    }

    public function test_upload_ok_returns_media_urls(): void
    {
        $me = $this->designer();
        [, $csrf] = $this->designerPage();
        $before = \Design\Portfolio::count($me);
        [$code, $json] = self::upload('/designer/works', $this->jpeg(), $csrf);
        $this->assertSame(200, $code);
        $id = (int)$json['id'];
        $this->assertGreaterThan(0, $id);
        $this->assertSame(['id' => $id, 'card' => "/media/$me/$id-card", 'view' => "/media/$me/$id-view", 'count' => $before + 1], $json);

        // /media через nginx: 200 і WebP (закриває перевірку з задачі 5)
        [$code, $h, $img] = self::fetch($json['card'], self::cookie());
        $this->assertSame(200, $code);
        $this->assertMatchesRegularExpression('#^content-type:\s*image/webp\s*$#mi', $h);
        $this->assertSame('RIFF', substr($img, 0, 4));
        $this->assertSame('WEBP', substr($img, 8, 4));
        $this->assertSame(200, self::fetch($json['view'], self::cookie())[0]);
        $this->assertSame(404, self::fetch($json['card'])[0]);                      // гостю — ні
    }

    public function test_work_title_hide_order_delete(): void
    {
        $me = $this->designer();
        [, $csrf] = $this->designerPage();
        $a = $this->uploadWork($csrf);
        $b = $this->uploadWork($csrf);
        $post = fn(string $path, array $data) => self::fetch($path, self::cookie(), $data + ['csrf' => $csrf]);

        [$code, , $body] = $post("/designer/works/$a", ['action' => 'title', 'title' => '  Візитки «Зерно» <b>  ']);
        $this->assertSame(200, $code);
        $this->assertSame(['ok' => true, 'title' => 'Візитки «Зерно» <b>'], json_decode($body, true));
        $this->assertSame('Візитки «Зерно» <b>', Sql::value('SELECT title FROM design_portfolio WHERE id = ?', [$a]));

        [$code] = $post("/designer/works/$a", ['action' => 'shw', 'on' => '0']);
        $this->assertSame(200, $code);
        $this->assertSame(0, (int)Sql::value('SELECT shw FROM design_portfolio WHERE id = ?', [$a]));

        $ids = array_column(\Design\Portfolio::all($me), 'id');
        $reordered = array_merge([$b], array_values(array_diff($ids, [$b])));
        [$code, , $body] = $post('/designer/works/order', ['ids' => $reordered]);
        $this->assertSame(200, $code, $body);
        $this->assertSame($reordered, array_column(\Design\Portfolio::all($me), 'id'));
        [$code, , $body] = $post('/designer/works/order', ['ids' => [$b]]);
        $this->assertSame(422, $code);
        $this->assertSame('Список робіт змінився — оновіть сторінку', json_decode($body, true)['error']);

        [$code, , $body] = $post("/designer/works/$b", ['action' => 'delete']);
        $this->assertSame(200, $code);
        $this->assertSame(['ok' => true, 'count' => count($ids) - 1], json_decode($body, true));
        $this->assertNull(Sql::one('SELECT id FROM design_portfolio WHERE id = ?', [$b]));
        $this->assertFileDoesNotExist(Config::storageRoot() . "/portfolio/$me/$b-card.webp");

        [$code, , $body] = $post("/designer/works/$b", ['action' => 'delete']);
        $this->assertSame(422, $code);
        $this->assertSame('Роботу не знайдено', json_decode($body, true)['error']);
        $this->assertSame(403, self::fetch("/designer/works/$a", self::cookie(), ['action' => 'delete'])[0]);
        $this->assertNotNull(Sql::one('SELECT id FROM design_portfolio WHERE id = ?', [$a]));
    }

    public function test_photo_upload(): void
    {
        $me = $this->designer();
        [, $csrf] = $this->designerPage();
        [$code, $json] = self::upload('/designer/photo', $this->jpeg('me.jpg'), $csrf);
        $this->assertSame(200, $code, json_encode($json, JSON_UNESCAPED_UNICODE));
        $this->assertMatchesRegularExpression("#^/media/$me/avatar-[0-9a-f]{8}$#", $json['photo']);
        $this->assertSame(basename($json['photo']) . '.webp', Designers::get($me)['photo']);
        [$code, $h] = self::fetch($json['photo'], self::cookie());
        $this->assertSame(200, $code);
        $this->assertMatchesRegularExpression('#^content-type:\s*image/webp\s*$#mi', $h);
    }

    public function test_save_keeps_input_on_error_then_saves(): void
    {
        $me = $this->designer();
        [, $csrf] = $this->designerPage();
        $was = Designers::get($me);
        [$code, , $body] = self::fetch('/designer', self::cookie(), [
            'csrf' => $csrf, 'action' => 'save', 'name' => "Олег О'Ніл <b>", 'about' => 'Друкую <i>все</i>', 'link' => 'javascript:alert(1)',
        ]);
        $this->assertSame(200, $code);
        $this->assertStringContainsString('Посилання має бути адресою сайту', $body);
        $this->assertStringContainsString('value="Олег О&#039;Ніл &lt;b&gt;"', $body);
        $this->assertStringContainsString('Друкую &lt;i&gt;все&lt;/i&gt;</textarea>', $body);
        $this->assertStringContainsString('value="javascript:alert(1)"', $body);
        $this->assertSame($was, Designers::get($me));

        [$code, $h] = self::fetch('/designer', self::cookie(), [
            'csrf' => $csrf, 'action' => 'save', 'name' => "Олег О'Ніл", 'about' => 'Друкую все', 'link' => 'behance.net/oleg',
        ]);
        $this->assertSame(302, $code);
        $this->assertSame('/designer/?saved=1', self::location($h));
        $d = Designers::get($me);
        $this->assertSame(["Олег О'Ніл", 'Друкую все', 'https://behance.net/oleg'], [$d['name'], $d['about'], $d['link']]);

        // Прострочений CSRF: 403, введене на місці, нічого не збережено
        [$code, , $body] = self::fetch('/designer', self::cookie(), ['csrf' => str_repeat('0', 64), 'action' => 'save', 'name' => 'Інше', 'about' => 'Нове', 'link' => '']);
        $this->assertSame(403, $code);
        $this->assertStringContainsString('Сесія застаріла', $body);
        $this->assertStringContainsString('value="Інше"', $body);
        $this->assertSame($d, Designers::get($me));
    }

    public function test_state_pages(): void
    {
        $me = $this->designer();
        [, $csrf] = $this->designerPage();

        self::setStatus($me, DesignerStatus::REJECTED, 'Додайте фото <надрукованих> робіт');
        [$body] = $this->designerPage();
        $this->assertStringContainsString('Додайте фото &lt;надрукованих&gt; робіт', $body);
        $this->assertStringContainsString('Оновити і подати знову', $body);
        $this->assertStringContainsString('name="about"', $body);

        self::setStatus($me, DesignerStatus::PENDING);
        [$body] = $this->designerPage();
        $this->assertStringContainsString('Заявку на розгляді', $body);
        $this->assertStringNotContainsString('name="about"', $body);
        [$code, $json] = self::upload('/designer/works', $this->jpeg(), $csrf);
        $this->assertSame(422, $code);
        $this->assertSame('Зараз роботи не можна змінювати', $json['error']);

        self::setStatus($me, DesignerStatus::BLOCKED);
        [$body] = $this->designerPage();
        $this->assertStringContainsString('Профіль заблоковано', $body);
        $main = substr($body, strpos($body, '<main>'), strpos($body, '</main>') - strpos($body, '<main>'));
        $this->assertStringNotContainsString('<button', $main);
        $this->assertStringNotContainsString('btn', $main);

        self::setStatus($me, DesignerStatus::APPROVED);
        [$body] = $this->designerPage('?approved=1');
        $this->assertStringContainsString('Вітаємо, ви дизайнер Майстерні!', $body);
        $this->assertStringContainsString('Заповнити профіль', $body);
        [$body] = $this->designerPage();
        $this->assertStringContainsString('<h1>Мій профіль</h1>', $body);
        $this->assertStringContainsString("href=\"/designers/$me/\"", $body);
        $this->assertStringContainsString('Як бачать клієнти', $body);
        $this->assertStringContainsString('Зберегти зміни', $body);
        $this->assertStringContainsString('sortablejs-1.15.6/Sortable.min.js', $body);
        $this->assertStringContainsString('href="/designer/" class="active">Мій профіль', $body);
        $this->assertStringNotContainsString('Стати дизайнером', $body);
        $this->assertStringNotContainsString('Місто', $body);
    }

    // ---- Етап 2, задача 7: каталог і профіль для клієнтів. Дизайнери-двійники 999999401+, клієнт 29 лише дивиться ----

    private const FAKE_BASE = 999999401;
    /** Схвалений (або іншого статусу) дизайнер з $works готовими роботами. Імʼя містить маркер для пошуку */
    private function fakeDesigner(int $n, string $name, int $status = DesignerStatus::APPROVED, int $works = 0, string $about = 'Друкую все'): int
    {
        $id = self::FAKE_BASE + $n;
        $this->fakes[] = $id;
        Sql::exec('DELETE FROM design_portfolio WHERE designer_id = ?', [$id]);
        Sql::exec('DELETE FROM design_designers WHERE customer_id = ?', [$id]);
        Sql::exec('INSERT INTO design_designers (customer_id, status, name, about, link) VALUES (?, ?, ?, ?, ?)',
            [$id, $status, $name, $about, 'https://behance.net/fake' . $n]);
        if ($works > 0) {
            // Portfolio::add дозволяє роботи лише в чернетці і схваленому — для інших статусів додаємо під тимчасовим статусом APPROVED
            Sql::exec('UPDATE design_designers SET status = ? WHERE customer_id = ?', [DesignerStatus::APPROVED, $id]);
            $this->tmp = $this->tmp !== '' ? $this->tmp : sys_get_temp_dir() . '/design-http-' . bin2hex(random_bytes(4));
            @mkdir($this->tmp, 0777, true);
            for ($i = 1; $i <= $works; $i++) {
                $file = $this->jpeg("f$n-$i.jpg");
                $w = \Design\Portfolio::add($id, $file);
                Sql::exec('UPDATE design_portfolio SET title = ? WHERE id = ?', ["Робота $n-$i <i>", $w['id']]);
            }
            Sql::exec('UPDATE design_designers SET status = ? WHERE customer_id = ?', [$status, $id]);
        }
        return $id;
    }

    public function test_catalog_requires_login(): void
    {
        [$code, , $body] = self::fetch('/designers/');
        $this->assertSame(401, $code);
        $this->assertStringContainsString('увійдіть на wolf.ua', $body);
        $id = $this->fakeDesigner(1, 'Гість Невидимий');
        $this->assertSame(401, self::fetch("/designers/$id/")[0]);
    }

    public function test_catalog_shows_approved_only(): void
    {
        $ok = $this->fakeDesigner(2, 'Зразковий Тестовий', DesignerStatus::APPROVED, 4, 'Візитки й <script>alert(1)</script> меню');
        $pending = $this->fakeDesigner(3, 'Тестовий На розгляді', DesignerStatus::PENDING);
        $blocked = $this->fakeDesigner(4, 'Тестовий Заблокований', DesignerStatus::BLOCKED);
        $draft = $this->fakeDesigner(5, 'Тестовий Чернетка', DesignerStatus::DRAFT);

        [$code, , $body] = self::fetch('/designers/?q=' . urlencode('Тестов'), self::cookie());
        $this->assertSame(200, $code);
        $this->assertStringContainsString('Дизайнери Майстерні', $body);
        $this->assertStringContainsString('Зразковий Тестовий', $body);
        $this->assertStringContainsString("href=\"/designers/$ok/\"", $body);
        $this->assertStringContainsString('Переглянути', $body);
        $this->assertStringContainsString('Візитки й &lt;script&gt;alert(1)&lt;/script&gt; меню', $body);
        $this->assertStringNotContainsString('<script>alert(1)', $body);
        foreach (['На розгляді', 'Заблокований', 'Чернетка'] as $hidden) $this->assertStringNotContainsString($hidden, $body);
        foreach ([$pending, $blocked, $draft] as $id) $this->assertStringNotContainsString("/designers/$id/", $body);
        // лише 3 картки робіт, без рейтингу, замовлень, міста
        $this->assertSame(3, preg_match_all('#<img[^>]+src="/media/' . $ok . '/\d+-card"#', $body));
        $main = substr($body, strpos($body, '<main>'));    // у шапці є «Мої замовлення»
        foreach (['замовлен', 'Замовити у', 'Місто'] as $banned) $this->assertStringNotContainsString($banned, $main);
        $this->assertDoesNotMatchRegularExpression('#fa-star|рейтинг#iu', substr($body, strpos($body, '<main>')));
        $this->assertStringContainsString('href="/designers/" class="active">Дизайнери', $body);

        // картинка картки справді віддається
        preg_match('#/media/' . $ok . '/\d+-card#', $body, $m);
        $this->assertSame(200, self::fetch($m[0], self::cookie())[0]);
    }

    public function test_catalog_search_and_empty_state(): void
    {
        $this->fakeDesigner(6, 'Пошуковий Альфа');
        $this->fakeDesigner(7, 'Пошуковий Бета');
        [, , $body] = self::fetch('/designers/?q=' . urlencode('Пошуковий Бета'), self::cookie());
        $this->assertStringContainsString('Пошуковий Бета', $body);
        $this->assertStringNotContainsString('Пошуковий Альфа', $body);
        $this->assertStringContainsString('value="Пошуковий Бета"', $body);

        [$code, , $body] = self::fetch('/designers/?q=' . urlencode('Ніхто "><b>x'), self::cookie());
        $this->assertSame(200, $code);
        $this->assertStringContainsString('Нікого не знайшли. Спробуйте інше імʼя', $body);
        $this->assertStringContainsString('value="Ніхто &quot;&gt;&lt;b&gt;x"', $body);
        $this->assertStringNotContainsString('<b>x', $body);
    }

    public function test_catalog_pagination_keeps_query(): void
    {
        for ($i = 0; $i < Designers::PER_PAGE + 2; $i++) $this->fakeDesigner(10 + $i, 'Сторінковий ' . sprintf('%02d', $i));
        [, , $p1] = self::fetch('/designers/?q=' . urlencode('Сторінковий'), self::cookie());
        $this->assertSame(Designers::PER_PAGE, substr_count($p1, 'class="designer-card'));
        $this->assertStringContainsString('class="pager"', $p1);
        $this->assertStringContainsString('href="/designers/?q=%D0%A1%D1%82%D0%BE%D1%80%D1%96%D0%BD%D0%BA%D0%BE%D0%B2%D0%B8%D0%B9&amp;page=2"', $p1);
        [, , $p2] = self::fetch('/designers/?q=' . urlencode('Сторінковий') . '&page=2', self::cookie());
        $this->assertSame(2, substr_count($p2, 'class="designer-card'));
        // сторінка поза межами — не помилка, а порожня сторінка
        $this->assertSame(200, self::fetch('/designers/?q=' . urlencode('Сторінковий') . '&page=99', self::cookie())[0]);
        [, , $one] = self::fetch('/designers/?q=' . urlencode('Сторінковий 01'), self::cookie());
        $this->assertStringNotContainsString('class="pager"', $one);
    }

    public function test_unapproved_profile_is_404(): void
    {
        foreach ([DesignerStatus::DRAFT, DesignerStatus::PENDING, DesignerStatus::REJECTED, DesignerStatus::BLOCKED] as $i => $st) {
            $id = $this->fakeDesigner(40 + $i, 'Прихований ' . $i, $st);
            $this->assertSame(404, self::fetch("/designers/$id/", self::cookie())[0], "статус $st");
        }
        $this->assertSame(404, self::fetch('/designers/999999499/', self::cookie())[0]);
    }

    public function test_public_profile_escapes_html(): void
    {
        $id = $this->fakeDesigner(50, "Олег О'Ніл <b>", DesignerStatus::APPROVED, 2, 'Про <i>себе</i>');
        [$code, , $body] = self::fetch("/designers/$id/", self::cookie());
        $this->assertSame(200, $code);
        $this->assertStringContainsString('Олег О&#039;Ніл &lt;b&gt;', $body);
        $this->assertStringNotContainsString('<b>', substr($body, strpos($body, '<main>')));
        $this->assertStringContainsString('Про &lt;i&gt;себе&lt;/i&gt;', $body);
        $this->assertStringContainsString('Робота 50-1 &lt;i&gt;', $body);
        $this->assertStringNotContainsString('<i>', preg_replace('#<i class="[^"]*"></i>#', '', substr($body, strpos($body, '<main>'))));
        $this->assertStringContainsString('Портфоліо · 2 роботи', $body);
        $this->assertStringContainsString('href="https://behance.net/fake50"', $body);
        $this->assertStringContainsString('rel="noopener nofollow ugc"', $body);
        $this->assertStringContainsString('Усі дизайнери', $body);
        $this->assertStringContainsString('data-bs-toggle="modal"', $body);
        $this->assertMatchesRegularExpression('#/media/' . $id . '/\d+-view#', $body);
        foreach (['замовлен', 'Замовити у'] as $banned) $this->assertStringNotContainsString($banned, substr($body, strpos($body, '<main>')));
        $this->assertStringContainsString('href="/designers/" class="active">Дизайнери', $body);
    }

    public function test_public_profile_hides_hidden_works_and_initials(): void
    {
        $id = $this->fakeDesigner(51, 'Іван Петренко', DesignerStatus::APPROVED, 2);
        $hidden = (int)Sql::value('SELECT MIN(id) FROM design_portfolio WHERE designer_id = ?', [$id]);
        Sql::exec('UPDATE design_portfolio SET shw = 0 WHERE id = ?', [$hidden]);
        [, , $body] = self::fetch("/designers/$id/", self::cookie());
        $this->assertStringNotContainsString("/media/$id/$hidden-", $body);
        $this->assertStringContainsString('Портфоліо · 1 робота', $body);
        $this->assertStringContainsString('>ІП<', $body);   // ініціали, фото немає
    }
}
