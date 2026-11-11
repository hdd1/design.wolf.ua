<?php

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

    /** @return array{0:int,1:string,2:string} код, заголовки, тело */
    private static function fetch(string $path, string $cookie = '', ?array $post = null): array
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
            $opts[CURLOPT_POSTFIELDS] = http_build_query($post);
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
        $this->assertSame(404, self::fetch("/orders/$id/mode/", self::cookie())[0]);
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
}
