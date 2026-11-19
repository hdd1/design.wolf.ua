<?php

use Design\Designers;
use Design\DesignerStatus;
use Design\Messages;
use Design\OfferStatus;
use Design\OrderStatus;
use Design\Products;
use Design\Sql;
use Design\View;
use PHPUnit\Framework\TestCase;

/**
 * Листування по-справжньому через nginx dev.design.wolf.ua.
 * Клієнт — DESIGN_TEST_CUSTOMER_ID (29), дизайнер — DESIGN_TEST_DESIGNER_ID (25). Замовлення й пропозиції тест створює сам
 * (id — автоінкремент) і прибирає лише їх. Рядок design_designers для 25 тест заводить сам, а якщо він уже є — пропуск:
 * справжні дані не чіпаємо. customers — лише читання.
 * Сповіщення йдуть через php-fpm (DESIGN_NOTIFY там не off), тому перед записом співрозмовник «в чаті» (seen_at = NOW()),
 * крім одного навмисного сповіщення в test_both_sides_read_and_write.
 *
 * @group http
 */
final class ChatHttpTest extends TestCase
{
    private const UA = 'DesignTest/1';
    private const STRANGER = 999999721;          // чужий клієнт (рядка в customers немає)
    private const STRANGER_DESIGNER = 999999722; // чужий дизайнер
    private const DESIGNER_NAME = 'Тест <b>Дизайнер</b>';

    /** @var list<int> */
    private array $orders = [];
    private bool $ownsDesigner = false;
    /** @var array<int, string> csrf за клієнтом на цей запуск */
    private static array $csrf = [];

