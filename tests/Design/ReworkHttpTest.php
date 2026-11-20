<?php

use Design\DesignerStatus;
use Design\OfferStatus;
use Design\OrderStatus;
use Design\Paths;
use Design\Products;
use Design\Rework;
use Design\Sql;
use PHPUnit\Framework\TestCase;

/**
 * «Ще не закінчили» по-справжньому через nginx dev.design.wolf.ua (етап 5, Task 6): кнопка й форма в дизайнера на купленому
 * замовленні, POST /orders/{id}/rework (propose | accept | decline | cancel), «Прийняти» / «Відмовитися» і «чий хід» у клієнта,
 * «Скасувати доопрацювання», ролі (чужа дія — відмова), застарілий CSRF, stage в опитуванні листування.
 * Клієнт — DESIGN_TEST_CUSTOMER_ID (29), дизайнер — DESIGN_TEST_DESIGNER_ID (25): тест робить 25 схваленим дизайнером
 * (є свій профіль — пропуск) і обраним на своєму купленому замовленні (1 000 на руки, 1 200 для клієнта, k 20 %). Грошей тест
 * не рухає (купівлю на доопрацюванні перевіряє ReworkTest). Куплений макет — рядок design_files без файла (посилання cf немає).
 * Сповіщення php-fpm кладе в dev api2 (n_smser) — тест прибирає рядки «…доопрацювання №N…» своїх замовлень.
 *
 * @group http
 */
final class ReworkHttpTest extends TestCase
{
    private const UA = 'DesignTest/1';
    private const CF = '/home/connect/cloud-links/cf';

    /** @var list<int> */
    private array $orders = [];
    private bool $ownsDesigner = false;
    /** @var array<int, string> */
    private static array $csrf = [];

