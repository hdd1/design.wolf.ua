<?php

use Design\Csrf;
use Design\Products;
use Design\Sql;
use PHPUnit\Framework\TestCase;

/**
 * Субакаунт wolf.ua (customers.parent_id ≠ 0) на design нічого не може — по-справжньому через nginx dev.design.wolf.ua.
 * Клієнт — DESIGN_TEST_CUSTOMER_ID (29): тест тимчасово ставить йому parent_id = DESIGN_TEST_DESIGNER_ID (25)
 * і в tearDown повертає початкове значення, навіть якщо тест упав.
 *
 * @group http
 */
final class SubAccountHttpTest extends TestCase
{
    private const UA = 'DesignTest/1';
    private const BANNER = 'з субакаунта замовити дизайн не можна';

    private ?int $origParent = null;

    protected function setUp(): void
    {
        $me = self::me();
        $this->origParent = (int)Sql::value('SELECT parent_id FROM customers WHERE id = ?', [$me]);
    }

    protected function tearDown(): void
    {
        if ($this->origParent !== null) {
            Sql::exec('UPDATE customers SET parent_id = ? WHERE id = ?', [$this->origParent, self::me()]);
            $this->origParent = null;
        }
    }

    private static function me(): int
    {
        $id = (int)getenv('DESIGN_TEST_CUSTOMER_ID');
        if ($id <= 0) self::markTestSkipped('DESIGN_TEST_CUSTOMER_ID не задан');
        return $id;
    }

    private static function parent(): int
    {
        $id = (int)getenv('DESIGN_TEST_DESIGNER_ID');
        if ($id <= 0) self::markTestSkipped('DESIGN_TEST_DESIGNER_ID не задан');
        return $id;
    }

    private static function becomeSub(): void
    {
        Sql::exec('UPDATE customers SET parent_id = ? WHERE id = ?', [self::parent(), self::me()]);
    }

    private static function cookie(): string
    {
        $id = self::me();
        $pass = (string)Sql::value('SELECT customer_pass FROM customers WHERE id = ?', [$id]);
        return 'wolfid=' . $id . '.' . hash_hmac('md5', $pass . self::UA, (string)$id);
    }

    /** @return array{0:int,1:string,2:string} код, заголовки, тіло */
    private static function fetch(string $path, ?array $post = null): array
    {
        $ch = curl_init('https://dev.design.wolf.ua' . $path);
        $opts = [
            CURLOPT_RESOLVE        => ['dev.design.wolf.ua:443:127.0.0.1'],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_USERAGENT      => self::UA,
            CURLOPT_HTTPHEADER     => ['Cookie: ' . self::cookie()],
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
        return [$code, substr((string)$raw, 0, $hs), substr((string)$raw, $hs)];
    }

    /** @return array<string, array{0:string}> */
    public static function pages(): array
    {
        return [
            'мої замовлення' => ['/orders/'],
            'новий бриф'     => ['/brief/'],
            'форма брифу'    => ['/brief/' . 'PID' . '/'],
            'дизайнер'       => ['/designer/'],
            'дизайнери'      => ['/designers/'],
            'стрічка'        => ['/feed/'],
            'мої пропозиції' => ['/my-offers/'],
        ];
    }

    /** @dataProvider pages */
    public function test_pages_show_banner_with_403(string $path): void
    {
        self::becomeSub();
        if (str_contains($path, 'PID')) $path = str_replace('PID', (string)Products::visible()[0]['id'], $path);
        [$code, $h, $body] = self::fetch($path);
        $this->assertSame(403, $code, $path);
        $this->assertMatchesRegularExpression('#^cache-control:.*no-store#mi', $h);
        $this->assertStringContainsString(self::BANNER, $body, $path);
        $this->assertStringContainsString('потрібен основний акаунт wolf.ua', $body);
        $this->assertMatchesRegularExpression('#href="https://(dev\.)?wolf\.ua/"#', $body);
    }

    public function test_home_is_open(): void
    {
        self::becomeSub();
        [$code, , $body] = self::fetch('/');
        $this->assertSame(200, $code);
        $this->assertStringNotContainsString(self::BANNER, $body);
    }

    public function test_main_account_not_blocked(): void
    {
        $this->assertSame(0, $this->origParent, 'тестовий клієнт має бути основним акаунтом');
        [$code, , $body] = self::fetch('/brief/');
        $this->assertSame(200, $code);
        $this->assertStringNotContainsString(self::BANNER, $body);
    }

    public function test_post_brief_is_rejected_with_json_and_creates_nothing(): void
    {
        $me = self::me();
        $pid = Products::visible()[0]['id'];
        $answers = array_fill(0, count(Products::get($pid)['questions']), 'x');
        $count = static fn(): int => (int)Sql::value('SELECT COUNT(*) FROM design_orders WHERE customer_id = ?', [$me]);
        $before = $count();
        self::becomeSub();
        [$code, $h, $body] = self::fetch('/brief', [
            'product_id' => $pid, 'title' => 'Субакаунт', 'answers' => $answers, 'action' => 'draft', 'csrf' => Csrf::token($me),
        ]);
        $this->assertSame(403, $code);
        $this->assertMatchesRegularExpression('#^content-type:\s*application/json#mi', $h);
        $err = json_decode($body, true)['error'] ?? '';
        $this->assertStringContainsString('субакаунта', $err);
        $this->assertSame($before, $count());
    }

    public function test_post_designer_application_is_rejected(): void
    {
        $me = self::me();
        $had = Sql::value('SELECT COUNT(*) FROM design_designers WHERE customer_id = ?', [$me]);
        self::becomeSub();
        [$code, , $body] = self::fetch('/designer', ['action' => 'draft', 'csrf' => Csrf::token($me), 'bio' => 'x']);
        $this->assertSame(403, $code);
        $this->assertStringContainsString('субакаунта', json_decode($body, true)['error'] ?? '');
        $this->assertEquals($had, Sql::value('SELECT COUNT(*) FROM design_designers WHERE customer_id = ?', [$me]));
    }

    public function test_ajax_get_json_endpoints_get_json_403(): void
    {
        self::becomeSub();
        foreach (['/orders/1/layouts', '/offers/1/messages'] as $path) {
            [$code, $h, $body] = self::fetch($path);
            $this->assertSame(403, $code, $path);
            $this->assertMatchesRegularExpression('#^content-type:\s*application/json#mi', $h, $path);
            $this->assertStringContainsString('субакаунта', json_decode($body, true)['error'] ?? '', $path);
        }
    }
}
