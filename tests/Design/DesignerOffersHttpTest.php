<?php

use Design\Customers;
use Design\Designers;
use Design\DesignerStatus;
use Design\Messages;
use Design\Offers;
use Design\OfferStatus;
use Design\OrderStatus;
use Design\Products;
use Design\Sql;
use PHPUnit\Framework\TestCase;

/**
 * Сторінки дизайнера по-справжньому через nginx dev.design.wolf.ua: стрічка, бриф з пропозицією / запрошенням, «Мої пропозиції».
 * Дизайнер — DESIGN_TEST_DESIGNER_ID (25), клієнт — DESIGN_TEST_CUSTOMER_ID (29). Рядок design_designers для 25 тест заводить
 * сам (схвалений лише на час тесту), а якщо він уже є — пропуск: справжні дані не чіпаємо. Замовлення — автоінкремент,
 * прибираються лише створені. customers — лише читання.
 * Сповіщення йдуть через php-fpm у dev api2 — їх мінімум: нова пропозиція (клієнту), «Беру» (клієнту), «Ні» (клієнту і куратору)
 *
 * @group http
 */
final class DesignerOffersHttpTest extends TestCase
{
    private const UA = 'DesignTest/1';
    private const DESIGNER_NAME = 'Тест Дизайнер стрічки';
    private const STRANGER = 999999731;   // чужий клієнт (рядка в customers немає)
    private const STRANGER_DESIGNER = 999999732;   // чужий дизайнер (рядка в design_designers немає)

    /** @var list<int> */
    private array $orders = [];
    private bool $ownsDesigner = false;

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