    protected function tearDown(): void
    {
        $steps = [
            function (): void {
                // Сповіщення йде після відповіді (fastcgi_finish_request) — може з'явитися за мить
                usleep(300000);
                foreach ($this->orders as $id) {
                    Sql::exec('DELETE FROM n_smser WHERE customer_id IN (?, ?) AND messenger_text LIKE ?',
                        [self::clientId(), self::designerId(), '%доопрацювання №' . $id . '%']);
                }
            },
            function (): void {
                if (!$this->orders) return;
                $in = implode(',', array_map('intval', $this->orders));
                Sql::exec("DELETE FROM design_purchases WHERE order_id IN ($in)");
                Sql::exec("DELETE FROM design_previews WHERE order_id IN ($in)");
                Sql::exec("DELETE FROM design_files WHERE order_id IN ($in)");
                Sql::exec("DELETE m FROM design_messages m JOIN design_offers f ON f.id = m.offer_id WHERE f.order_id IN ($in)");
                Sql::exec("DELETE FROM design_offers WHERE order_id IN ($in)");
                Sql::exec("DELETE FROM design_orders WHERE id IN ($in)");
                $this->orders = [];
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

    private function designer(): int
    {
        $id = self::designerId();
        if (Sql::value('SELECT customer_id FROM design_designers WHERE customer_id = ?', [$id]) !== null) {
            $this->markTestSkipped("у клієнта $id вже є профіль дизайнера — тест не чіпає справжні дані");
        }
        Sql::insert('design_designers', ['customer_id' => $id, 'status' => DesignerStatus::APPROVED, 'name' => 'Тест Дизайнер доопрацювання']);
        $this->ownsDesigner = true;
        return $id;
    }

    /** Куплене замовлення клієнта з обраною пропозицією (1 000 / 1 200, k 20 %) і одним купленим макетом → [orderId, offerId] */
    private function purchased(int $client, int $designer): array
    {
        $orderId = Sql::insert('design_orders', [
            'customer_id' => $client,
            'product_id' => Products::visible()[0]['id'],
            'mode' => 1,
            'status' => OrderStatus::PURCHASED,
            'title' => 'Візитка для кавʼярні «Зерно»',
            'answers' => json_encode([['q' => 'Текст', 'a' => 'Тест доопрацювання']], JSON_UNESCAPED_UNICODE),
            'paid_client' => 1200,
            'paid_designer' => 1000,
        ]);
        $this->orders[] = $orderId;
        Sql::exec('UPDATE design_orders SET published_at = NOW(), purchased_at = NOW() WHERE id = ?', [$orderId]);
        $offerId = Sql::insert('design_offers', [
            'order_id' => $orderId, 'designer_id' => $designer, 'status' => OfferStatus::CHOSEN,
            'text' => 'Зроблю', 'price_designer' => 1000, 'markup_percent' => 20, 'price_client' => 1200,
            'deadline' => '2026-11-15',
        ]);
        Sql::exec('UPDATE design_orders SET chosen_offer_id = ?, chosen_at = NOW() WHERE id = ?', [$offerId, $orderId]);
        Sql::exec('UPDATE design_offers SET client_seen_at = NOW(), designer_seen_at = NOW() WHERE id = ?', [$offerId]);
        Sql::insert('design_files', [
            'order_id' => $orderId, 'label' => 'Візитка', 'original_name' => 'Візитка.pdf', 'file' => 'test-none.pdf',
            'size' => 1000, 'md5' => md5('x'), 'status' => 1, 'purchase_id' => 999999,
        ]);
        return [$orderId, $offerId];
    }

    private static function cookie(int $id): string
    {
        $pass = (string)Sql::value('SELECT customer_pass FROM customers WHERE id = ?', [$id]);
        return 'wolfid=' . $id . '.' . hash_hmac('md5', $pass . self::UA, (string)$id);
    }

    /** @return array{0:int,1:string,2:string} код, заголовки, тіло */
    private static function fetch(string $path, int $as, ?array $post = null): array
    {
        $ch = curl_init();
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
            $opts[CURLOPT_POSTFIELDS] = http_build_query($post);
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

    /** POST /orders/{id}/rework → [код, Location, тіло] */
    private static function rework(int $orderId, int $as, array $post): array
    {
        [$code, $h, $body] = self::fetch("/orders/$orderId/rework", $as, $post + ['csrf' => self::csrf($as)]);
        return [$code, preg_match('/^location:\s*(\S+)/mi', $h, $m) ? $m[1] : '', $body];
    }

    /** Текст сторінки без тегів, з одинарними пробілами (НБСП — пробіл) */
    private static function text(string $html): string
    {
        $html = preg_replace('~<(script|style)\b.*?</\1>~su', ' ', $html);
        $t = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $t));
    }

    private static function page(string $path, int $as): string
    {
        [$code, , $html] = self::fetch($path, $as);
        self::assertSame(200, $code, $path);
        return $html;
    }

    private static function row(int $orderId): array
    {
        return Sql::one('SELECT o.status, o.rework_price_designer, o.rework_price_client, o.rework_deadline, f.price_client, f.price_designer, f.deadline
                         FROM design_orders o JOIN design_offers f ON f.id = o.chosen_offer_id WHERE o.id = ?', [$orderId]);
    }

    private static function stage(int $offerId, int $as): string
    {
        [$code, , $body] = self::fetch("/offers/$offerId/messages", $as);
        self::assertSame(200, $code);
        return (string)(json_decode($body, true)['workspace']['stage'] ?? '');
    }

    /** Сповіщення php-fpm шле вже після перенаправлення — чекаємо рядок n_smser (до 3 с) */
    private static function sms(int $customerId, string $like): bool
    {
        for ($i = 0; $i < 30; $i++) {
            if (Sql::value('SELECT id FROM n_smser WHERE customer_id = ? AND messenger_text LIKE ?', [$customerId, $like]) !== null) return true;
            usleep(100000);
        }
        return false;
    }

    /** Форма дії доопрацювання на сторінці (action / кнопка) */
    private static function hasAction(string $html, int $orderId, string $action): bool
    {
        return (bool)preg_match('~<form[^>]*action="/orders/' . $orderId . '/rework"[^>]*>(?:(?!</form>).)*name="action" value="' . $action . '"~s', $html);
    }

    public function test_designer_proposes_client_declines(): void
    {
        $client = self::clientId();
        $designer = $this->designer();
        [$orderId, $offerId] = $this->purchased($client, $designer);
        $tomorrow = date('Y-m-d', strtotime('+1 day'));

        // Куплено: «Ще не закінчили» — лише в дизайнера
        $d = self::page("/feed/$orderId/", $designer);
        $t = self::text($d);
        $this->assertStringContainsString('Готово', $t);
        $this->assertStringContainsString('Ще не закінчили', $t);
        $this->assertStringContainsString('href="/feed/' . $orderId . '/?form=rework#ws-rework"', $d);
        $c = self::page("/orders/$orderId/", $client);
        $this->assertStringNotContainsString('Ще не закінчили', self::text($c));
        $this->assertStringContainsString('Макети ваші', self::text($c));
        $this->assertFalse(self::hasAction($c, $orderId, 'accept'));
        $this->assertSame('6', self::stage($offerId, $client));

        // Форма: нова повна сума (зараз — уже нараховане), «Готово до» не раніше сьогодні
        $f = self::page("/feed/$orderId/?form=rework", $designer);
        $this->assertTrue(self::hasAction($f, $orderId, 'propose'));
        $this->assertMatchesRegularExpression('~name="price"[^>]*value="1000"~', $f);
        $this->assertMatchesRegularExpression('~name="deadline"[^>]*min="' . date('Y-m-d') . '"~', $f);
        $ft = self::text($f);
        foreach (['Нова повна сума вам на руки', 'Уже нараховано 1 000 грн', 'Для клієнта — без доплати', 'Запропонувати'] as $s) {
            $this->assertStringContainsString($s, $ft, $s);
        }

        // Помилка — та сама сторінка з формою, введеним і текстом; нічого не записано
        [$code, , $html] = self::rework($orderId, $designer, ['action' => 'propose', 'price' => '1 300', 'deadline' => date('Y-m-d', strtotime('-1 day'))]);
        $this->assertSame(422, $code);
        $this->assertStringContainsString('Дата — не раніше сьогодні', self::text($html));
        $this->assertMatchesRegularExpression('~name="price"[^>]*value="1 300"~', $html);
        $this->assertNull(self::row($orderId)['rework_price_client']);

        // Клієнт не може пропонувати
        [$code] = self::rework($orderId, $client, ['action' => 'propose', 'price' => '1300', 'deadline' => $tomorrow]);
        $this->assertSame(422, $code);
        $this->assertNull(self::row($orderId)['rework_price_client']);

        // Пропозиція: 1 300 на руки → 1 560 для клієнта (k 20 %, округлення 10), доплата 360
        [$code, $loc] = self::rework($orderId, $designer, ['action' => 'propose', 'price' => '1 300', 'deadline' => $tomorrow]);
        $this->assertSame(302, $code);
        $this->assertSame("/feed/$orderId/?done=rework-sent", $loc);
        $r = self::row($orderId);
        $this->assertSame([1300, 1560, $tomorrow, OrderStatus::PURCHASED], [(int)$r['rework_price_designer'], (int)$r['rework_price_client'],
            $r['rework_deadline'], (int)$r['status']]);
        $this->assertTrue(self::sms($client, "Майстерня дизайну: дизайнер пропонує доопрацювання №$orderId: +360 грн%"), 'сповіщення клієнту');

        // Клієнт: «Чекає вашої відповіді», доплата й дата, «Прийняти» / «Відмовитися»
        $c = self::page("/orders/$orderId/", $client);
        $ct = self::text($c);
        $this->assertStringContainsString('Чекає вашої відповіді', $ct);
        $this->assertStringContainsString('Дизайнер пропонує доопрацювання: +360 грн, до ' . date('d.m.Y', strtotime('+1 day')), $ct);
        $this->assertTrue(self::hasAction($c, $orderId, 'accept'));
        $this->assertTrue(self::hasAction($c, $orderId, 'decline'));
        $this->assertStringContainsString('Прийняти', $ct);
        $this->assertStringContainsString('Відмовитися', $ct);
        $this->assertSame('6p', self::stage($offerId, $client));

        // Дизайнер: «Чекаємо відповіді клієнта», кнопки «Ще не закінчили» немає
        $d = self::text(self::page("/feed/$orderId/?done=rework-sent", $designer));
        $this->assertStringContainsString('Чекаємо відповіді клієнта', $d);
        $this->assertStringContainsString('Пропозицію доопрацювання надіслано', $d);
        $this->assertStringNotContainsString('Ще не закінчили', $d);
        $this->assertSame('6p', self::stage($offerId, $designer));

        // Дизайнер не може прийняти за клієнта; застарілий CSRF — 403; нічого не змінилось
        [$code] = self::rework($orderId, $designer, ['action' => 'accept']);
        $this->assertSame(422, $code);
        [$code] = self::rework($orderId, $client, ['action' => 'decline', 'csrf' => str_repeat('0', 64)]);
        $this->assertSame(403, $code);
        $this->assertSame(1560, (int)self::row($orderId)['rework_price_client']);

        // «Відмовитися»: пропозицію скинуто, «Куплено», дизайнер знову бачить «Ще не закінчили»
        [$code, $loc] = self::rework($orderId, $client, ['action' => 'decline']);
        $this->assertSame(302, $code);
        $this->assertSame("/orders/$orderId/?done=rework-declined", $loc);
        $this->assertTrue(self::sms($designer, "Майстерня дизайну: клієнт відмовився від доопрацювання №$orderId.%"), 'сповіщення дизайнеру');
        $r = self::row($orderId);
        $this->assertSame([null, OrderStatus::PURCHASED, 1200], [$r['rework_price_client'], (int)$r['status'], (int)$r['price_client']]);
        $ct = self::text(self::page("/orders/$orderId/?done=rework-declined", $client));
        $this->assertStringContainsString('Макети ваші', $ct);
        $this->assertStringContainsString('Клієнт відмовився від доопрацювання', $ct);
        $this->assertStringContainsString('Ще не закінчили', self::text(self::page("/feed/$orderId/", $designer)));
        $this->assertSame([], self::links($orderId));
    }

    public function test_accept_then_cancel_rework(): void
    {
        $client = self::clientId();
        $designer = $this->designer();
        [$orderId, $offerId] = $this->purchased($client, $designer);
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        Rework::propose($orderId, $designer, 1300, $tomorrow);

        [$code, $loc] = self::rework($orderId, $client, ['action' => 'accept']);
        $this->assertSame(302, $code);
        $this->assertSame("/orders/$orderId/?done=rework-accepted", $loc);
        $this->assertTrue(self::sms($designer, "Майстерня дизайну: клієнт прийняв доопрацювання №$orderId, до %"), 'сповіщення дизайнеру');
        $r = self::row($orderId);
        $this->assertSame([OrderStatus::REWORK, 1560, 1300, $tomorrow, null],
            [(int)$r['status'], (int)$r['price_client'], (int)$r['price_designer'], $r['deadline'], $r['rework_price_client']]);

        // Клієнт: «Доопрацювання», нова ціна, «Купую» (вимкнена — нових макетів ще немає), «Скасувати доопрацювання»
        $c = self::page("/orders/$orderId/?done=rework-accepted", $client);
        $ct = self::text($c);
        foreach (['Доопрацювання', 'Ціна 1 560 грн', 'Дизайнер працює над змінами', 'Доопрацювання прийнято', 'Скасувати доопрацювання',
                     'Ціну змінено з 1 200 на 1 560 грн'] as $s) {
            $this->assertStringContainsString($s, $ct, $s);
        }
        $this->assertMatchesRegularExpression('~<button[^>]*data-ws-buy[^>]*disabled~', $c);
        $this->assertTrue(self::hasAction($c, $orderId, 'cancel'));
        $this->assertSame('7', self::stage($offerId, $client));

        // Дизайнер працює як «В роботі»: «Завантажити макети», без «Ще не закінчили»
        $d = self::page("/feed/$orderId/", $designer);
        $dt = self::text($d);
        $this->assertStringContainsString('Завантажити макети', $dt);
        $this->assertStringContainsString('Ви отримаєте 1 300 грн', $dt);
        $this->assertStringNotContainsString('Ще не закінчили', $dt);
        $this->assertFalse(self::hasAction($d, $orderId, 'cancel'));

        // Дизайнер скасувати не може
        [$code] = self::rework($orderId, $designer, ['action' => 'cancel']);
        $this->assertSame(422, $code);
        $this->assertSame(OrderStatus::REWORK, (int)self::row($orderId)['status']);

        // «Скасувати доопрацювання»: «Куплено», ціни — сплачені, дата та сама
        [$code, $loc] = self::rework($orderId, $client, ['action' => 'cancel']);
        $this->assertSame(302, $code);
        $this->assertSame("/orders/$orderId/?done=rework-cancelled", $loc);
        $this->assertTrue(self::sms($designer, "Майстерня дизайну: клієнт скасував доопрацювання №$orderId.%"), 'сповіщення дизайнеру');
        $r = self::row($orderId);
        $this->assertSame([OrderStatus::PURCHASED, 1200, 1000, $tomorrow],
            [(int)$r['status'], (int)$r['price_client'], (int)$r['price_designer'], $r['deadline']]);
        $ct = self::text(self::page("/orders/$orderId/?done=rework-cancelled", $client));
        $this->assertStringContainsString('Макети ваші', $ct);
        $this->assertStringContainsString('Доопрацювання скасовано', $ct);
        $this->assertSame([], self::links($orderId));
    }

    /** Гість і чужий клієнт: дії немає */
    public function test_stranger_gets_404(): void
    {
        $client = self::clientId();
        $designer = $this->designer();
        [$orderId] = $this->purchased($client, $designer);
        Rework::propose($orderId, $designer, 1300, date('Y-m-d', strtotime('+1 day')));
        // 25 — не клієнт і не обраний дизайнер чужого замовлення: робимо обраним іншого (неіснуючого) дизайнера
        Sql::exec('UPDATE design_offers SET designer_id = 1 WHERE order_id = ?', [$orderId]);
        [$code] = self::rework($orderId, $designer, ['action' => 'decline']);
        $this->assertSame(404, $code);
        $this->assertSame(1560, (int)self::row($orderId)['rework_price_client']);
    }

    /** Симлінки cloud-links/cf на макети замовлення */
    private static function links(int $orderId): array
    {
        $out = [];
        exec('find ' . escapeshellarg(self::CF) . ' -type l -lname ' . escapeshellarg(Paths::root() . "/layouts/$orderId/*"), $out);
        return $out;
    }
}
