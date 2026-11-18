<?php

use Design\Config;
use Design\Customers;
use Design\DesignerException;
use Design\DesignerStatus;
use Design\Messages;
use Design\Notify;
use Design\Offers;
use Design\OfferStatus;
use Design\Orders;
use Design\OrderStatus;
use Design\Price;
use Design\Products;
use Design\Settings;
use Design\Sql;
use PHPUnit\Framework\TestCase;

/**
 * Режим «Одразу обрати»: запрошення, «Так» / «Ні», відкликання, «Запросити іншого», «Збирати пропозиції».
 * Клієнт і дизайнери — фіктивні customer_id 999999721+ (рядків у customers немає, куратора теж). Для сповіщення куратору —
 * справжній клієнт DESIGN_TEST_CUSTOMER_ID: видаляємо лише його замовлення, створені тестом. Замовлення — автоінкремент
 * @group db
 */
final class OffersMode2Test extends TestCase
{
    private const CLIENT = 999999721;
    private const A = 999999722;          // схвалений дизайнер
    private const B = 999999723;          // схвалений дизайнер
    private const PENDING = 999999724;    // на розгляді
    private const BLOCKED = 999999725;    // заблокований
    private const IDS = [self::CLIENT, self::A, self::B, self::PENDING, self::BLOCKED];

    /** @var list<int> замовлення справжнього клієнта, створені тестом */
    private array $created = [];
    private string $log;
    private string|false $oldLog;

    protected function setUp(): void
    {
        Notify::$sent = [];
        Settings::reset();
        $this->log = tempnam(sys_get_temp_dir(), 'offers2');
        $this->oldLog = ini_set('error_log', $this->log);
        $this->cleanup();
        $this->designer(self::A, 'Петро Дизайнер', DesignerStatus::APPROVED);
        $this->designer(self::B, 'Оксана', DesignerStatus::APPROVED);
        $this->designer(self::PENDING, 'Новачок', DesignerStatus::PENDING);
        $this->designer(self::BLOCKED, 'Заблокований', DesignerStatus::BLOCKED);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        Notify::$sent = [];
        ini_set('error_log', $this->oldLog === false ? '' : $this->oldLog);
        @unlink($this->log);
    }

