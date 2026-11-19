<?php

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
 * Сторінки клієнта по-справжньому через nginx dev.design.wolf.ua: пропозиції на сторінці замовлення, вибір, режим «Одразу обрати».
 * Клієнт — DESIGN_TEST_CUSTOMER_ID (29), дизайнер — DESIGN_TEST_DESIGNER_ID (25). Рядок design_designers для 25 тест заводить
 * сам (схвалений лише на час тесту), а якщо він уже є — тести, яким потрібен 25, пропускаються. «Інші дизайнери» — id без
 * рядка в design_designers (для клієнта — недоступні). Замовлення — автоінкремент, прибираються лише створені. customers — лише читання.
 * Сповіщення йдуть через php-fpm у dev api2 — їх мінімум: вибір (дизайнеру 25) і запрошення (дизайнеру 25)
 *
 * @group http
 */
final class ClientOffersHttpTest extends TestCase
{
    private const UA = 'DesignTest/1';
    private const DESIGNER_NAME = 'Тест Дизайнер <b>клієнта</b>';
    private const STRANGER = 999999751;   // чужий клієнт (рядка в customers немає)
    private const GHOST = 999999752;      // дизайнер без рядка design_designers — недоступний
    private const OTHER = 999999753;      // схвалений дизайнер без рядка в customers (тест заводить і прибирає сам)
    private const OTHER_NAME = 'Олена <i>Дизайн</i>';

