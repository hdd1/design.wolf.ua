<?php

use Design\Designers;
use Design\DesignerStatus;
use Design\OfferStatus;
use Design\OrderStatus;
use Design\Products;
use Design\Sql;
use PHPUnit\Framework\TestCase;

/**
 * «Заробіток» дизайнера по-справжньому через nginx dev.design.wolf.ua: GET /earnings/.
 * Дизайнер — DESIGN_TEST_DESIGNER_ID (25; рядок design_designers тест заводить сам, є чужий — пропуск), клієнт — DESIGN_TEST_CUSTOMER_ID (29).
 * Покупки — рядки design_purchases напряму, без руху грошей (customers і transaction не чіпаємо); усе створене видаляється
 *
 * @group http
 */
final class EarningsHttpTest extends TestCase
{
    private const UA = 'DesignTest/1';
    private const OTHER_DESIGNER = 999999733;   // чужий дизайнер (рядка в customers немає)

    /** @var list<int> */
    private array $orders = [];
    private bool $ownsDesigner = false;

    protected function tearDown(): void
    {
        if ($this->orders) {
            $in = implode(',', $this->orders);
            Sql::exec("DELETE FROM design_purchases WHERE order_id IN ($in)");
            Sql::exec("DELETE FROM design_offers WHERE order_id IN ($in)");
            Sql::exec("DELETE FROM design_orders WHERE id IN ($in)");
            $this->orders = [];
        }
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

    private function designer(int $status = DesignerStatus::APPROVED): int
    {
        $id = self::designerId();
        if (Designers::get($id) !== null) $this->markTestSkipped("у клієнта $id вже є профіль дизайнера — тест не чіпає справжні дані");
        Sql::insert('design_designers', ['customer_id' => $id, 'status' => $status, 'name' => 'Тест Дизайнер заробітку']);
        $this->ownsDesigner = true;
        return $id;
    }

    /** Замовлення клієнта з обраною пропозицією дизайнера і проведеною покупкою на $amountDesigner (гонорар) */
    private function purchase(int $designerId, int $amountDesigner, string $title, string $paidAt, int $status = 2): int
    {
        $orderId = Sql::insert('design_orders', [
            'customer_id' => self::clientId(), 'product_id' => Products::visible()[0]['id'], 'mode' => 1, 'status' => OrderStatus::PURCHASED,
            'title' => $title, 'answers' => json_encode([['q' => 'Розмір', 'a' => '90×50']], JSON_UNESCAPED_UNICODE),
        ]);
        $this->orders[] = $orderId;
        $offerId = Sql::insert('design_offers', [
            'order_id' => $orderId, 'designer_id' => $designerId, 'status' => OfferStatus::CHOSEN, 'text' => 'Зроблю',
            'price_designer' => $amountDesigner, 'markup_percent' => 20, 'price_client' => (int)($amountDesigner * 1.2),
        ]);
        Sql::exec('UPDATE design_orders SET chosen_offer_id = ? WHERE id = ?', [$offerId, $orderId]);
        Sql::insert('design_purchases', [
            'order_id' => $orderId, 'offer_id' => $offerId, 'payer_id' => self::clientId(), 'status' => $status,
            'price_client' => (int)($amountDesigner * 1.2), 'price_designer' => $amountDesigner,
            'amount_client' => (int)($amountDesigner * 1.2), 'amount_designer' => $amountDesigner, 'paid_at' => $paidAt,
        ]);
        return $orderId;
    }

    private static function cookie(int $id): string
    {
        $pass = (string)Sql::value('SELECT customer_pass FROM customers WHERE id = ?', [$id]);
        return 'wolfid=' . $id . '.' . hash_hmac('md5', $pass . self::UA, (string)$id);
    }

    /** @return array{0:int,1:string,2:string} код, заголовки, тіло */
    private static function fetch(string $path, int $as, string $method = 'GET'): array
    {
        $ch = curl_init('https://dev.design.wolf.ua' . $path);
        curl_setopt_array($ch, [
            CURLOPT_RESOLVE        => ['dev.design.wolf.ua:443:127.0.0.1'],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_USERAGENT      => self::UA,
            CURLOPT_HTTPHEADER     => ['Cookie: ' . self::cookie($as)],
        ]);
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        return [$code, substr($raw, 0, $hs), substr($raw, $hs)];
    }

    private static function main(string $html): string
    {
        $a = strpos($html, '<main>');
        return substr($html, $a, strpos($html, '</main>') - $a);
    }

    public function test_requires_approved_designer(): void
    {
        $designer = self::designerId();
        if (Designers::get($designer) !== null) $this->markTestSkipped("у клієнта $designer вже є профіль дизайнера");
        // клієнт (не дизайнер) і дизайнер на розгляді — 404, меню без «Заробіток»
        foreach ([null, DesignerStatus::PENDING] as $status) {
            if ($status !== null) $this->designer($status);
            [$code, , $body] = self::fetch('/earnings/', $designer);
            $this->assertSame(404, $code, 'статус ' . var_export($status, true));
            $this->assertStringNotContainsString('/earnings/', $body);
        }
        // справжній клієнт-не-дизайнер
        [$code] = self::fetch('/earnings/', self::clientId());
        if (Designers::get(self::clientId()) === null) $this->assertSame(404, $code);
        // гість — сторінка входу (401), без заробітку
        $ch = curl_init('https://dev.design.wolf.ua/earnings/');
        curl_setopt_array($ch, [CURLOPT_RESOLVE => ['dev.design.wolf.ua:443:127.0.0.1'], CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_USERAGENT => self::UA]);
        $body = (string)curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $this->assertSame(401, $code);
        $this->assertStringNotContainsString('Для виведення коштів', $body);
        // POST на /earnings/ не існує
        [$code] = self::fetch('/earnings/', self::clientId(), 'POST');
        $this->assertContains($code, [404, 405]);
    }

    public function test_total_and_only_own_completed(): void
    {
        $designer = $this->designer();
        $a = $this->purchase($designer, 1000, 'Флаєр СТО «Мотор»', '2026-11-18 10:30:00');
        $b = $this->purchase($designer, 2500, 'Логотип <b>кав\'ярні</b>', '2026-11-19 12:00:00');
        $pending = $this->purchase($designer, 7000, 'Непроведена покупка', '2026-11-19 13:00:00', 1);
        $cancelledPurchase = $this->purchase($designer, 8000, 'Скасована покупка', '2026-11-19 14:00:00', 3);
        $foreign = $this->purchase(self::OTHER_DESIGNER, 30000, 'Чужий гонорар', '2026-11-19 15:00:00');

        [$code, , $html] = self::fetch('/earnings/', $designer);
        $this->assertSame(200, $code);
        $main = self::main($html);

        $this->assertStringContainsString('Флаєр СТО «Мотор»', $main);
        $this->assertStringContainsString('№' . $a, $main);
        $this->assertStringContainsString('№' . $b, $main);
        // назва екранована
        $this->assertStringContainsString('Логотип &lt;b&gt;кав&#039;ярні&lt;/b&gt;', $main);
        $this->assertStringNotContainsString('<b>кав', $main);
        // нові зверху
        $this->assertLessThan(strpos($main, '№' . $a), strpos($main, '№' . $b));
        // сума: проведені 1000 + 2500 (гроші цілі гривні, пробіл-розділювач тисяч — як View::price)
        $this->assertMatchesRegularExpression('/Разом[^<]*<[^>]*>\s*3[\s\x{00A0}]500[\s\x{00A0}]грн/u', $main);
        $this->assertStringContainsString('Для виведення коштів зверніться до менеджера', $main);
        foreach ([$pending, $cancelledPurchase, $foreign] as $id) $this->assertStringNotContainsString('№' . $id . '<', $main);
        $this->assertStringNotContainsString('Непроведена покупка', $main);
        $this->assertStringNotContainsString('Скасована покупка', $main);
        $this->assertStringNotContainsString('Чужий гонорар', $main);
        $this->assertStringNotContainsString('7 000', $main);
        $this->assertStringNotContainsString('30 000', $main);
        // пункт меню
        $this->assertMatchesRegularExpression('#<a href="/earnings/" class="active">Заробіток#', $html);
        $this->assertStringNotContainsString('<form', $main, 'кнопки виведення немає');
        $this->assertStringNotContainsString('Вивести', $main);
    }

    public function test_empty_state(): void
    {
        $designer = $this->designer();
        [$code, , $html] = self::fetch('/earnings/', $designer);
        $this->assertSame(200, $code);
        $main = self::main($html);
        $this->assertStringContainsString('Поки що немає', $main);
        $this->assertMatchesRegularExpression('/Разом[^<]*<[^>]*>\s*0[\s\x{00A0}]грн/u', $main);
        $this->assertStringContainsString('Для виведення коштів зверніться до менеджера', $main);
    }
}