    private function cleanup(): void
    {
        $in = implode(',', self::IDS);
        $orders = $this->created === [] ? '0' : implode(',', $this->created);
        Sql::exec("DELETE m FROM design_messages m JOIN design_offers o ON o.id = m.offer_id
                   LEFT JOIN design_orders d ON d.id = o.order_id
                   WHERE o.designer_id IN ($in) OR d.customer_id IN ($in) OR o.order_id IN ($orders)");
        Sql::exec("DELETE o FROM design_offers o LEFT JOIN design_orders d ON d.id = o.order_id
                   WHERE o.designer_id IN ($in) OR d.customer_id IN ($in) OR o.order_id IN ($orders)");
        Sql::exec("DELETE FROM design_orders WHERE customer_id IN ($in) OR id IN ($orders)");
        Sql::exec("DELETE FROM design_designers WHERE customer_id IN ($in)");
        $this->created = [];
    }

    private function designer(int $id, string $name, int $status): void
    {
        Sql::insert('design_designers', ['customer_id' => $id, 'status' => $status, 'name' => $name, 'photo' => 'avatar-0123abcd']);
    }

    /** Чернетка клієнта; для справжнього клієнта id запамʼятовується для прибирання */
    private function draft(int $customerId = self::CLIENT): int
    {
        $id = Sql::insert('design_orders', [
            'customer_id' => $customerId,
            'product_id' => Products::visible()[0]['id'],
            'mode' => 1,
            'status' => OrderStatus::DRAFT,
            'answers' => json_encode([['q' => 'Розмір', 'a' => '90×50'], ['q' => 'Текст', 'a' => 'ПІБ']], JSON_UNESCAPED_UNICODE),
        ]);
        if (!in_array($customerId, self::IDS, true)) $this->created[] = $id;
        return $id;
    }

    private function realClient(): int
    {
        $id = (int)getenv('DESIGN_TEST_CUSTOMER_ID');
        if ($id <= 0) self::markTestSkipped('DESIGN_TEST_CUSTOMER_ID не задан');
        return $id;
    }

    private function inDays(int $n): string { return date('Y-m-d', strtotime("+$n day")); }

    private function assertError(string $message, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Очікувалась помилка «{$message}»");
        } catch (DesignerException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    private function offerRow(int $id): array
    {
        return Sql::one('SELECT * FROM design_offers WHERE id = ?', [$id]);
    }

    private function orderRow(int $id): array
    {
        return Sql::one('SELECT * FROM design_orders WHERE id = ?', [$id]);
    }

    private function offerId(int $orderId, int $designerId): int
    {
        return (int)Sql::value('SELECT id FROM design_offers WHERE order_id = ? AND designer_id = ?', [$orderId, $designerId]);
    }

    private function time(): string
    {
        return ' \(\d\d\.\d\d \d\d:\d\d\)$~u';
    }

    private function logText(): string
    {
        return (string)file_get_contents($this->log);
    }

    public function test_publish_with_designer_invites(): void
    {
        $orderId = $this->draft();
        Offers::invite($orderId, self::CLIENT, self::A);

        $order = $this->orderRow($orderId);
        $this->assertSame(OrderStatus::WAITING_DESIGNER, (int)$order['status']);
        $this->assertSame(2, (int)$order['mode']);
        $this->assertNotNull($order['published_at']);
        $this->assertLessThan(5, abs(strtotime($order['published_at']) - time()));

        $offerId = $this->offerId($orderId, self::A);
        $this->assertGreaterThan(0, $offerId);
        $this->assertSame(OfferStatus::INVITED, (int)$this->offerRow($offerId)['status']);

        $this->assertCount(1, Notify::$sent);
        $n = Notify::$sent[0];
        $this->assertSame(self::A, $n['customer_id']);
        // фіктивного клієнта немає в customers — «Клієнт»
        $this->assertMatchesRegularExpression(
            '~^Майстерня дизайну: клієнт Клієнт обрав вас для замовлення №' . $orderId . '\. Береш\? '
            . preg_quote(Config::siteUrl() . '/feed/' . $orderId . '/', '~') . $this->time(),
            $n['msg']
        );
        $this->assertSame('Майстерня дизайну: вас обрали для №' . $orderId . '. design.wolf.ua', $n['msgSMS']);

        // клієнт чекає відповіді; у списку пропозицій запрошення немає; у стрічці бриф не видно
        $state = Offers::mode2State($orderId, self::CLIENT);
        $this->assertSame('waiting', $state['state']);
        $this->assertSame($offerId, $state['offer']['id']);
        $this->assertSame('Петро Дизайнер', $state['offer']['designer_name']);
        $this->assertTrue($state['offer']['available']);
        $this->assertSame([], Offers::forOrder($orderId, self::CLIENT));
        $this->assertNotContains($orderId, array_column(Offers::feed(self::B, 1)['items'], 'order_id'));
        $this->assertNull(Offers::mode2State($orderId, self::A));          // чуже замовлення

        // подвійний клік «Опублікувати» з тим самим дизайнером — нічого не змінює, повторного сповіщення немає
        Notify::$sent = [];
        $before = $this->orderRow($orderId);
        Offers::invite($orderId, self::CLIENT, self::A);
        $this->assertSame($before, $this->orderRow($orderId));
        $this->assertSame(OfferStatus::INVITED, (int)$this->offerRow($offerId)['status']);
        $this->assertSame(1, (int)Sql::value('SELECT COUNT(*) FROM design_offers WHERE order_id = ?', [$orderId]));
        $this->assertSame([], Notify::$sent);

        // поки чекаємо — нікого більше не запросити і не перемкнути на збір
        $this->assertError('Замовлення зараз не можна змінити', fn() => Offers::invite($orderId, self::CLIENT, self::B));
        $this->assertError('Замовлення зараз не можна змінити', fn() => Offers::collect($orderId, self::CLIENT));
        $this->assertError('Замовлення не знайдено', fn() => Offers::invite($orderId, self::B, self::A));
        $this->assertSame(0, $this->offerId($orderId, self::B));
        $this->assertSame([], Notify::$sent);

        // режим 1 — без змін: публікація чернетки → збір пропозицій; запросити в бриф режиму 1 не можна; mode2State — null
        $mode1 = $this->draft();
        Orders::publish($mode1, self::CLIENT);
        $row = $this->orderRow($mode1);
        $this->assertSame(OrderStatus::COLLECTING, (int)$row['status']);
        $this->assertSame(1, (int)$row['mode']);
        $this->assertError('Замовлення зараз не можна змінити', fn() => Offers::invite($mode1, self::CLIENT, self::A));
        $this->assertNull(Offers::mode2State($mode1, self::CLIENT));
        $this->assertSame([], Notify::$sent);
    }

    public function test_accept_moves_to_collecting_and_notifies(): void
    {
        $orderId = $this->draft();
        Offers::invite($orderId, self::CLIENT, self::A);
        $offerId = $this->offerId($orderId, self::A);
        Notify::$sent = [];

        // ті самі перевірки, що й у propose; нічого не змінюється
        $this->assertError('Вкажіть суму від 1 грн', fn() => Offers::accept($offerId, self::A, 'Текст', 0, $this->inDays(1)));
        $this->assertError('Опишіть, що зробите', fn() => Offers::accept($offerId, self::A, ' ', 100, $this->inDays(1)));
        $this->assertError('Дата — не раніше сьогодні', fn() => Offers::accept($offerId, self::A, 'Текст', 100, $this->inDays(-1)));
        $this->assertError('Пропозицію не знайдено', fn() => Offers::accept($offerId, self::B, 'Текст', 100, $this->inDays(1)));
        $this->assertSame(OfferStatus::INVITED, (int)$this->offerRow($offerId)['status']);
        $this->assertSame(OrderStatus::WAITING_DESIGNER, (int)$this->orderRow($orderId)['status']);

        $deadline = $this->inDays(3);
        Offers::accept($offerId, self::A, '  Зроблю за два дні  ', 850, $deadline);

        $row = $this->offerRow($offerId);
        $k = Settings::markupPercent();
        $p = Price::client(850, $k, Settings::priceRound());
        $this->assertSame(OfferStatus::SENT, (int)$row['status']);
        $this->assertSame('Зроблю за два дні', $row['text']);
        $this->assertSame(850, (int)$row['price_designer']);
        $this->assertSame($k, (float)$row['markup_percent']);
        $this->assertSame($p, (int)$row['price_client']);
        $this->assertSame($deadline, $row['deadline']);
        $order = $this->orderRow($orderId);
        $this->assertSame(OrderStatus::COLLECTING, (int)$order['status']);
        $this->assertSame(2, (int)$order['mode']);

        $this->assertCount(1, Notify::$sent);
        $n = Notify::$sent[0];
        $this->assertSame(self::CLIENT, $n['customer_id']);
        $this->assertMatchesRegularExpression(
            '~^Майстерня дизайну: нова пропозиція від Петро Дизайнер по замовленню №' . $orderId . ' — '
            . preg_quote(number_format($p, 0, ',', "\u{00A0}"), '~') . ' грн\. '
            . preg_quote(Config::siteUrl() . '/orders/' . $orderId . '/', '~') . $this->time(),
            $n['msg']
        );
        $this->assertSame('Майстерня дизайну: нова пропозиція по №' . $orderId . '. design.wolf.ua', $n['msgSMS']);

        // клієнт бачить пропозицію з ціною; бриф режиму 2 у стрічці так і не видно
        $state = Offers::mode2State($orderId, self::CLIENT);
        $this->assertSame('offer', $state['state']);
        $this->assertSame($offerId, $state['offer']['id']);
        $this->assertSame($p, $state['offer']['price_client']);
        $this->assertSame([$offerId], array_column(Offers::forOrder($orderId, self::CLIENT), 'id'));
        $this->assertNotContains($orderId, array_column(Offers::feed(self::B, 1)['items'], 'order_id'));

        // повтор «Так» — помилка; ціну до вибору можна змінити
        $this->assertError('Ви вже запропонували — змініть пропозицію', fn() => Offers::accept($offerId, self::A, 'Ще', 900, $deadline));
        $this->assertCount(1, Notify::$sent);

        // поки є пропозиція — не запросити іншого і не перемкнути на збір
        $this->assertError('Замовлення зараз не можна змінити', fn() => Offers::invite($orderId, self::CLIENT, self::B));
        $this->assertError('Замовлення зараз не можна змінити', fn() => Offers::collect($orderId, self::CLIENT));

        // клієнт обирає — далі як режим 1
        Offers::choose($orderId, self::CLIENT, $offerId, $p);
        $this->assertSame(OrderStatus::IN_WORK, (int)$this->orderRow($orderId)['status']);
        $this->assertSame(OfferStatus::CHOSEN, (int)$this->offerRow($offerId)['status']);
        $this->assertNull(Offers::mode2State($orderId, self::CLIENT));

        // не схвалений дизайнер «Так» сказати не може
        $other = $this->draft();
        Offers::invite($other, self::CLIENT, self::B);
        Sql::exec('UPDATE design_designers SET status = ? WHERE customer_id = ?', [DesignerStatus::BLOCKED, self::B]);
        $this->assertError('Лише схвалені дизайнери можуть пропонувати',
            fn() => Offers::accept($this->offerId($other, self::B), self::B, 'Текст', 100, $deadline));
        $this->assertSame(OfferStatus::INVITED, (int)$this->offerRow($this->offerId($other, self::B))['status']);
    }

    public function test_decline_with_reason_notifies_client_and_curator(): void
    {
        $client = $this->realClient();
        $curator = (int)Sql::value('SELECT curator_id FROM customers WHERE id = ?', [$client]);
        if ($curator <= 0) self::markTestSkipped('У DESIGN_TEST_CUSTOMER_ID немає куратора');
        $header = Customers::header($client);

        $orderId = $this->draft($client);
        Offers::invite($orderId, $client, self::A);
        $this->assertStringContainsString('клієнт ' . $header['short'] . ' обрав вас для замовлення №' . $orderId . '. Береш?', Notify::$sent[0]['msg']);
        $offerId = $this->offerId($orderId, self::A);
        Notify::$sent = [];

        $this->assertError('Пропозицію не знайдено', fn() => Offers::decline($offerId, self::B, ''));
        $this->assertError('Повідомлення — не більше 2000 символів', fn() => Offers::decline($offerId, self::A, str_repeat('я', Messages::TEXT_MAX + 1)));
        $this->assertSame(OfferStatus::INVITED, (int)$this->offerRow($offerId)['status']);
        $this->assertSame([], Messages::list($offerId));

        Offers::decline($offerId, self::A, "  Зайнятий до п'ятниці  ");

        $this->assertSame(OfferStatus::DECLINED, (int)$this->offerRow($offerId)['status']);
        $order = $this->orderRow($orderId);
        $this->assertSame(OrderStatus::COLLECTING, (int)$order['status']);
        $this->assertSame(2, (int)$order['mode']);

        // причина — повідомлення дизайнера в листуванні з позначкою відмови, видне клієнту
        $msgs = Messages::list($offerId);
        $this->assertCount(1, $msgs);
        $this->assertSame(Messages::ROLE_DESIGNER, $msgs[0]['role']);
        $this->assertSame(self::A, $msgs[0]['author_id']);
        $this->assertSame("Зайнятий до п'ятниці", $msgs[0]['text']);
        $this->assertSame(['event' => 'decline'], $msgs[0]['data']);
        $this->assertSame($msgs[0]['id'], Offers::declineReason($offerId)['id']);

        $this->assertCount(2, Notify::$sent);
        [$toClient, $toCurator] = Notify::$sent;
        $this->assertSame($client, $toClient['customer_id']);
        $this->assertMatchesRegularExpression(
            '~^Майстерня дизайну: Петро Дизайнер не може взяти №' . $orderId . '\. Оберіть іншого дизайнера: '
            . preg_quote(Config::siteUrl() . '/orders/' . $orderId . '/', '~') . $this->time(),
            $toClient['msg']
        );
        $this->assertSame('Майстерня дизайну: дизайнер відмовився від №' . $orderId . '. design.wolf.ua', $toClient['msgSMS']);

        $this->assertSame($curator, $toCurator['user_id']);
        $this->assertArrayNotHasKey('customer_id', $toCurator);
        $this->assertMatchesRegularExpression(
            '~^Майстерня дизайну: дизайнер Петро Дизайнер відмовився від №' . $orderId
            . ' \(клієнт ' . preg_quote($header['name'], '~') . ', id ' . $client . '\)' . $this->time(),
            $toCurator['msg']
        );
        $this->assertSame('Майстерня дизайну: дизайнер відмовився від №' . $orderId . '. design.wolf.ua', $toCurator['msgSMS']);

        // клієнт бачить відмову з кнопками
        $state = Offers::mode2State($orderId, $client);
        $this->assertSame('declined', $state['state']);
        $this->assertSame($offerId, $state['offer']['id']);
        $this->assertSame(OfferStatus::DECLINED, $state['offer']['status']);

        // повтор — помилка, сповіщень більше немає
        $this->assertError('Запрошення вже неактуальне', fn() => Offers::decline($offerId, self::A, ''));
        $this->assertCount(2, Notify::$sent);

        // без причини і без куратора (фіктивний клієнт): повідомлення немає, куратору — лише запис у журнал
        Notify::$sent = [];
        $fake = $this->draft();
        Offers::invite($fake, self::CLIENT, self::B);
        $fakeOffer = $this->offerId($fake, self::B);
        Notify::$sent = [];
        Offers::decline($fakeOffer, self::B, '   ');
        $this->assertSame([], Messages::list($fakeOffer));
        $this->assertCount(1, Notify::$sent);
        $this->assertSame(self::CLIENT, Notify::$sent[0]['customer_id']);
        $this->assertStringContainsString('Оксана не може взяти №' . $fake, Notify::$sent[0]['msg']);
        $this->assertStringContainsString('немає куратора', $this->logText());
        $this->assertStringContainsString('id ' . self::CLIENT, $this->logText());
    }

    public function test_withdraw_after_accept_acts_like_decline(): void
    {
        $orderId = $this->draft();
        Offers::invite($orderId, self::CLIENT, self::A);
        $offerId = $this->offerId($orderId, self::A);
        Offers::accept($offerId, self::A, 'Зроблю', 500, $this->inDays(2));
        Notify::$sent = [];

        Offers::withdraw($offerId, self::A);

        $this->assertSame(OfferStatus::WITHDRAWN, (int)$this->offerRow($offerId)['status']);
        $order = $this->orderRow($orderId);
        $this->assertSame(OrderStatus::COLLECTING, (int)$order['status']);
        $this->assertSame(2, (int)$order['mode']);

        $this->assertCount(1, Notify::$sent);   // куратора у фіктивного клієнта немає — лише журнал
        $n = Notify::$sent[0];
        $this->assertSame(self::CLIENT, $n['customer_id']);
        $this->assertMatchesRegularExpression(
            '~^Майстерня дизайну: Петро Дизайнер не може взяти №' . $orderId . '\. Оберіть іншого дизайнера: '
            . preg_quote(Config::siteUrl() . '/orders/' . $orderId . '/', '~') . $this->time(),
            $n['msg']
        );
        $this->assertSame('Майстерня дизайну: дизайнер відмовився від №' . $orderId . '. design.wolf.ua', $n['msgSMS']);
        $this->assertStringContainsString('дизайнер Петро Дизайнер відмовився від №' . $orderId, $this->logText());

        $state = Offers::mode2State($orderId, self::CLIENT);
        $this->assertSame('declined', $state['state']);
        $this->assertSame(OfferStatus::WITHDRAWN, $state['offer']['status']);

        // клієнт може запросити іншого
        Offers::invite($orderId, self::CLIENT, self::B);
        $this->assertSame(OrderStatus::WAITING_DESIGNER, (int)$this->orderRow($orderId)['status']);

        // відкликання в режимі 1 — без сповіщень
        $mode1 = $this->draft();
        Orders::publish($mode1, self::CLIENT);
        $m1 = Offers::propose($mode1, self::A, 'Режим 1', 300, $this->inDays(1));
        Notify::$sent = [];
        Offers::withdraw($m1, self::A);
        $this->assertSame([], Notify::$sent);
    }

    public function test_reinvite_declined_designer(): void
    {
        $orderId = $this->draft();
        Offers::invite($orderId, self::CLIENT, self::A);
        $a = $this->offerId($orderId, self::A);
        Offers::decline($a, self::A, '');

        // «Запросити іншого»
        Notify::$sent = [];
        Offers::invite($orderId, self::CLIENT, self::B);
        $b = $this->offerId($orderId, self::B);
        $this->assertSame(OfferStatus::INVITED, (int)$this->offerRow($b)['status']);
        $this->assertSame(OrderStatus::WAITING_DESIGNER, (int)$this->orderRow($orderId)['status']);
        $this->assertSame(self::B, Notify::$sent[0]['customer_id']);
        $state = Offers::mode2State($orderId, self::CLIENT);
        $this->assertSame('waiting', $state['state']);
        $this->assertSame($b, $state['offer']['id']);
        Offers::decline($b, self::B, 'Ні');

        // знову того, хто вже відмовився, — той самий рядок
        Notify::$sent = [];
        Offers::invite($orderId, self::CLIENT, self::A);
        $this->assertSame($a, $this->offerId($orderId, self::A));
        $this->assertSame(OfferStatus::INVITED, (int)$this->offerRow($a)['status']);
        $this->assertSame(1, (int)Sql::value('SELECT COUNT(*) FROM design_offers WHERE order_id = ? AND designer_id = ?', [$orderId, self::A]));
        $this->assertSame(self::A, Notify::$sent[0]['customer_id']);
        $this->assertSame($a, Offers::mode2State($orderId, self::CLIENT)['offer']['id']);

        // і після «Так» + відкликання — теж той самий рядок, але з чистими полями: старі текст, ціна й термін не показуються
        Offers::accept($a, self::A, 'Так', 400, $this->inDays(1));
        Offers::withdraw($a, self::A);
        Offers::invite($orderId, self::CLIENT, self::A);
        $row = $this->offerRow($a);
        $this->assertSame(OfferStatus::INVITED, (int)$row['status']);
        $this->assertNull($row['text']);
        $this->assertSame(0, (int)$row['price_designer']);
        $this->assertSame(0.0, (float)$row['markup_percent']);
        $this->assertSame(0, (int)$row['price_client']);
        $this->assertNull($row['deadline']);
        $card = Offers::mode2State($orderId, self::CLIENT)['offer'];
        $this->assertSame('', $card['text']);
        $this->assertSame(0, $card['price_client']);
        $this->assertNull($card['deadline']);

        // захист у глибину: навіть якщо в запрошенні щось лишилося, картка його не показує
        Sql::exec("UPDATE design_offers SET text = 'Старий текст', price_designer = 400, markup_percent = 20, price_client = 480, deadline = ? WHERE id = ?",
            [$this->inDays(1), $a]);
        $state = Offers::mode2State($orderId, self::CLIENT);
        $this->assertSame('waiting', $state['state']);
        $this->assertSame('', $state['offer']['text']);
        $this->assertSame(0, $state['offer']['price_client']);
        $this->assertNull($state['offer']['deadline']);
        Offers::accept($a, self::A, 'Знову так', 450, $this->inDays(1));
        $this->assertSame(450, (int)$this->offerRow($a)['price_designer']);
        $this->assertSame(OrderStatus::COLLECTING, (int)$this->orderRow($orderId)['status']);
    }

    public function test_collect_republishes(): void
    {
        $orderId = $this->draft();
        Offers::invite($orderId, self::CLIENT, self::A);
        $a = $this->offerId($orderId, self::A);
        Offers::decline($a, self::A, 'Не встигну');
        Sql::exec("UPDATE design_orders SET published_at = NOW() - INTERVAL 2 DAY, notified_no_offers_at = NOW() - INTERVAL 1 DAY WHERE id = ?", [$orderId]);
        Notify::$sent = [];

        $this->assertError('Замовлення не знайдено', fn() => Offers::collect($orderId, self::A));
        Offers::collect($orderId, self::CLIENT);

        $order = $this->orderRow($orderId);
        $this->assertSame(OrderStatus::COLLECTING, (int)$order['status']);
        $this->assertSame(1, (int)$order['mode']);
        $this->assertLessThan(5, abs(strtotime($order['published_at']) - time()));
        $this->assertNull($order['notified_no_offers_at']);
        $this->assertNull(Offers::mode2State($orderId, self::CLIENT));
        $this->assertSame([], Notify::$sent);

        // подвійний клік — нічого не змінює і не падає
        Offers::collect($orderId, self::CLIENT);
        $this->assertSame(1, (int)$this->orderRow($orderId)['mode']);

        // бриф у стрічці як новий — і для того, хто відмовився; він може запропонувати себе в той самий рядок
        $feed = array_column(Offers::feed(self::A, 1)['items'], null, 'order_id');
        $this->assertArrayHasKey($orderId, $feed);
        $this->assertTrue($feed[$orderId]['is_new']);
        $this->assertArrayHasKey($orderId, array_column(Offers::feed(self::B, 1)['items'], null, 'order_id'));
        $this->assertSame($a, Offers::propose($orderId, self::A, 'Тепер можу', 600, $this->inDays(2)));
        $this->assertSame(OfferStatus::SENT, (int)$this->offerRow($a)['status']);

        // режим 1 і чернетку на збір не перемкнути; у режимі 1 запросити не можна
        $this->assertError('Замовлення зараз не можна змінити', fn() => Offers::invite($orderId, self::CLIENT, self::B));
        $draft = $this->draft();
        $this->assertError('Замовлення зараз не можна змінити', fn() => Offers::collect($draft, self::CLIENT));
        $this->assertSame(OrderStatus::DRAFT, (int)$this->orderRow($draft)['status']);
    }

    public function test_invite_blocked_or_self_refused(): void
    {
        $orderId = $this->draft();
        foreach ([self::PENDING, self::BLOCKED, 999999729] as $designer) {   // останній — взагалі не дизайнер
            $this->assertError('Дизайнер недоступний', fn() => Offers::invite($orderId, self::CLIENT, $designer));
        }
        // дизайнер теж клієнт: себе обрати не можна
        $own = $this->draft(self::A);
        $this->assertError('Це ваше замовлення — обрати себе не можна', fn() => Offers::invite($own, self::A, self::A));
        $this->assertError('Замовлення не знайдено', fn() => Offers::invite(0, self::CLIENT, self::A));

        foreach ([$orderId, $own] as $id) {
            $row = $this->orderRow($id);
            $this->assertSame(OrderStatus::DRAFT, (int)$row['status']);
            $this->assertSame(1, (int)$row['mode']);
            $this->assertNull($row['published_at']);
        }
        $this->assertSame(0, (int)Sql::value('SELECT COUNT(*) FROM design_offers WHERE order_id IN (?, ?)', [$orderId, $own]));
        $this->assertSame([], Notify::$sent);
    }

    public function test_blocked_invited_looks_declined(): void
    {
        $orderId = $this->draft();
        Offers::invite($orderId, self::CLIENT, self::A);
        $a = $this->offerId($orderId, self::A);
        Sql::exec('UPDATE design_designers SET status = ? WHERE customer_id = ?', [DesignerStatus::BLOCKED, self::A]);

        $state = Offers::mode2State($orderId, self::CLIENT);
        $this->assertSame('declined', $state['state']);
        $this->assertSame($a, $state['offer']['id']);
        $this->assertFalse($state['offer']['available']);
        $this->assertSame(OfferStatus::INVITED, $state['offer']['status']);

        // кнопки працюють: «Запросити іншого» закриває запрошення заблокованого
        Notify::$sent = [];
        Offers::invite($orderId, self::CLIENT, self::B);
        $this->assertSame(OfferStatus::DECLINED, (int)$this->offerRow($a)['status']);
        $this->assertSame(OrderStatus::WAITING_DESIGNER, (int)$this->orderRow($orderId)['status']);
        $this->assertSame([self::B], array_column(Notify::$sent, 'customer_id'));
        $state = Offers::mode2State($orderId, self::CLIENT);
        $this->assertSame('waiting', $state['state']);
        $this->assertSame($this->offerId($orderId, self::B), $state['offer']['id']);

        // і «Збирати пропозиції» — теж
        $other = $this->draft();
        Offers::invite($other, self::CLIENT, self::B);
        $b = $this->offerId($other, self::B);
        Sql::exec('UPDATE design_designers SET status = ? WHERE customer_id = ?', [DesignerStatus::BLOCKED, self::B]);
        $this->assertSame('declined', Offers::mode2State($other, self::CLIENT)['state']);
        Offers::collect($other, self::CLIENT);
        $row = $this->orderRow($other);
        $this->assertSame(OrderStatus::COLLECTING, (int)$row['status']);
        $this->assertSame(1, (int)$row['mode']);
        $this->assertSame(OfferStatus::DECLINED, (int)$this->offerRow($b)['status']);
    }

    /** Причина відмови для клієнта — лише позначене повідомлення (data.event = decline) поточного раунду запрошення */
    public function test_decline_reason_only_marked_and_current_round(): void
    {
        $orderId = $this->draft();
        Offers::invite($orderId, self::CLIENT, self::A);
        $a = $this->offerId($orderId, self::A);
        $this->assertNull(Offers::declineReason($a), 'запрошення без відповіді — причини немає');

        // Раунд 1: «Так», питання в листуванні, відкликання — це не відмова з причиною
        Offers::accept($a, self::A, 'Зроблю', 500, $this->inDays(2));
        Messages::add($a, self::A, Messages::ROLE_DESIGNER, 'А який формат?');
        Offers::withdraw($a, self::A);
        $this->assertNull(Offers::declineReason($a));

        // Раунд 2: «Ні» без причини — останнє повідомлення дизайнера (питання) причиною не стає
        Offers::invite($orderId, self::CLIENT, self::A);
        Offers::decline($a, self::A, '');
        $this->assertSame('declined', Offers::mode2State($orderId, self::CLIENT)['state']);
        $this->assertNull(Offers::declineReason($a));

        // Раунд 3: «Ні» з причиною
        Offers::invite($orderId, self::CLIENT, self::A);
        Offers::decline($a, self::A, 'Зайнятий до пʼятниці');
        $reason = Offers::declineReason($a);
        $this->assertSame('Зайнятий до пʼятниці', $reason['text']);
        $this->assertSame(['event' => 'decline'], $reason['data']);

        // Раунд 4 (пізніше): знову запрошений і знову «Ні» без причини — стара причина раунду 3 не показується
        Sql::exec('UPDATE design_messages SET created = created - INTERVAL 1 HOUR WHERE offer_id = ?', [$a]);
        Offers::invite($orderId, self::CLIENT, self::A);
        $this->assertNull(Offers::declineReason($a), 'запрошено знову — причини ще немає');
        Offers::decline($a, self::A, '');
        $this->assertNull(Offers::declineReason($a));

        // Раунд 5: нова причина — показується саме вона
        Offers::invite($orderId, self::CLIENT, self::A);
        Offers::decline($a, self::A, 'Тепер у відпустці');
        $this->assertSame('Тепер у відпустці', Offers::declineReason($a)['text']);
    }

    /** Перегляд листування (seen, notified) не робить стару відмову «останньою»: клієнт бачить того, хто відмовився пізніше */
    public function test_seen_does_not_change_latest_refusal(): void
    {
        $orderId = $this->draft();
        Offers::invite($orderId, self::CLIENT, self::A);
        $a = $this->offerId($orderId, self::A);
        Offers::decline($a, self::A, 'Не встигну');
        Offers::invite($orderId, self::CLIENT, self::B);
        $b = $this->offerId($orderId, self::B);
        Offers::decline($b, self::B, '');
        // відмови рознесені в часі: A — раніше, B — пізніше
        Sql::exec('UPDATE design_offers SET updated = NOW() - INTERVAL 10 MINUTE WHERE id = ?', [$a]);
        Sql::exec('UPDATE design_offers SET updated = NOW() - INTERVAL 5 MINUTE WHERE id = ?', [$b]);
        Sql::exec('UPDATE design_messages SET created = NOW() - INTERVAL 10 MINUTE WHERE offer_id = ?', [$a]);
        $this->assertSame($b, Offers::mode2State($orderId, self::CLIENT)['offer']['id']);

        // A відкрив своє старе листування, клієнту пішло б сповіщення — B лишається останнім, причина A не показується
        Messages::seen($a, Messages::ROLE_DESIGNER);
        Messages::notifyNew($a, Messages::ROLE_CLIENT, self::CLIENT, 'Петро Дизайнер', $orderId);
        $this->assertSame($b, Offers::mode2State($orderId, self::CLIENT)['offer']['id']);
        $this->assertNull(Offers::declineReason($b));
        $this->assertSame('Не встигну', Offers::declineReason($a)['text'], 'причина A лишається при A');
    }
}
