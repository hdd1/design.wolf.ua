<?php

use Design\DesignerStatus;
use Design\OfferStatus;
use Design\OrderStatus;
use Design\Products;
use Design\Sql;
use PHPUnit\Framework\TestCase;

/**
 * «Скасувати замовлення» і «Скарга» по-справжньому через nginx dev.design.wolf.ua (етап 5, Task 7): посилання й форми в
 * клієнта (збір пропозицій і робочий простір), POST /orders/{id}/cancel і /orders/{id}/complaint, відмови (порожньо, CSRF,
 * чуже, після купівлі), скасоване замовлення лише для читання в обох сторін (листування, пропозиція, «Купую»), «Скасовано:
 * {причина}», «Скаргу надіслано {дата}», сповіщення в n_smser після перенаправлення.
 * Клієнт — DESIGN_TEST_CUSTOMER_ID (29), дизайнер — DESIGN_TEST_DESIGNER_ID (25: тест робить його схваленим дизайнером; є свій
 * профіль — пропуск). Грошей тест не рухає: покупка — рядок design_purchases, куплений макет — рядок design_files без файла.
 * Сповіщення php-fpm кладе в dev api2 (n_smser) — тест чекає очікувані рядки своїх замовлень і прибирає їх.
 *
 * @group http
 */
final class CancelComplaintHttpTest extends TestCase
{
    private const UA = 'DesignTest/1';

    /** @var list<int> */
    private array $orders = [];
    /** @var array<int, int> замовлення → скільки рядків n_smser має з'явитися */
    private array $expected = [];
    private bool $ownsDesigner = false;
    /** @var array<int, string> */
    private static array $csrf = [];