    /** @var list<int> */
    private array $orders = [];
    private bool $ownsDesigner = false;
    private bool $ownsOther = false;

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
        if ($this->ownsOther) {
            Sql::exec('DELETE FROM design_designers WHERE customer_id = ?', [self::OTHER]);
            $this->ownsOther = false;
        }
    }

    /** Другий схвалений дизайнер (фіктивний id; увійти ним не можна) */
    private function other(): int
    {
        Sql::exec('DELETE FROM design_designers WHERE customer_id = ?', [self::OTHER]);
        Sql::insert('design_designers', ['customer_id' => self::OTHER, 'status' => DesignerStatus::APPROVED, 'name' => self::OTHER_NAME]);
        $this->ownsOther = true;
        return self::OTHER;
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

    /** 25 — схвалений дизайнер на час тесту; вже є свій рядок — пропуск */
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

    /** Фрагмент <main> сторінки (GET, 200) — без шапки і підвалу */
    private function page(string $path, int $as): string
    {
        [$code, , $body] = self::fetch($path, $as);
        $this->assertSame(200, $code, "GET $path");
        return self::main($body);
    }

    private static function main(string $html): string
    {
        $a = strpos($html, '<main>');
        return $a === false ? $html : substr($html, $a, strpos($html, '</main>') - $a);
    }

    /** CSRF клієнта з форми брифу (секрет php-fpm, не тестовий) */
    private static function csrf(int $as): string
    {
        [, , $html] = self::fetch('/brief/' . Products::visible()[0]['id'] . '/', $as);
        return preg_match('/name="csrf" value="([0-9a-f]{64})"/', $html, $m) ? $m[1] : '';
    }

    /** POST /orders/{id}: код, Location, тіло */
    private static function act(int $orderId, int $as, array $fields): array
    {
        [$code, $h, $body] = self::fetch("/orders/$orderId", $as, $fields);
        return [$code, preg_match('/^location:\s*(\S+)/mi', $h, $m) ? $m[1] : '', $body];
    }

    private function order(int $customerId, int $status = OrderStatus::COLLECTING, int $mode = 1): int
    {
        $id = Sql::insert('design_orders', [
            'customer_id' => $customerId,
            'product_id' => Products::visible()[0]['id'],
            'mode' => $mode,
            'status' => $status,
            'title' => 'Флаєр для СТО «Мотор Сервіс»',
            'answers' => json_encode([['q' => 'Що потрібно', 'a' => 'Флаєр А5'], ['q' => 'Стиль', 'a' => 'Темний фон']], JSON_UNESCAPED_UNICODE),
        ]);
        if ($status !== OrderStatus::DRAFT) Sql::exec('UPDATE design_orders SET published_at = NOW() WHERE id = ?', [$id]);
        $this->orders[] = $id;
        return $id;
    }

    /** Пропозиція з ціною клієнта price·1.2 */
    private function offer(int $orderId, int $designerId, int $status = OfferStatus::SENT, int $price = 1000, string $text = 'Зроблю флаєр, що читається з 3 метрів'): int
    {
        return Sql::insert('design_offers', [
            'order_id' => $orderId, 'designer_id' => $designerId, 'status' => $status,
            'text' => $text, 'price_designer' => $price, 'markup_percent' => 20,
            'price_client' => (int)($price * 1.2), 'deadline' => date('Y-m-d', strtotime('+3 day')),
        ]);
    }

    private static function orderRow(int $id): array
    {
        return Sql::one('SELECT status, mode, chosen_offer_id FROM design_orders WHERE id = ?', [$id]);
    }

    public function test_order_shows_offers_without_rating(): void
    {
        $client = self::clientId();
        $designer = $this->designer();
        $orderId = $this->order($client);
        $mine = $this->offer($orderId, $designer, OfferStatus::SENT, 1000, 'Текст <i>пропозиції</i>');
        $gone = $this->offer($orderId, self::GHOST + 1, OfferStatus::WITHDRAWN, 700);
        Messages::add($mine, $designer, Messages::ROLE_DESIGNER, 'Добрий день! Мапу малюю сам?');
        Messages::system($mine, 'Ціну змінено з 1 080 на 1 200 грн', ['event' => 'price', 'from' => 1080, 'to' => 1200]);

        $body = $this->page("/orders/$orderId/", $client);
        $this->assertStringContainsString('Ваш хід: оберіть дизайнера', $body);
        $this->assertStringContainsString('Пропозиції · 2', $body);
        $this->assertStringContainsString('Тест Дизайнер &lt;b&gt;клієнта&lt;/b&gt;', $body);
        $this->assertStringNotContainsString('<b>клієнта</b>', $body);
        $this->assertStringContainsString('Текст &lt;i&gt;пропозиції&lt;/i&gt;', $body);
        $this->assertStringContainsString("1\u{00A0}200\u{00A0}грн", $body);
        $this->assertStringContainsString('Ціну змінено', $body);
        $this->assertStringContainsString("href=\"/designers/$designer/\"", $body);
        $this->assertStringContainsString('Портфоліо', $body);
        $this->assertStringContainsString("Листування · 1", $body);
        $this->assertStringContainsString('Дизайнер відкликав пропозицію', $body);
        // Підтвердження вибору з ціною, яку бачив клієнт
        $this->assertMatchesRegularExpression('/name="offer_id" value="' . $mine . '"/', $body);
        $this->assertMatchesRegularExpression('/name="expected_price" value="1200"/', $body);
        $this->assertStringNotContainsString('name="offer_id" value="' . $gone . '"', $body);
        // Без рейтингу і кількості замовлень
        $this->assertStringNotContainsString('fa-star', $body);
        $this->assertDoesNotMatchRegularExpression('/\d+\s+замовлен/u', $body);
        // Листування першої пропозиції — у боковій панелі, клієнт «в чаті»
        $this->assertStringContainsString('data-chat="' . $mine . '"', $body);
        $this->assertStringContainsString('data-write="1"', $body);
        $this->assertStringContainsString('До вибору — лише текстові повідомлення', $body);
        $this->assertStringContainsString('Мапу малюю сам?', $body);
        $this->assertNotNull(Sql::value('SELECT client_seen_at FROM design_offers WHERE id = ?', [$mine]));

        // «Мої замовлення»: чий хід з числом пропозицій
        $list = $this->page('/orders/', $client);
        $this->assertStringContainsString('Ваш хід: оберіть дизайнера · 1 пропозиція', $list);
    }

    public function test_empty_offers_page(): void
    {
        $client = self::clientId();
        $orderId = $this->order($client);
        $body = $this->page("/orders/$orderId/", $client);
        $this->assertStringContainsString('Чекаємо пропозицій від дизайнерів', $body);
        $this->assertStringContainsString('Пропозицій поки немає', $body);
        $this->assertStringNotContainsString('data-chat=', $body);
    }

    public function test_choose_flow(): void
    {
        $client = self::clientId();
        $designer = $this->designer();
        $orderId = $this->order($client);
        $mine = $this->offer($orderId, $designer, OfferStatus::SENT, 1000);
        $other = $this->offer($orderId, self::GHOST, OfferStatus::SENT, 900);
        $csrf = self::csrf($client);

        // Без CSRF — 403, нічого не змінилося
        [$code] = self::act($orderId, $client, ['action' => 'choose', 'offer_id' => $mine, 'expected_price' => 1200, 'csrf' => 'x']);
        $this->assertSame(403, $code);
        $this->assertSame(OrderStatus::COLLECTING, (int)self::orderRow($orderId)['status']);

        // Дизайнер тим часом змінив ціну — сторінка знову з новою ціною і поясненням
        [$code, , $body] = self::act($orderId, $client, ['action' => 'choose', 'offer_id' => $mine, 'expected_price' => 1100, 'csrf' => $csrf]);
        $this->assertSame(422, $code);
        $this->assertStringContainsString('Ціну змінено — перегляньте пропозицію', $body);
        $this->assertStringContainsString('name="expected_price" value="1200"', $body);
        $this->assertSame(OrderStatus::COLLECTING, (int)self::orderRow($orderId)['status']);

        [$code, $loc] = self::act($orderId, $client, ['action' => 'choose', 'offer_id' => $mine, 'expected_price' => 1200, 'csrf' => $csrf]);
        $this->assertSame(302, $code);
        $this->assertSame("/orders/$orderId/?done=chosen", $loc);
        $row = self::orderRow($orderId);
        $this->assertSame(OrderStatus::IN_WORK, (int)$row['status']);
        $this->assertSame($mine, (int)$row['chosen_offer_id']);
        $this->assertSame(OfferStatus::OTHER_CHOSEN, (int)Sql::value('SELECT status FROM design_offers WHERE id = ?', [$other]));

        // Після вибору: «В роботі», обраний дизайнер і листування з ним; «Обрати» більше немає
        [$code, , $html] = self::fetch("/orders/$orderId/?done=chosen", $client);
        $this->assertSame(200, $code);
        $this->assertStringContainsString('Дизайнера обрано', $html);
        $body = self::main($html);
        $this->assertStringContainsString('В роботі', $body);
        $this->assertStringContainsString('Обраний дизайнер', $body);
        $this->assertStringContainsString('data-chat="' . $mine . '"', $body);
        $this->assertStringContainsString('data-write="1"', $body);
        $this->assertStringNotContainsString('name="offer_id"', $body);
        $this->assertStringContainsString('Вже обрано іншого дизайнера', $body);

        // Листування з іншим — лише читання
        $body = $this->page("/orders/$orderId/?chat=$other", $client);
        $this->assertStringContainsString('data-chat="' . $other . '"', $body);
        $this->assertStringContainsString('data-write="0"', $body);

        // Повторний вибір — помилка, нічого не змінюється
        [$code, , $body] = self::act($orderId, $client, ['action' => 'choose', 'offer_id' => $other, 'expected_price' => 1080, 'csrf' => $csrf]);
        $this->assertSame(422, $code);
        $this->assertStringContainsString('Дизайнера вже обрано', $body);
        $this->assertSame($mine, (int)self::orderRow($orderId)['chosen_offer_id']);
    }

    public function test_mode2_pick_invite_wait_decline_collect(): void
    {
        $client = self::clientId();
        $designer = $this->designer();
        $orderId = $this->order($client, OrderStatus::DRAFT);
        $csrf = self::csrf($client);

        // Крок 3: «Одразу обрати дизайнера» веде на вибір
        $body = $this->page("/orders/$orderId/mode/", $client);
        $this->assertStringContainsString("href=\"/orders/$orderId/pick/\"", $body);
        $this->assertStringNotContainsString('Незабаром', $body);

        $body = $this->page("/orders/$orderId/pick/", $client);
        $this->assertStringContainsString('Тест Дизайнер &lt;b&gt;клієнта&lt;/b&gt;', $body);
        $this->assertStringContainsString('name="designer_id" value="' . $designer . '"', $body);
        $this->assertStringContainsString('Опублікувати і запросити', $body);
        // Себе клієнт у списку не бачить (якщо він теж дизайнер) — тут 29 не дизайнер, а 25 — так
        $own = $this->page('/orders/' . $this->order($designer, OrderStatus::DRAFT) . '/pick/', $designer);
        $this->assertStringNotContainsString('name="designer_id" value="' . $designer . '"', $own);

        // Запрошення: чернетка публікується в режимі 2
        [$code, $loc] = self::act($orderId, $client, ['action' => 'invite', 'designer_id' => $designer, 'csrf' => $csrf]);
        $this->assertSame(302, $code);
        $this->assertSame("/orders/$orderId/?done=invited", $loc);
        $row = self::orderRow($orderId);
        $this->assertSame([OrderStatus::WAITING_DESIGNER, 2], [(int)$row['status'], (int)$row['mode']]);

        $body = $this->page("/orders/$orderId/", $client);
        $this->assertStringContainsString('Чекаємо відповіді дизайнера', $body);
        $this->assertStringContainsString('Тест Дизайнер &lt;b&gt;клієнта&lt;/b&gt;', $body);
        // Поки чекаємо — вибір іншого закритий
        [$code, $loc] = self::fetch("/orders/$orderId/pick/", $client);
        $this->assertSame(302, $code);

        // Дизайнер відмовився з причиною (бібліотекою, без сповіщень)
        $offerId = (int)Sql::value('SELECT id FROM design_offers WHERE order_id = ? AND designer_id = ?', [$orderId, $designer]);
        Offers::decline($offerId, $designer, 'На жаль, до 25 листопада <s>зайнятий</s>');
        $body = $this->page("/orders/$orderId/", $client);
        $this->assertStringContainsString('Ваш хід: оберіть, як продовжити', $body);
        $this->assertStringContainsString('Дизайнер відмовився', $body);
        $this->assertStringContainsString('На жаль, до 25 листопада &lt;s&gt;зайнятий&lt;/s&gt;', $body);
        $this->assertStringContainsString("href=\"/orders/$orderId/pick/\"", $body);
        $this->assertStringContainsString('value="collect"', $body);
        $this->assertStringContainsString('оберіть, як продовжити', $this->page('/orders/', $client));
        $this->assertStringContainsString('Запросити дизайнера', $this->page("/orders/$orderId/pick/", $client));

        // Знову запросили того самого (пізніше) і він відмовився без причини — стара причина не показується
        Sql::exec('UPDATE design_messages SET created = created - INTERVAL 1 HOUR WHERE offer_id = ?', [$offerId]);
        Offers::invite($orderId, $client, $designer);
        Offers::decline($offerId, $designer, '');
        $body = $this->page("/orders/$orderId/", $client);
        $this->assertStringContainsString('Ваш хід: оберіть, як продовжити', $body);
        $this->assertStringContainsString('Дизайнер не вказав причину', $body);
        $this->assertStringNotContainsString('зайнятий', $body);

        // «Збирати пропозиції»: режим 1, повтор нічого не ламає
        foreach ([1, 2] as $_) {
            [$code, $loc] = self::act($orderId, $client, ['action' => 'collect', 'csrf' => $csrf]);
            $this->assertSame(302, $code);
            $this->assertSame("/orders/$orderId/?done=collect", $loc);
        }
        $row = self::orderRow($orderId);
        $this->assertSame([OrderStatus::COLLECTING, 1], [(int)$row['status'], (int)$row['mode']]);
        [$code, $h] = self::fetch("/orders/$orderId/pick/", $client);
        $this->assertSame(302, $code);
        $this->assertMatchesRegularExpression("#^location: /orders/$orderId/#mi", $h);
        // Відмова з режиму 2 у списку пропозицій не показується
        $this->assertStringContainsString('Пропозицій поки немає', $this->page("/orders/$orderId/", $client));
    }

    /** Режим 2 до кінця: дизайнер сказав «Беру» з ціною → клієнт обирає → «В роботі» */
    public function test_mode2_accept_then_choose_in_work(): void
    {
        $client = self::clientId();
        $designer = $this->designer();
        $orderId = $this->order($client, OrderStatus::WAITING_DESIGNER, 2);
        $offerId = $this->offer($orderId, $designer, OfferStatus::INVITED);
        Sql::exec('UPDATE design_offers SET text = NULL, price_designer = 0, markup_percent = 0, price_client = 0, deadline = NULL WHERE id = ?', [$offerId]);
        $csrf = self::csrf($client);

        // «Беру» (бібліотекою, без сповіщень): ціна клієнта — за поточною націнкою
        Offers::accept($offerId, $designer, 'Беру, зроблю', 800, date('Y-m-d', strtotime('+4 day')));
        $p = (int)Sql::value('SELECT price_client FROM design_offers WHERE id = ?', [$offerId]);
        $body = $this->page("/orders/$orderId/", $client);
        $this->assertStringContainsString('Дизайнер назвав ціну і термін — підтвердіть вибір', $body);
        $this->assertMatchesRegularExpression('/name="offer_id" value="' . $offerId . '"/', $body);
        $this->assertStringContainsString('name="expected_price" value="' . $p . '"', $body);

        [$code, $loc] = self::act($orderId, $client, ['action' => 'choose', 'offer_id' => $offerId, 'expected_price' => $p, 'csrf' => $csrf]);
        $this->assertSame(302, $code);
        $this->assertSame("/orders/$orderId/?done=chosen", $loc);
        $row = self::orderRow($orderId);
        $this->assertSame([OrderStatus::IN_WORK, 2, $offerId], [(int)$row['status'], (int)$row['mode'], (int)$row['chosen_offer_id']]);
        $this->assertSame(OfferStatus::CHOSEN, (int)Sql::value('SELECT status FROM design_offers WHERE id = ?', [$offerId]));

        $body = $this->page("/orders/$orderId/", $client);
        $this->assertStringContainsString('В роботі', $body);
        $this->assertStringContainsString('Обраний дизайнер', $body);
        $this->assertStringContainsString('data-write="1"', $body);
    }

    /**
     * Рішення власника 2026-11-19: дизайнер сказав «Беру» з ціною — у клієнта, крім «Обрати за N грн», «Запросити іншого
     * дизайнера» (пропозицію попереднього буде закрито) і «Збирати пропозиції». Тут — «Запросити іншого»: попередній
     * (фіктивний, без сповіщення) → «обрано іншого», запрошення — дизайнеру 25
     */
    public function test_mode2_offer_invite_other(): void
    {
        $client = self::clientId();
        $designer = $this->designer();
        $other = $this->other();
        $orderId = $this->order($client, OrderStatus::COLLECTING, 2);
        $offerId = $this->offer($orderId, $other, OfferStatus::SENT, 700);
        $csrf = self::csrf($client);

        $body = $this->page("/orders/$orderId/", $client);
        $this->assertStringContainsString('Дизайнер назвав ціну і термін — підтвердіть вибір', $body);
        $this->assertStringContainsString('data-bs-target="#choose-' . $offerId . '"', $body);
        $this->assertStringContainsString('Запросити іншого дизайнера', $body);
        $this->assertStringContainsString("href=\"/orders/$orderId/pick/\"", $body);
        $this->assertStringContainsString('Пропозицію Олена &lt;i&gt;Дизайн&lt;/i&gt; буде закрито', $body);
        $this->assertStringContainsString('value="collect"', $body);
        $this->assertStringContainsString('Збирати пропозиції', $body);
        $this->assertStringNotContainsString('<i>Дизайн</i>', $body);

        // Вибір іншого: без того, хто вже назвав ціну; попередження і підтвердження перед запрошенням
        $body = $this->page("/orders/$orderId/pick/", $client);
        $this->assertStringContainsString('name="designer_id" value="' . $designer . '"', $body);
        $this->assertStringNotContainsString('name="designer_id" value="' . $other . '"', $body);
        $this->assertStringContainsString('Пропозицію Олена &lt;i&gt;Дизайн&lt;/i&gt; буде закрито', $body);
        $this->assertMatchesRegularExpression('/<form id="pick-form"[^>]* data-confirm="Пропозицію Олена &lt;i&gt;Дизайн&lt;\/i&gt; буде закрито[^"]*"/', $body);
        $this->assertStringContainsString('/assets/js/offer-form.js', self::fetch("/orders/$orderId/pick/", $client)[2]);

        [$code, $loc] = self::act($orderId, $client, ['action' => 'invite', 'designer_id' => $designer, 'csrf' => $csrf]);
        $this->assertSame(302, $code);
        $this->assertSame("/orders/$orderId/?done=invited", $loc);
        $row = self::orderRow($orderId);
        $this->assertSame([OrderStatus::WAITING_DESIGNER, 2], [(int)$row['status'], (int)$row['mode']]);
        $this->assertSame(OfferStatus::OTHER_CHOSEN, (int)Sql::value('SELECT status FROM design_offers WHERE id = ?', [$offerId]));
        $this->assertSame(OfferStatus::INVITED,
            (int)Sql::value('SELECT status FROM design_offers WHERE order_id = ? AND designer_id = ?', [$orderId, $designer]));

        $body = $this->page("/orders/$orderId/", $client);
        $this->assertStringContainsString('Чекаємо відповіді дизайнера', $body);
        $this->assertStringContainsString('Тест Дизайнер &lt;b&gt;клієнта&lt;/b&gt;', $body);
        $this->assertStringNotContainsString('Запросити іншого дизайнера', $body);
    }

    /**
     * Рішення власника 2026-11-19, «Збирати пропозиції» після ціни: пропозиція 25 лишається і обирається вже в режимі 1.
     * І дизайнер, чию пропозицію закрило «Запросити іншого», бачить «обрано іншого» без можливості писати
     */
    public function test_mode2_offer_collect_then_choose_and_closed_designer_view(): void
    {
        $client = self::clientId();
        $designer = $this->designer();
        $other = $this->other();
        $orderId = $this->order($client, OrderStatus::COLLECTING, 2);
        $offerId = $this->offer($orderId, $designer, OfferStatus::SENT, 800);
        $csrf = self::csrf($client);

        $this->assertStringContainsString('Пропозицію Тест Дизайнер &lt;b&gt;клієнта&lt;/b&gt; буде закрито', $this->page("/orders/$orderId/", $client));

        foreach ([1, 2] as $_) {
            [$code, $loc] = self::act($orderId, $client, ['action' => 'collect', 'csrf' => $csrf]);
            $this->assertSame(302, $code);
            $this->assertSame("/orders/$orderId/?done=collect", $loc);
        }
        $row = self::orderRow($orderId);
        $this->assertSame([OrderStatus::COLLECTING, 1], [(int)$row['status'], (int)$row['mode']]);
        $this->assertSame(OfferStatus::SENT, (int)Sql::value('SELECT status FROM design_offers WHERE id = ?', [$offerId]));

        $body = $this->page("/orders/$orderId/", $client);
        $this->assertStringContainsString('data-bs-target="#choose-' . $offerId . '"', $body);
        $this->assertStringNotContainsString('Запросити іншого дизайнера', $body);
        $this->assertStringNotContainsString('value="collect"', $body);
        // вибір іншого в режимі 1 закритий
        $this->assertSame(302, self::fetch("/orders/$orderId/pick/", $client)[0]);

        [$code, $loc] = self::act($orderId, $client, ['action' => 'choose', 'offer_id' => $offerId, 'expected_price' => 960, 'csrf' => $csrf]);
        $this->assertSame(302, $code);
        $this->assertSame("/orders/$orderId/?done=chosen", $loc);
        $row = self::orderRow($orderId);
        $this->assertSame([OrderStatus::IN_WORK, 1, $offerId], [(int)$row['status'], (int)$row['mode'], (int)$row['chosen_offer_id']]);

        // 25 сказав «Беру», а клієнт запросив іншого (бібліотекою — сповіщень немає): 25 бачить «обрано іншого», писати не може
        $closed = $this->order($client, OrderStatus::COLLECTING, 2);
        $mine = $this->offer($closed, $designer, OfferStatus::SENT, 800);
        Messages::add($mine, $designer, Messages::ROLE_DESIGNER, 'Уточню формат');
        Offers::invite($closed, $client, $other);
        $this->assertSame(OfferStatus::OTHER_CHOSEN, (int)Sql::value('SELECT status FROM design_offers WHERE id = ?', [$mine]));
        $body = $this->page("/feed/$closed/", $designer);
        $this->assertStringContainsString('Клієнт обрав іншого дизайнера', $body);
        $this->assertStringContainsString('data-chat="' . $mine . '"', $body);
        $this->assertStringContainsString('data-write="0"', $body);
        $this->assertStringContainsString('Вже обрано іншого дизайнера', $this->page('/feed/', $designer));
    }

    /** Вибір пропозиції з іншого свого замовлення — відмова, нічого не змінилося */
    public function test_choose_offer_from_other_own_order_refused(): void
    {
        $client = self::clientId();
        $x = $this->order($client);
        $y = $this->order($client);
        $onY = $this->offer($y, self::GHOST, OfferStatus::SENT, 900);
        $csrf = self::csrf($client);

        [$code, , $body] = self::act($x, $client, ['action' => 'choose', 'offer_id' => $onY, 'expected_price' => 1080, 'csrf' => $csrf]);
        $this->assertSame(422, $code);
        $this->assertStringContainsString('Пропозиція недоступна', $body);
        foreach ([$x, $y] as $id) {
            $row = self::orderRow($id);
            $this->assertSame(OrderStatus::COLLECTING, (int)$row['status']);
            $this->assertNull($row['chosen_offer_id']);
        }
        $this->assertSame(OfferStatus::SENT, (int)Sql::value('SELECT status FROM design_offers WHERE id = ?', [$onY]));
    }

    public function test_unavailable_designer_not_choosable(): void
    {
        $client = self::clientId();
        $orderId = $this->order($client);
        $ghost = $this->offer($orderId, self::GHOST, OfferStatus::SENT, 900);
        $csrf = self::csrf($client);

        $body = $this->page("/orders/$orderId/", $client);
        $this->assertStringContainsString('Дизайнер недоступний', $body);
        $this->assertStringNotContainsString('name="offer_id" value="' . $ghost . '"', $body);
        $this->assertStringNotContainsString('href="/designers/' . self::GHOST . '/"', $body);
        // писати недоступному нікуди: без «Написати»
        $this->assertStringNotContainsString("?chat=$ghost", $body);
        $this->assertStringNotContainsString('Написати', $body);
        // а наявне листування — лише прочитати
        Messages::add($ghost, self::GHOST, Messages::ROLE_DESIGNER, 'Старе повідомлення');
        $body = $this->page("/orders/$orderId/", $client);
        $this->assertStringContainsString("?chat=$ghost#chat\"><i class=\"fal fa-comment\"></i> Листування · 1", $body);
        $body = $this->page("/orders/$orderId/?chat=$ghost", $client);
        $this->assertStringContainsString('data-chat="' . $ghost . '"', $body);
        $this->assertStringContainsString('data-write="0"', $body);

        [$code, , $body] = self::act($orderId, $client, ['action' => 'choose', 'offer_id' => $ghost, 'expected_price' => 1080, 'csrf' => $csrf]);
        $this->assertSame(422, $code);
        $this->assertStringContainsString('Дизайнер недоступний', $body);
        $this->assertSame(OrderStatus::COLLECTING, (int)self::orderRow($orderId)['status']);

        // Запросити недоступного не можна: сторінка вибору з помилкою, чернетка лишається чернеткою
        $draft = $this->order($client, OrderStatus::DRAFT);
        [$code, , $body] = self::act($draft, $client, ['action' => 'invite', 'designer_id' => self::GHOST, 'csrf' => $csrf]);
        $this->assertSame(422, $code);
        $this->assertStringContainsString('Дизайнер недоступний', $body);
        $this->assertSame(OrderStatus::DRAFT, (int)self::orderRow($draft)['status']);
    }

    public function test_foreign_order_404(): void
    {
        $client = self::clientId();
        $foreign = $this->order(self::STRANGER);
        $offer = $this->offer($foreign, self::GHOST);
        $draft = $this->order(self::STRANGER, OrderStatus::DRAFT);
        $csrf = self::csrf($client);

        foreach (["/orders/$foreign/", "/orders/$foreign/?chat=$offer", "/orders/$draft/pick/"] as $p) {
            $this->assertSame(404, self::fetch($p, $client)[0], "GET $p");
        }
        foreach ([
            [$foreign, ['action' => 'choose', 'offer_id' => $offer, 'expected_price' => 1200]],
            [$foreign, ['action' => 'collect']],
            [$draft, ['action' => 'invite', 'designer_id' => self::GHOST]],
        ] as [$id, $fields]) {
            [$code] = self::act($id, $client, $fields + ['csrf' => $csrf]);
            $this->assertSame(404, $code, $fields['action']);
        }
        $this->assertSame(OrderStatus::COLLECTING, (int)self::orderRow($foreign)['status']);
        $this->assertSame(OrderStatus::DRAFT, (int)self::orderRow($draft)['status']);

        // Пропозиція з чужого замовлення у ?chat= свого не відкривається
        $mineOrder = $this->order($client);
        $body = $this->page("/orders/$mineOrder/?chat=$offer", $client);
        $this->assertStringNotContainsString('data-chat="' . $offer . '"', $body);
    }
}