    /** 25 стає дизайнером зі статусом status на час тесту; вже є свій рядок — пропуск */
    private function designer(int $status = DesignerStatus::APPROVED): int
    {
        $id = self::designerId();
        if (Designers::get($id) !== null) {
            $this->markTestSkipped("у клієнта $id вже є профіль дизайнера — тест не чіпає справжні дані");
        }
        Sql::insert('design_designers', ['customer_id' => $id, 'status' => $status, 'name' => self::DESIGNER_NAME]);
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

    /** Тіло сторінки (GET, 200) */
    private function page(string $path, int $as): string
    {
        [$code, , $body] = self::fetch($path, $as);
        $this->assertSame(200, $code, "GET $path");
        return $body;
    }

    /** CSRF дизайнера з форми брифу (секрет php-fpm, не тестовий) */
    private static function csrf(int $as): string
    {
        [, , $html] = self::fetch('/brief/' . Products::visible()[0]['id'] . '/', $as);
        return preg_match('/name="csrf" value="([0-9a-f]{64})"/', $html, $m) ? $m[1] : '';
    }

    /** POST /feed/{id}: код і Location */
    private static function act(int $orderId, int $as, array $fields): array
    {
        [$code, $h, $body] = self::fetch("/feed/$orderId", $as, $fields);
        return [$code, preg_match('/^location:\s*(\S+)/mi', $h, $m) ? $m[1] : '', $body];
    }

    private function order(int $customerId, int $status = OrderStatus::COLLECTING, int $mode = 1, string $answer = 'Флаєр для СТО «Мотор Сервіс»'): int
    {
        $id = Sql::insert('design_orders', [
            'customer_id' => $customerId,
            'product_id' => Products::visible()[0]['id'],
            'mode' => $mode,
            'status' => $status,
            'answers' => json_encode([['q' => 'Що потрібно', 'a' => $answer], ['q' => 'Стиль', 'a' => 'Темний фон']], JSON_UNESCAPED_UNICODE),
        ]);
        Sql::exec('UPDATE design_orders SET published_at = NOW() WHERE id = ?', [$id]);
        $this->orders[] = $id;
        return $id;
    }

    private function offer(int $orderId, int $designerId, int $status = OfferStatus::SENT, int $price = 1000): int
    {
        $blank = $status === OfferStatus::INVITED;
        return Sql::insert('design_offers', [
            'order_id' => $orderId, 'designer_id' => $designerId, 'status' => $status,
            'text' => $blank ? null : 'Зроблю', 'price_designer' => $blank ? 0 : $price, 'markup_percent' => $blank ? 0 : 20,
            'price_client' => $blank ? 0 : (int)($price * 1.2), 'deadline' => $blank ? null : date('Y-m-d', strtotime('+3 day')),
        ]);
    }

    private static function offerRow(int $orderId, int $designerId): ?array
    {
        return Sql::one('SELECT * FROM design_offers WHERE order_id = ? AND designer_id = ?', [$orderId, $designerId]);
    }

    /** Фрагмент <main> сторінки — без шапки і підвалу */
    private static function main(string $html): string
    {
        $a = strpos($html, '<main>');
        return substr($html, $a, strpos($html, '</main>') - $a);
    }

    public function test_feed_requires_approved(): void
    {
        $client = self::clientId();
        $designer = self::designerId();
        if (Designers::get($designer) !== null) $this->markTestSkipped("у клієнта $designer вже є профіль дизайнера");
        $orderId = $this->order($client);
        $paths = ['/feed/', "/feed/$orderId/", '/my-offers/'];

        // Не дизайнер і дизайнер на розгляді — 404 і стара шапка
        foreach ([null, DesignerStatus::PENDING] as $status) {
            if ($status !== null) $this->designer($status);
            foreach ($paths as $p) {
                [$code, , $body] = self::fetch($p, $designer);
                $this->assertSame(404, $code, "GET $p, статус " . var_export($status, true));
            }
            $this->assertStringNotContainsString('Стрічка брифів', $body);
            $this->assertStringContainsString('href="/designers/"', $body);
            [$code] = self::act($orderId, $designer, ['action' => 'propose', 'csrf' => self::csrf($designer), 'text' => 'x', 'price' => '100', 'deadline' => date('Y-m-d')]);
            $this->assertSame(404, $code);
        }
        $this->assertNull(self::offerRow($orderId, $designer));

        // Схвалений — сторінки відкриваються, шапка дизайнера
        Sql::exec('UPDATE design_designers SET status = ? WHERE customer_id = ?', [DesignerStatus::APPROVED, $designer]);
        foreach ($paths as $p) $body = $this->page($p, $designer);
        foreach (['href="/feed/"', 'Стрічка брифів', 'href="/my-offers/"', 'Мої пропозиції', 'href="/orders/"', 'Мої замовлення', 'href="/designer/"', 'Мій профіль'] as $s) {
            $this->assertStringContainsString($s, $body);
        }
        $this->assertStringNotContainsString('Стати дизайнером', $body);

        // Клієнт 29 (не дизайнер) — шапка без змін
        $body = $this->page('/orders/', $client);
        $this->assertStringNotContainsString('href="/feed/"', $body);
        $this->assertStringContainsString('Стати дизайнером', $body);

        // Своє замовлення дизайнер бачить як клієнт; чуже закрите — 404
        $own = $this->order($designer);
        [$code, $h] = self::fetch("/feed/$own/", $designer);
        $this->assertSame(302, $code);
        $this->assertMatchesRegularExpression("#^location: /orders/$own/#mi", $h);
        $closed = $this->order(self::STRANGER, OrderStatus::IN_WORK);
        [$code] = self::fetch("/feed/$closed/", $designer);
        $this->assertSame(404, $code);
    }

    /** Бриф, якого немає в стрічці і де в 25 немає своєї пропозиції, — 404; пропонувати на нього напряму не можна */
    public function test_hidden_briefs_404(): void
    {
        $client = self::clientId();
        $designer = $this->designer();
        $draft = $this->order($client, OrderStatus::DRAFT);
        // Режим 2: запрошено іншого дизайнера
        $waiting = $this->order($client, OrderStatus::WAITING_DESIGNER, 2);
        $this->offer($waiting, self::STRANGER_DESIGNER, OfferStatus::INVITED);
        // Режим 2 після відмови іншого: збір, але не в стрічці
        $refused = $this->order($client, OrderStatus::COLLECTING, 2);
        $this->offer($refused, self::STRANGER_DESIGNER, OfferStatus::DECLINED);
        $csrf = self::csrf($designer);

        foreach (['чернетка' => $draft, 'режим 2, запрошено іншого' => $waiting, 'режим 2 після відмови' => $refused] as $what => $id) {
            [$code, , $body] = self::fetch("/feed/$id/", $designer);
            $this->assertSame(404, $code, "GET: $what");
            $this->assertStringNotContainsString('Флаєр для СТО', $body, "бриф не видно: $what");
            [$code] = self::fetch("/feed/$id/?form=propose", $designer);
            $this->assertSame(404, $code, "GET ?form=propose: $what");

            [$code, , $body] = self::act($id, $designer, ['action' => 'propose', 'csrf' => $csrf, 'text' => 'Зроблю', 'price' => '1000', 'deadline' => date('Y-m-d', strtotime('+3 day'))]);
            $this->assertSame(404, $code, "POST propose: $what");
            $this->assertStringNotContainsString('Флаєр для СТО', $body);
            $this->assertNull(self::offerRow($id, $designer), "пропозицію не створено: $what");
        }
        $this->assertStringNotContainsString("/feed/$refused/", $this->page('/feed/', $designer));
    }

    public function test_feed_counter_and_seen(): void
    {
        $client = self::clientId();
        $designer = $this->designer();
        Sql::exec('UPDATE design_designers SET feed_seen_at = NOW() - INTERVAL 1 HOUR WHERE customer_id = ?', [$designer]);
        $orderId = $this->order($client, answer: 'Барбершоп «Борода», Харків');

        // Кружечок у меню = нові брифи після feed_seen_at (на dev можуть бути й справжні)
        $n = Offers::feedNewCount($designer);
        $this->assertGreaterThanOrEqual(1, $n);
        $body = $this->page('/my-offers/', $designer);
        $shown = $n > 99 ? '99+' : (string)$n;   // так кружечок форматує шапка
        $this->assertMatchesRegularExpression('#href="/feed/" class="">Стрічка брифів <span class="nav-badge"[^>]*>' . preg_quote($shown, '#') . '</span>#u', $body);

        // Стрічка: картка з позначкою «нове», превʼю відповідей, кнопкою; відкриття скидає лічильник
        $body = $this->page('/feed/', $designer);
        $this->assertNotNull(Sql::value('SELECT feed_seen_at FROM design_designers WHERE customer_id = ? AND feed_seen_at > NOW() - INTERVAL 1 MINUTE', [$designer]));
        $this->assertStringNotContainsString('nav-badge', $body);
        $at = strpos($body, "href=\"/feed/$orderId/\"");
        $this->assertNotFalse($at);
        $card = substr($body, $at, 1500);
        $this->assertStringContainsString('нове', $card);
        $this->assertStringContainsString('Барбершоп «Борода», Харків', $card);
        $this->assertStringContainsString('Пропозицій: 0', $card);
        $this->assertStringContainsString("/feed/$orderId/?form=propose#offer", $card);

        $body = $this->page('/feed/', $designer);
        $at = strpos($body, "href=\"/feed/$orderId/\"");
        $this->assertStringNotContainsString('new-chip', substr($body, $at, 300), 'вдруге — вже не нове');
        $this->assertSame(0, Offers::feedNewCount($designer));
    }

    public function test_propose_update_withdraw_flow(): void
    {
        $client = self::clientId();
        $designer = $this->designer();
        $orderId = $this->order($client);
        $csrf = self::csrf($designer);
        $deadline = date('Y-m-d', strtotime('+5 day'));

        // Форма: k і крок округлення — у data-*, дата не раніше сьогодні
        $body = $this->page("/feed/$orderId/?form=propose", $designer);
        $this->assertStringContainsString('data-offer-form', $body);
        $this->assertStringContainsString('data-k="20" data-round="10"', $body);
        $this->assertStringContainsString('min="' . date('Y-m-d') . '"', $body);
        $this->assertStringContainsString('Сума вам на руки', $body);

        // Помилка — та сама форма з введеним, нічого не записано
        [$code, , $body] = self::act($orderId, $designer, ['action' => 'propose', 'csrf' => $csrf, 'text' => '  ', 'price' => '1 000', 'deadline' => $deadline]);
        $this->assertSame(422, $code);
        $this->assertStringContainsString('Опишіть, що зробите', $body);
        $this->assertStringContainsString('value="1 000"', $body);
        $this->assertStringContainsString('value="' . $deadline . '"', $body);
        $this->assertStringContainsString('Клієнт побачить 1' . "\u{00A0}" . '200' . "\u{00A0}" . 'грн', $body);
        [$code, , $body] = self::act($orderId, $designer, ['action' => 'propose', 'csrf' => '', 'text' => 'Зроблю <b>флаєр</b>', 'price' => '1000', 'deadline' => $deadline]);
        $this->assertSame(403, $code);
        $this->assertStringContainsString('Сесія застаріла', $body);
        $this->assertStringContainsString('Зроблю &lt;b&gt;флаєр&lt;/b&gt;', $body);
        $this->assertNull(self::offerRow($orderId, $designer));

        // Надіслати
        [$code, $loc] = self::act($orderId, $designer, ['action' => 'propose', 'csrf' => $csrf, 'text' => 'Зроблю <b>флаєр</b>', 'price' => '1 000', 'deadline' => $deadline]);
        $this->assertSame(302, $code);
        $this->assertSame("/feed/$orderId/?done=sent", $loc);
        $row = self::offerRow($orderId, $designer);
        $this->assertSame([OfferStatus::SENT, 1000, 1200], [(int)$row['status'], (int)$row['price_designer'], (int)$row['price_client']]);
        $body = $this->page($loc, $designer);
        $this->assertStringContainsString('Пропозицію надіслано', $body);
        $this->assertStringContainsString('Ви запропонували 1' . "\u{00A0}" . '000' . "\u{00A0}" . 'грн на руки', $body);
        $this->assertStringContainsString('Зроблю &lt;b&gt;флаєр&lt;/b&gt;', $body);
        $this->assertStringNotContainsString('<b>флаєр', $body);
        $this->assertStringContainsString('data-chat="' . $row['id'] . '"', $body);
        $this->assertStringContainsString('/assets/js/chat.js', $body);
        $this->assertNotNull(self::offerRow($orderId, $designer)['designer_seen_at'], 'перший рендер листування — seen');

        // Змінити: форма з поточними значеннями, нова ціна → системне повідомлення в листуванні
        $body = $this->page("/feed/$orderId/?form=edit", $designer);
        $this->assertStringContainsString('name="action" value="update"', $body);
        $this->assertStringContainsString('value="1000"', $body);
        $this->assertStringContainsString('data-was-designer="1000" data-was-client="1200"', $body);
        [$code, $loc] = self::act($orderId, $designer, ['action' => 'update', 'csrf' => $csrf, 'text' => 'Зроблю флаєр і зворот', 'price' => '1100', 'deadline' => $deadline]);
        $this->assertSame(302, $code);
        $this->assertSame("/feed/$orderId/?done=updated", $loc);
        $this->assertSame(1320, (int)self::offerRow($orderId, $designer)['price_client']);
        $body = $this->page($loc, $designer);
        $this->assertStringContainsString('<div class="chat-sys">Ціну змінено з 1' . "\u{00A0}" . '200 на 1' . "\u{00A0}" . '320 грн</div>', $body);

        // Відкликати зі стрічки: назад у стрічку з тостом; на сторінці брифу — «запропонувати знову» з попередніми полями
        [$code, $loc] = self::act($orderId, $designer, ['action' => 'withdraw', 'csrf' => $csrf, 'back' => 'feed']);
        $this->assertSame(302, $code);
        $this->assertSame('/feed/?done=withdrawn', $loc);
        $this->assertSame(OfferStatus::WITHDRAWN, (int)self::offerRow($orderId, $designer)['status']);
        $this->assertStringContainsString('Пропозицію відкликано', $this->page($loc, $designer));
        $body = $this->page("/feed/$orderId/", $designer);
        $this->assertStringContainsString('Запропонувати знову', $body);
        $body = $this->page("/feed/$orderId/?form=propose", $designer);
        $this->assertStringContainsString('Зроблю флаєр і зворот</textarea>', $body);

        // Повторне відкликання — помилка на сторінці, стан не змінено
        [$code, , $body] = self::act($orderId, $designer, ['action' => 'withdraw', 'csrf' => $csrf]);
        $this->assertSame(422, $code);
        $this->assertStringContainsString('Пропозицію вже не можна відкликати', $body);
    }

    public function test_invite_accept_and_decline(): void
    {
        $client = self::clientId();
        $designer = $this->designer();
        $csrf = self::csrf($designer);
        $deadline = date('Y-m-d', strtotime('+4 day'));

        // Запрошення: без ціни, кнопки «Беру» / «Відмовитися»
        $a = $this->order($client, OrderStatus::WAITING_DESIGNER, 2);
        $invited = $this->offer($a, $designer, OfferStatus::INVITED);
        // листування минулого раунду (рядок запрошено знову) — у запрошенні не показується
        Messages::add($invited, $client, Messages::ROLE_CLIENT, 'Повідомлення минулого раунду');
        $main = self::main($this->page("/feed/$a/", $designer));
        $this->assertStringContainsString("Вас обрали для замовлення №$a", $main);
        $this->assertStringContainsString('>Беру</a>', $main);
        $this->assertStringContainsString('>Відмовитися</a>', $main);
        $this->assertStringNotContainsString('data-chat=', $main, 'до «Беру» листування немає');
        $this->assertStringNotContainsString('Повідомлення минулого раунду', $main);
        $this->assertNull(self::offerRow($a, $designer)['designer_seen_at']);
        $this->assertStringNotContainsString('грн', $main, 'у запрошенні немає ціни');
        $this->assertStringContainsString('Флаєр для СТО «Мотор Сервіс»', $main);

        $main = self::main($this->page('/my-offers/', $designer));
        $at = strpos($main, "№$a ");
        $this->assertNotFalse($at);
        $this->assertStringNotContainsString('грн', substr($main, $at, strpos($main, '</div>', strpos($main, 'class="acts"', $at)) - $at));
        $this->assertStringContainsString('Вас обрали для замовлення — береш?', $main);

        // «Беру» — та сама форма пропозиції; пропозиція надіслана, замовлення — збір
        $body = $this->page("/feed/$a/?form=take", $designer);
        $this->assertStringContainsString('name="action" value="accept"', $body);
        [$code, $loc] = self::act($a, $designer, ['action' => 'accept', 'csrf' => $csrf, 'text' => 'Беру, зроблю', 'price' => '800', 'deadline' => $deadline]);
        $this->assertSame(302, $code);
        $this->assertSame("/feed/$a/?done=accepted", $loc);
        $row = self::offerRow($a, $designer);
        $this->assertSame([OfferStatus::SENT, 800, 960], [(int)$row['status'], (int)$row['price_designer'], (int)$row['price_client']]);
        $this->assertSame(OrderStatus::COLLECTING, (int)Sql::value('SELECT status FROM design_orders WHERE id = ?', [$a]));
        $this->assertStringContainsString('Ви запропонували 800' . "\u{00A0}" . 'грн на руки', $this->page($loc, $designer));

        // «Відмовитися» з причиною — причина стає повідомленням у листуванні
        $b = $this->order($client, OrderStatus::WAITING_DESIGNER, 2);
        $this->offer($b, $designer, OfferStatus::INVITED);
        $this->assertStringContainsString('name="reason"', $this->page("/feed/$b/?form=decline", $designer));
        [$code, $loc] = self::act($b, $designer, ['action' => 'decline', 'csrf' => $csrf, 'reason' => 'Не встигну до потрібної дати']);
        $this->assertSame(302, $code);
        $this->assertSame("/feed/$b/?done=declined", $loc);
        $row = self::offerRow($b, $designer);
        $this->assertSame(OfferStatus::DECLINED, (int)$row['status']);
        $this->assertSame(1, Messages::count((int)$row['id']));
        $this->assertSame(['event' => 'decline'], Messages::list((int)$row['id'])[0]['data']);
        $body = $this->page($loc, $designer);
        $this->assertStringContainsString('Ви відмовилися від цього замовлення', $body);
        $this->assertStringContainsString('Не встигну до потрібної дати', $body);

        // Відповідь на вже неактуальне запрошення — помилка, нічого не змінено
        [$code, , $body] = self::act($b, $designer, ['action' => 'accept', 'csrf' => $csrf, 'text' => 'Таки беру', 'price' => '800', 'deadline' => $deadline]);
        $this->assertSame(422, $code);
        $this->assertStringContainsString('Запрошення вже неактуальне', $body);
        $this->assertSame(OfferStatus::DECLINED, (int)self::offerRow($b, $designer)['status']);
    }

    public function test_client_shown_as_short_name(): void
    {
        $client = self::clientId();
        $designer = $this->designer();
        $c = Sql::one('SELECT first_name, last_name, phone, login FROM customers WHERE id = ?', [$client]);
        $short = Customers::header($client)['short'];

        $invite = $this->order($client, OrderStatus::WAITING_DESIGNER, 2);
        $this->offer($invite, $designer, OfferStatus::INVITED);
        $work = $this->order($client, OrderStatus::IN_WORK);
        $chosen = $this->offer($work, $designer, OfferStatus::CHOSEN);
        Sql::exec('UPDATE design_orders SET chosen_offer_id = ? WHERE id = ?', [$chosen, $work]);
        Messages::add($chosen, $client, Messages::ROLE_CLIENT, 'Добрий день');

        foreach (["/feed/$invite/", "/feed/$work/", '/my-offers/', '/my-offers/?tab=work'] as $p) {
            $main = self::main($this->page($p, $designer));
            $this->assertStringContainsString(htmlspecialchars($short), $main, $p);
            foreach (['last_name', 'phone', 'login'] as $f) {
                $v = trim((string)$c[$f]);
                if (mb_strlen($v) >= 3) $this->assertStringNotContainsString($v, $main, "$p: $f клієнта");
            }
        }
    }

    public function test_my_offers_tabs(): void
    {
        $client = self::clientId();
        $designer = $this->designer();
        $sent = $this->order($client);
        $this->offer($sent, $designer, OfferStatus::SENT, 1500);
        $other = $this->order($client, OrderStatus::IN_WORK);
        $this->offer($other, $designer, OfferStatus::OTHER_CHOSEN, 1800);
        $withdrawn = $this->order($client);
        $this->offer($withdrawn, $designer, OfferStatus::WITHDRAWN);
        $work = $this->order($client, OrderStatus::IN_WORK);
        $this->offer($work, $designer, OfferStatus::CHOSEN, 2000);

        $main = self::main($this->page('/my-offers/', $designer));
        $this->assertStringContainsString('Пропозиції <span class="n">3</span>', $main);
        $this->assertStringContainsString('В роботі <span class="n">1</span>', $main);
        $this->assertStringContainsString('Ви запропонували 1' . "\u{00A0}" . '500' . "\u{00A0}" . 'грн на руки', $main);
        $this->assertStringContainsString("/feed/$sent/?form=edit#offer", $main);
        $this->assertStringContainsString('Клієнт обрав іншого дизайнера', $main);
        $this->assertStringContainsString('Ви відкликали пропозицію', $main);
        $this->assertStringContainsString("/feed/$withdrawn/?form=propose#offer", $main);
        $this->assertStringNotContainsString("/feed/$work/", $main);

        $main = self::main($this->page('/my-offers/?tab=work', $designer));
        $this->assertStringContainsString("href=\"/feed/$work/\"", $main);
        $this->assertStringContainsString('2' . "\u{00A0}" . '000' . "\u{00A0}" . 'грн', $main);
        // «Чий хід» дизайнера (етап 4): листування порожнє, прев'ю ще немає
        $this->assertStringContainsString('Ваш хід: покажіть перше прев&#039;ю', $main);
        $this->assertStringNotContainsString("/feed/$sent/", $main);

        // Відкликати з «Мої пропозиції» — назад туди ж
        [$code, $loc] = self::act($sent, $designer, ['action' => 'withdraw', 'csrf' => self::csrf($designer), 'back' => 'my']);
        $this->assertSame(302, $code);
        $this->assertSame('/my-offers/?done=withdrawn', $loc);
        $this->assertSame(OfferStatus::WITHDRAWN, (int)self::offerRow($sent, $designer)['status']);
    }
}