    protected function tearDown(): void
    {
        $steps = [
            function (): void {
                // Сповіщення йде після перенаправлення (fastcgi_finish_request) — чекаємо всі очікувані рядки (до 5 с), тоді прибираємо
                foreach ($this->orders as $id) {
                    for ($i = 0; $i < 50 && self::smsCount($id) < ($this->expected[$id] ?? 0); $i++) usleep(100000);
                    Sql::exec('DELETE FROM n_smser WHERE ' . self::smsWhere(), self::smsArgs($id));
                }
                $this->expected = [];
            },
            function (): void {
                if (!$this->orders) return;
                $in = implode(',', array_map('intval', $this->orders));
                Sql::exec("DELETE FROM design_complaints WHERE order_id IN ($in)");
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

    // ---------- n_smser ----------

    /**
     * Рядки сповіщень цього замовлення: клієнт / дизайнер (customer_id), куратор і user 1 (user_id), лише Майстерні дизайну
     * (таблиця спільна з wolf.ua) і з точним початком тексту: дизайнеру про скасування, куратору про скасування, скарга
     */
    private static function smsWhere(): string
    {
        return '(customer_id IN (?, ?) OR user_id IN (?, 1)) AND (messenger_text LIKE ? OR messenger_text LIKE ? OR messenger_text LIKE ?)';
    }

    private static function smsArgs(int $orderId): array
    {
        return [self::clientId(), self::designerId(), self::curator(),
            "Майстерня дизайну: замовлення №$orderId скасовано клієнтом. %",
            "Майстерня дизайну: клієнт % скасував замовлення №$orderId. Причина: %",
            "Майстерня дизайну: скарга на замовлення №$orderId від клієнта %"];
    }

    private static function smsCount(int $orderId): int
    {
        return (int)Sql::value('SELECT COUNT(*) FROM n_smser WHERE ' . self::smsWhere(), self::smsArgs($orderId));
    }

    /** Чекає рядок n_smser отримувача (customer_id або user_id) з текстом $like (до 3 с) */
    private static function sms(string $col, int $to, string $like): bool
    {
        for ($i = 0; $i < 30; $i++) {
            if (Sql::value("SELECT id FROM n_smser WHERE $col = ? AND messenger_text LIKE ?", [$to, $like]) !== null) return true;
            usleep(100000);
        }
        return false;
    }

    // ---------- підготовка ----------

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

    private static function curator(): int
    {
        return (int)Sql::value('SELECT curator_id FROM customers WHERE id = ?', [self::clientId()]);
    }

    private function designer(): int
    {
        $id = self::designerId();
        if (Sql::value('SELECT customer_id FROM design_designers WHERE customer_id = ?', [$id]) !== null) {
            $this->markTestSkipped("у клієнта $id вже є профіль дизайнера — тест не чіпає справжні дані");
        }
        Sql::insert('design_designers', ['customer_id' => $id, 'status' => DesignerStatus::APPROVED, 'name' => 'Тест Дизайнер скасування']);
        $this->ownsDesigner = true;
        return $id;
    }

    private function order(int $status): int
    {
        $orderId = Sql::insert('design_orders', [
            'customer_id' => self::clientId(),
            'product_id' => Products::visible()[0]['id'],
            'mode' => 1,
            'status' => $status,
            'title' => 'Візитка для кавʼярні «Зерно»',
            'answers' => json_encode([['q' => 'Текст', 'a' => 'Тест скасування']], JSON_UNESCAPED_UNICODE),
        ]);
        $this->orders[] = $orderId;
        Sql::exec('UPDATE design_orders SET published_at = NOW() WHERE id = ?', [$orderId]);
        return $orderId;
    }

    private function offer(int $orderId, int $designer, int $status): int
    {
        $offerId = Sql::insert('design_offers', [
            'order_id' => $orderId, 'designer_id' => $designer, 'status' => $status,
            'text' => 'Зроблю', 'price_designer' => 1000, 'markup_percent' => 20, 'price_client' => 1200,
            'deadline' => date('Y-m-d', strtotime('+5 days')),
        ]);
        Sql::exec('UPDATE design_offers SET client_seen_at = NOW(), designer_seen_at = NOW() WHERE id = ?', [$offerId]);
        Sql::insert('design_messages', ['offer_id' => $offerId, 'author_role' => 2, 'author_id' => $designer, 'text' => 'Добрий день!']);
        return $offerId;
    }

    /** Замовлення з обраною пропозицією дизайнера → [orderId, offerId]; «Куплено» — з проведеною покупкою і купленим макетом */
    private function chosen(int $status, int $designer): array
    {
        $orderId = $this->order($status);
        $offerId = $this->offer($orderId, $designer, OfferStatus::CHOSEN);
        Sql::exec('UPDATE design_orders SET chosen_offer_id = ?, chosen_at = NOW() WHERE id = ?', [$offerId, $orderId]);
        if ($status === OrderStatus::PURCHASED || $status === OrderStatus::REWORK) {
            Sql::exec('UPDATE design_orders SET paid_client = 1200, paid_designer = 1000, purchased_at = NOW() WHERE id = ?', [$orderId]);
            $purchase = Sql::insert('design_purchases', [
                'order_id' => $orderId, 'offer_id' => $offerId, 'payer_id' => self::clientId(), 'price_client' => 1200, 'price_designer' => 1000,
                'markup_percent' => 20, 'amount_client' => 1200, 'amount_designer' => 1000, 'status' => 2,
            ]);
            Sql::insert('design_files', [
                'order_id' => $orderId, 'label' => 'Візитка', 'original_name' => 'Візитка.pdf', 'file' => 'test-none.pdf',
                'size' => 1000, 'md5' => md5('x'), 'status' => 1, 'purchase_id' => $purchase,
            ]);
        }
        return [$orderId, $offerId];
    }

    // ---------- HTTP ----------

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

    /** POST дії замовлення (cancel | complaint) → [код, Location, тіло]; 302 — рахуємо очікувані сповіщення */
    private function act(int $orderId, string $what, int $as, array $post, int $sms = 2): array
    {
        [$code, $h, $body] = self::fetch("/orders/$orderId/$what", $as, $post + ['csrf' => self::csrf($as)]);
        if ($code === 302) $this->expected[$orderId] = ($this->expected[$orderId] ?? 0) + $sms;
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

    private static function status(int $orderId): int
    {
        return (int)Sql::value('SELECT status FROM design_orders WHERE id = ?', [$orderId]);
    }

    private static function hasForm(string $html, int $orderId, string $what): bool
    {
        return (bool)preg_match('~<form[^>]*action="/orders/' . $orderId . '/' . $what . '"~', $html);
    }

    // ---------- тести ----------

    public function test_cancel_collecting_order(): void
    {
        $client = self::clientId();
        $designer = $this->designer();
        $orderId = $this->order(OrderStatus::COLLECTING);
        $offerId = $this->offer($orderId, $designer, OfferStatus::SENT);

        // Посилання є, форма — за ?form=cancel
        $c = self::page("/orders/$orderId/", $client);
        $this->assertStringContainsString('href="/orders/' . $orderId . '/?form=cancel#order-cancel"', $c);
        $this->assertFalse(self::hasForm($c, $orderId, 'cancel'));
        $f = self::page("/orders/$orderId/?form=cancel", $client);
        $this->assertTrue(self::hasForm($f, $orderId, 'cancel'));
        $this->assertMatchesRegularExpression('~<textarea[^>]*name="reason"[^>]*required~', $f);
        $this->assertStringContainsString('Скасувати замовлення №' . $orderId . '?', self::text($f));

        // Відмови: порожня причина (422, форма відкрита), застарілий CSRF (403), не клієнт (404)
        [$code, , $body] = $this->act($orderId, 'cancel', $client, ['reason' => "  \n "]);
        $this->assertSame(422, $code);
        $this->assertStringContainsString('Вкажіть причину', self::text($body));
        $this->assertTrue(self::hasForm($body, $orderId, 'cancel'));
        [$code, , $body] = self::fetch("/orders/$orderId/cancel", $client, ['reason' => 'Передумав', 'csrf' => str_repeat('0', 64)]);
        $this->assertSame(403, $code);
        $this->assertMatchesRegularExpression('~<textarea[^>]*name="reason"[^>]*>Передумав</textarea>~', $body, 'введене лишилось');
        [$code] = $this->act($orderId, 'cancel', $designer, ['reason' => 'Чуже']);
        $this->assertSame(404, $code);
        $this->assertSame(OrderStatus::COLLECTING, self::status($orderId));

        // Скасування → тост, «Скасовано: {причина}» (екрановано), посилання й форми немає
        [$code, $loc] = $this->act($orderId, 'cancel', $client, ['reason' => 'Знайшов <b>іншого</b>']);
        $this->assertSame(302, $code);
        $this->assertSame("/orders/$orderId/?done=cancelled", $loc);
        $this->assertSame(OrderStatus::CANCELLED, self::status($orderId));
        $c = self::page("/orders/$orderId/?done=cancelled", $client);
        $t = self::text($c);
        $this->assertStringContainsString('Скасовано: Знайшов <b>іншого</b>', $t);
        $this->assertStringContainsString('Знайшов &lt;b&gt;іншого&lt;/b&gt;', $c);
        $this->assertStringContainsString('Замовлення скасовано', $t);
        $this->assertStringNotContainsString('?form=cancel', $c);
        $this->assertFalse(self::hasForm(self::page("/orders/$orderId/?form=cancel", $client), $orderId, 'cancel'));
        $this->assertStringNotContainsString('data-bs-target="#choose-', $c, 'обрати вже не можна');
        // Повтор (друга вкладка) — 422 «Замовлення вже скасовано»
        [$code, , $body] = $this->act($orderId, 'cancel', $client, ['reason' => 'Ще раз']);
        $this->assertSame(422, $code);
        $this->assertStringContainsString('Замовлення вже скасовано', self::text($body));
        // Список замовлень
        $this->assertStringContainsString('Скасовано: Знайшов <b>іншого</b>', self::text(self::page('/orders/', $client)));

        // Дизайнер: пропозицію не змінити й не відкликати, листування закрите
        $d = self::page("/feed/$orderId/", $designer);
        $this->assertStringContainsString('Замовлення скасовано клієнтом', self::text($d));
        $this->assertStringNotContainsString('?form=edit', $d);
        $this->assertStringNotContainsString('value="withdraw"', $d);
        $this->assertStringNotContainsString('Знайшов', self::text($d), 'причину дизайнер не бачить');
        $this->assertStringNotContainsString('name="price"', self::page("/feed/$orderId/?form=edit", $designer));
        foreach (['update' => ['text' => 'Інакше', 'price' => '900', 'deadline' => date('Y-m-d', strtotime('+3 days'))], 'withdraw' => []] as $action => $post) {
            [$code, , $body] = self::fetch("/feed/$orderId", $designer, ['action' => $action, 'csrf' => self::csrf($designer)] + $post);
            $this->assertSame(422, $code, $action);
            $this->assertStringContainsString('Замовлення скасовано', self::text($body));
        }
        $this->assertSame(OfferStatus::SENT, (int)Sql::value('SELECT status FROM design_offers WHERE id = ?', [$offerId]));
        [$code, , $body] = self::fetch("/offers/$offerId/messages", $designer, ['text' => 'Привіт', 'csrf' => self::csrf($designer)]);
        $this->assertSame(403, $code);
        $this->assertSame('Листування закрите', json_decode($body, true)['error'] ?? null);
        [$code, , $body] = self::fetch("/offers/$offerId/messages", $client, ['text' => 'Привіт', 'csrf' => self::csrf($client)]);
        $this->assertSame(403, $code);
        $my = self::page('/my-offers/', $designer);
        $this->assertMatchesRegularExpression('~№' . $orderId . '\b(?:(?!offer-row).)*Замовлення скасовано клієнтом~su', $my);
        $this->assertStringNotContainsString("/feed/$orderId/?form=edit", $my);

        // Сповіщення: дизайнеру (без причини) і куратору (з причиною)
        $this->assertTrue(self::sms('customer_id', $designer, "%замовлення №$orderId скасовано клієнтом%"), 'сповіщення дизайнеру');
        if (self::curator() > 0) {
            $this->assertTrue(self::sms('user_id', self::curator(), "%скасував замовлення №$orderId. Причина: Знайшов <b>іншого</b>%"), 'сповіщення куратору');
        }
    }

    public function test_cancel_in_work_workspace(): void
    {
        $client = self::clientId();
        $designer = $this->designer();
        [$orderId, $offerId] = $this->chosen(OrderStatus::IN_WORK, $designer);

        $c = self::page("/orders/$orderId/", $client);
        $this->assertStringContainsString('data-ws-buy', $c, 'робочий простір');
        $this->assertStringContainsString('href="/orders/' . $orderId . '/?form=cancel#order-cancel"', $c);
        $this->assertStringNotContainsString('?form=complaint', $c, 'скарга — лише після купівлі');
        $f = self::page("/orders/$orderId/?form=cancel", $client);
        $this->assertTrue(self::hasForm($f, $orderId, 'cancel'));
        $this->assertStringContainsString('data-ws-buy', $f, 'форма — у робочому просторі');
        $this->assertStringNotContainsString('href="/orders/' . $orderId . '/?form=cancel#order-cancel"', $f, 'відкрита форма замість посилання');
        $this->assertStringNotContainsString('?form=cancel', self::page("/feed/$orderId/", $designer), 'дизайнер не скасовує');
        [$code] = $this->act($orderId, 'complaint', $client, ['text' => 'Погано']);
        $this->assertSame(422, $code);

        [$code] = $this->act($orderId, 'cancel', $client, ['reason' => 'Дизайнер мовчить']);
        $this->assertSame(302, $code);

        // Клієнт: не робочий простір, листування для читання, «Купую» немає
        $c = self::page("/orders/$orderId/", $client);
        $t = self::text($c);
        $this->assertStringContainsString('Скасовано: Дизайнер мовчить', $t);
        $this->assertStringNotContainsString('data-ws-buy', $c);
        $this->assertStringNotContainsString('workspace.js', $c);
        $this->assertStringContainsString('Листування закрите', $t);
        $this->assertStringContainsString('Добрий день!', $t, 'листування видно');
        [$code] = self::fetch("/orders/$orderId/buy", $client);
        $this->assertSame(404, $code, 'вікна оплати немає');
        [$code, , $body] = self::fetch("/orders/$orderId/buy", $client, ['amount' => '1200', 'csrf' => self::csrf($client)]);
        $this->assertGreaterThanOrEqual(400, $code);
        $this->assertSame(0, (int)Sql::value('SELECT COUNT(*) FROM design_purchases WHERE order_id = ?', [$orderId]));

        // Дизайнер: не робочий простір, без завантаження, «чий хід» в опитуванні
        $d = self::page("/feed/$orderId/", $designer);
        $this->assertStringContainsString('Замовлення скасовано клієнтом', self::text($d));
        $this->assertStringNotContainsString('data-ws-upload', $d);
        $this->assertStringNotContainsString('chunk-upload.js', $d);
        [$code, , $body] = self::fetch("/offers/$offerId/messages", $designer);
        $this->assertSame(200, $code);
        $poll = json_decode($body, true);
        $this->assertFalse($poll['write']);
        $this->assertSame('8', $poll['workspace']['stage']);
        $this->assertSame('Замовлення скасовано клієнтом', $poll['workspace']['turn']['text']);
        $my = self::page('/my-offers/?tab=work', $designer);
        $this->assertMatchesRegularExpression('~№' . $orderId . '\b(?:(?!order-row).)*Замовлення скасовано клієнтом~su', $my);

        $this->assertTrue(self::sms('customer_id', $designer, "%замовлення №$orderId скасовано клієнтом%"));
    }

    public function test_complaint_after_purchase(): void
    {
        $client = self::clientId();
        $designer = $this->designer();
        [$orderId] = $this->chosen(OrderStatus::PURCHASED, $designer);

        $c = self::page("/orders/$orderId/", $client);
        $this->assertStringContainsString('href="/orders/' . $orderId . '/?form=complaint#ws-complaint"', $c);
        $this->assertStringNotContainsString('?form=cancel', $c, 'після купівлі не скасувати');
        $this->assertStringNotContainsString('Скаргу надіслано', self::text($c));
        $this->assertStringNotContainsString('?form=complaint', self::page("/feed/$orderId/", $designer), 'скарга — лише клієнту');
        $f = self::page("/orders/$orderId/?form=complaint", $client);
        $this->assertTrue(self::hasForm($f, $orderId, 'complaint'));
        $this->assertMatchesRegularExpression('~<textarea[^>]*name="text"[^>]*required~', $f);

        [$code, , $body] = $this->act($orderId, 'cancel', $client, ['reason' => 'Пізно']);
        $this->assertSame(422, $code);
        $this->assertStringContainsString('Скасувати можна лише до першої купівлі', self::text($body));
        [$code, , $body] = $this->act($orderId, 'complaint', $client, ['text' => ' ']);
        $this->assertSame(422, $code);
        $this->assertStringContainsString('Опишіть скаргу', self::text($body));
        $this->assertTrue(self::hasForm($body, $orderId, 'complaint'));
        [$code, , $body] = self::fetch("/orders/$orderId/complaint", $client, ['text' => 'Помилка в телефоні', 'csrf' => str_repeat('0', 64)]);
        $this->assertSame(403, $code);
        $this->assertMatchesRegularExpression('~<textarea[^>]*name="text"[^>]*>Помилка в телефоні</textarea>~', $body);
        [$code] = $this->act($orderId, 'complaint', $designer, ['text' => 'Чуже']);
        $this->assertSame(404, $code);
        $this->assertSame(0, (int)Sql::value('SELECT COUNT(*) FROM design_complaints WHERE order_id = ?', [$orderId]));

        [$code, $loc] = $this->act($orderId, 'complaint', $client, ['text' => 'Помилка в номері телефону']);
        $this->assertSame(302, $code);
        $this->assertSame("/orders/$orderId/?done=complaint", $loc);
        $t = self::text(self::page("/orders/$orderId/?done=complaint", $client));
        $this->assertStringContainsString('Скаргу надіслано ' . \Design\View::date(date('Y-m-d')), $t);
        $this->assertSame(OrderStatus::PURCHASED, self::status($orderId));
        $this->assertSame(1, (int)Sql::value('SELECT COUNT(*) FROM design_complaints WHERE order_id = ?', [$orderId]));

        if (self::curator() > 0) {
            $this->assertTrue(self::sms('user_id', self::curator(), "%скарга на замовлення №$orderId від клієнта %Помилка в номері телефону%"), 'куратору');
        }
        $this->assertTrue(self::sms('user_id', 1, "%скарга на замовлення №$orderId від клієнта %Помилка в номері телефону%"), 'user 1');
    }
}