    protected function tearDown(): void
    {
        if ($this->orders) {
            $in = implode(',', array_map('intval', $this->orders));
            Sql::exec("DELETE m FROM design_messages m JOIN design_offers f ON f.id = m.offer_id WHERE f.order_id IN ($in)");
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

    private static function cookie(int $id): string
    {
        $pass = (string)Sql::value('SELECT customer_pass FROM customers WHERE id = ?', [$id]);
        return 'wolfid=' . $id . '.' . hash_hmac('md5', $pass . self::UA, (string)$id);
    }

    /** @return array{0:int,1:string,2:string} код, заголовки, тіло */
    private static function fetch(string $path, int $as, ?array $post = null): array
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

    /** CSRF клієнта з форми брифу (секрет php-fpm, не тестовий) */
    private static function csrf(int $as): string
    {
        if (!isset(self::$csrf[$as])) {
            [, , $html] = self::fetch('/brief/' . Products::visible()[0]['id'] . '/', $as);
            self::$csrf[$as] = preg_match('/name="csrf" value="([0-9a-f]{64})"/', $html, $m) ? $m[1] : '';
        }
        return self::$csrf[$as];
    }

    /** @return array{0:int,1:array} код і розібраний JSON */
    private static function poll(int $offerId, int $as, int|string $after = 0): array
    {
        [$code, $h, $body] = self::fetch("/offers/$offerId/messages?after=$after", $as);
        if ($code !== 404) {
            self::assertMatchesRegularExpression('#^content-type:\s*application/json#mi', $h);
        }
        return [$code, (array)json_decode($body, true)];
    }

    /** @return array{0:int,1:array} */
    private static function send(int $offerId, int $as, string $text, ?string $csrf = null): array
    {
        [$code, , $body] = self::fetch("/offers/$offerId/messages", $as, ['text' => $text, 'csrf' => $csrf ?? self::csrf($as)]);
        return [$code, (array)json_decode($body, true)];
    }

    private function order(int $customerId, int $status = OrderStatus::COLLECTING): int
    {
        $id = Sql::insert('design_orders', [
            'customer_id' => $customerId,
            'product_id' => Products::visible()[0]['id'],
            'mode' => 1,
            'status' => $status,
            'answers' => json_encode([['q' => 'Текст', 'a' => 'Тест листування']], JSON_UNESCAPED_UNICODE),
        ]);
        Sql::exec('UPDATE design_orders SET published_at = NOW() WHERE id = ?', [$id]);
        $this->orders[] = $id;
        return $id;
    }

    private function offer(int $orderId, int $designerId, int $status = OfferStatus::SENT): int
    {
        return Sql::insert('design_offers', [
            'order_id' => $orderId, 'designer_id' => $designerId, 'status' => $status,
            'text' => 'Зроблю', 'price_designer' => 1000, 'markup_percent' => 20, 'price_client' => 1200,
            'deadline' => date('Y-m-d', strtotime('+3 day')),
        ]);
    }

    /** Співрозмовник «в чаті» — сповіщення не піде */
    private static function inChat(int $offerId): void
    {
        Sql::exec('UPDATE design_offers SET client_seen_at = NOW(), designer_seen_at = NOW() WHERE id = ?', [$offerId]);
    }

    private static function offerRow(int $id): array
    {
        return Sql::one('SELECT * FROM design_offers WHERE id = ?', [$id]);
    }

    public function test_both_sides_read_and_write(): void
    {
        $client = self::clientId();
        $designer = $this->designer();
        $offerId = $this->offer($this->order($client), $designer);

        [$code, $json] = self::poll($offerId, $client);
        $this->assertSame(200, $code);
        $this->assertSame(['messages' => [], 'write' => true], $json);
        $this->assertNotNull(self::offerRow($offerId)['client_seen_at'], 'GET відмічає seen клієнта');
        $this->assertNull(self::offerRow($offerId)['designer_seen_at']);

        // Перше повідомлення клієнта — дизайнер ще не відкривав листування: одне справжнє сповіщення (dev api2)
        [$code, $json] = self::send($offerId, $client, '  Добрий день! Мапа є?  ');
        $this->assertSame(200, $code, json_encode($json, JSON_UNESCAPED_UNICODE));
        $m = $json['message'];
        $this->assertSame(['id', 'mine', 'system', 'author', 'text', 'time'], array_keys($m));
        $this->assertTrue($m['mine']);
        $this->assertFalse($m['system']);
        $this->assertSame('Добрий день! Мапа є?', $m['text']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d$/', $m['time']);
        $row = self::offerRow($offerId);
        $this->assertNotNull($row['designer_notified_at'], 'notifyNew дизайнеру');
        $this->assertNull($row['client_notified_at']);

        // Дизайнер бачить клієнта коротким іменем
        [$code, $json] = self::poll($offerId, $designer);
        $this->assertSame(200, $code);
        $this->assertTrue($json['write']);
        $this->assertCount(1, $json['messages']);
        $this->assertFalse($json['messages'][0]['mine']);
        $this->assertSame(\Design\Customers::header($client)['short'], $json['messages'][0]['author']);
        $this->assertNotNull(self::offerRow($offerId)['designer_seen_at']);

        self::inChat($offerId);
        [$code, $json] = self::send($offerId, $designer, 'Намалюю сам');
        $this->assertSame(200, $code);
        $this->assertTrue($json['message']['mine']);
        $this->assertNull(self::offerRow($offerId)['client_notified_at'], 'клієнт «в чаті» — без сповіщення');

        // Клієнт бачить дизайнера за design_designers.name
        [, $json] = self::poll($offerId, $client);
        $this->assertSame([true, false], array_column($json['messages'], 'mine'));
        $this->assertSame(self::DESIGNER_NAME, $json['messages'][1]['author']);
        $this->assertSame(2, Messages::count($offerId));

        // Системне повідомлення: без автора, не «моє»
        Messages::system($offerId, 'Ціну змінено з 1 000 на 1 200 грн');
        [, $json] = self::poll($offerId, $client, $json['messages'][1]['id']);
        $this->assertSame([['mine' => false, 'system' => true, 'author' => '', 'text' => 'Ціну змінено з 1 000 на 1 200 грн']],
            array_map(static fn(array $m): array => array_intersect_key($m, ['mine' => 1, 'system' => 1, 'author' => 1, 'text' => 1]), $json['messages']));

        // Без CSRF — 403, порожнє — 422, задовге — 422; нічого не записано
        [$code, $json] = self::send($offerId, $client, 'без токена', '');
        $this->assertSame(403, $code);
        $this->assertSame('Сесія застаріла — оновіть сторінку', $json['error']);
        [$code, $json] = self::send($offerId, $client, '   ');
        $this->assertSame(422, $code);
        $this->assertSame('Напишіть повідомлення', $json['error']);
        [$code, $json] = self::send($offerId, $client, str_repeat('я', Messages::TEXT_MAX + 1));
        $this->assertSame(422, $code);
        $this->assertSame('Повідомлення — не більше 2000 символів', $json['error']);
        $this->assertSame(2, Messages::count($offerId));
    }

    public function test_foreign_offer_messages_404(): void
    {
        $client = self::clientId();
        $designer = self::designerId();
        // Чуже замовлення з чужою пропозицією: ні клієнт 29, ні дизайнер 25 до нього не причетні
        $foreign = $this->offer($this->order(self::STRANGER), self::STRANGER_DESIGNER);
        // Замовлення клієнта 29 з пропозицією чужого дизайнера: 25 не бачить
        $othersOffer = $this->offer($this->order($client), self::STRANGER_DESIGNER);

        foreach ([[$foreign, $client], [$foreign, $designer], [$othersOffer, $designer], [999999999, $client]] as [$offerId, $as]) {
            [$code] = self::poll($offerId, $as);
            $this->assertSame(404, $code, "GET $offerId як $as");
            [$code] = self::send($offerId, $as, 'Привіт');
            $this->assertSame(404, $code, "POST $offerId як $as");
        }
        $this->assertSame(0, (int)Sql::value('SELECT COUNT(*) FROM design_messages WHERE offer_id IN (?, ?)', [$foreign, $othersOffer]));
        $this->assertNull(self::offerRow($foreign)['client_seen_at']);
        $this->assertNull(self::offerRow($othersOffer)['designer_seen_at']);
    }

    public function test_closed_offer_is_read_only(): void
    {
        $client = self::clientId();
        $designer = $this->designer();
        $offerId = $this->offer($this->order($client), $designer, OfferStatus::OTHER_CHOSEN);
        Messages::add($offerId, $designer, Messages::ROLE_DESIGNER, 'Було відкрито');

        foreach ([$client, $designer] as $as) {
            [$code, $json] = self::poll($offerId, $as);
            $this->assertSame(200, $code);
            $this->assertFalse($json['write']);
            $this->assertCount(1, $json['messages']);

            [$code, $json] = self::send($offerId, $as, 'Ще одне');
            $this->assertSame(403, $code);
            $this->assertSame(['error' => 'Листування закрите'], $json);
        }
        $this->assertSame(1, Messages::count($offerId));
    }

    /** Запрошення без відповіді: листування лише для читання з обох боків (починається з «Беру») */
    public function test_invited_offer_is_read_only(): void
    {
        $client = self::clientId();
        $designer = $this->designer();
        $orderId = $this->order($client, OrderStatus::WAITING_DESIGNER);
        Sql::exec('UPDATE design_orders SET mode = 2 WHERE id = ?', [$orderId]);
        $offerId = $this->offer($orderId, $designer, OfferStatus::INVITED);

        foreach ([$designer, $client] as $as) {
            [$code, $json] = self::poll($offerId, $as);
            $this->assertSame(200, $code);
            $this->assertFalse($json['write']);
            [$code, $json] = self::send($offerId, $as, 'А який формат?');
            $this->assertSame(403, $code);
            $this->assertSame(['error' => 'Листування закрите'], $json);
        }
        $this->assertSame(0, Messages::count($offerId));
        $this->assertNull(self::offerRow($offerId)['client_notified_at']);
    }

    /** Дизайнер більше не схвалений: обидві сторони читають, але не пишуть; сповіщень немає */
    public function test_unapproved_designer_chat_read_only(): void
    {
        $client = self::clientId();
        $designer = $this->designer();
        $offerId = $this->offer($this->order($client), $designer);
        Messages::add($offerId, $designer, Messages::ROLE_DESIGNER, 'Було до блокування');
        Sql::exec('UPDATE design_designers SET status = ? WHERE customer_id = ?', [DesignerStatus::BLOCKED, $designer]);

        foreach ([$designer, $client] as $as) {
            [$code, $json] = self::poll($offerId, $as);
            $this->assertSame(200, $code, "GET як $as");
            $this->assertFalse($json['write']);
            $this->assertSame(['Було до блокування'], array_column($json['messages'], 'text'));
            [$code, $json] = self::send($offerId, $as, 'Ще одне');
            $this->assertSame(403, $code, "POST як $as");
            $this->assertSame(['error' => 'Листування закрите'], $json);
        }
        $this->assertSame(1, Messages::count($offerId));
        $row = self::offerRow($offerId);
        $this->assertNull($row['client_notified_at']);
        $this->assertNull($row['designer_notified_at']);

        // клієнт відкриває листування недоступного дизайнера — лише читання
        [$code, , $html] = self::fetch('/orders/' . $this->orders[0] . "/?chat=$offerId", $client);
        $this->assertSame(200, $code);
        $this->assertStringContainsString('data-chat="' . $offerId . '"', $html);
        $this->assertStringContainsString('data-write="0"', $html);
        $this->assertStringContainsString('Дизайнер недоступний', $html);
    }

    public function test_poll_after_id(): void
    {
        $client = self::clientId();
        $designer = $this->designer();
        $offerId = $this->offer($this->order($client), $designer);
        $ids = [];
        for ($i = 1; $i <= 105; $i++) {
            $ids[] = Messages::add($offerId, $i % 2 ? $client : $designer, $i % 2 ? Messages::ROLE_CLIENT : Messages::ROLE_DESIGNER, "№$i");
        }

        [, $json] = self::poll($offerId, $client, $ids[1]);
        $this->assertCount(100, $json['messages'], 'не більше 100 за раз');
        $this->assertSame(array_slice($ids, 2, 100), array_column($json['messages'], 'id'));
        $this->assertSame('№3', $json['messages'][0]['text']);
        $this->assertTrue($json['messages'][0]['mine']);
        $this->assertFalse($json['messages'][1]['mine']);

        [, $json] = self::poll($offerId, $client, $ids[101]);
        $this->assertSame(array_slice($ids, 102), array_column($json['messages'], 'id'));

        [, $json] = self::poll($offerId, $client, end($ids));
        $this->assertSame([], $json['messages']);

        [$code, $json] = self::poll($offerId, $designer, 'abc');
        $this->assertSame(200, $code, 'нечислове after — з початку');
        $this->assertSame($ids[0], $json['messages'][0]['id']);
    }

    public function test_html_escaped_in_json(): void
    {
        $client = self::clientId();
        $designer = $this->designer();
        $offerId = $this->offer($this->order($client), $designer);
        self::inChat($offerId);
        $text = '<script>alert(1)</script> & "лапки" https://example.com/a?b=1&c=<x>';

        [$code, $json] = self::send($offerId, $client, $text);
        $this->assertSame(200, $code);
        $this->assertSame($text, $json['message']['text'], 'у JSON — сирий текст: chat.js кладе його лише через textContent');

        [, $json] = self::poll($offerId, $designer);
        $this->assertSame($text, $json['messages'][0]['text']);

        // Серверний перший рендер того ж листування — екранований, посилання з rel
        Messages::add($offerId, $designer, Messages::ROLE_DESIGNER, 'Відповідь');
        $access = \Design\Offers::access($offerId, $client);
        $html = View::fetch('partials/chat', [
            'offerId' => $offerId, 'title' => self::DESIGNER_NAME, 'subtitle' => '1 200 грн', 'note' => null,
            'csrf' => 'x', 'messages' => \Design\Chat::messages($access), 'write' => true, 'footer' => null,
        ]);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<b>Дизайнер', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;лапки&quot;', $html);
        $this->assertStringContainsString('Тест &lt;b&gt;Дизайнер&lt;/b&gt;', $html);
        $this->assertStringContainsString('<a href="https://example.com/a?b=1&amp;c=" target="_blank" rel="noopener nofollow ugc">https://example.com/a?b=1&amp;c=</a>&lt;x&gt;', $html);
    }
}
