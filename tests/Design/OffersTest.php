<?php

use Design\Config;
use Design\DesignerException;
use Design\DesignerStatus;
use Design\Messages;
use Design\Notify;
use Design\Offers;
use Design\OfferStatus;
use Design\OrderStatus;
use Design\Price;
use Design\Products;
use Design\Settings;
use Design\Sql;
use PHPUnit\Framework\TestCase;

/**
 * Пропозиції режиму 1, вибір дизайнера, стрічка брифів.
 * Клієнт і дизайнери — фіктивні customer_id 999999711+ (рядків у customers немає); замовлення тест вставляє сам (id — автоінкремент)
 * @group db
 */
final class OffersTest extends TestCase
{
    private const CLIENT = 999999711;
    private const A = 999999712;          // схвалений дизайнер
    private const B = 999999713;          // схвалений дизайнер
    private const C = 999999714;          // схвалений, нічого не пропонує
    private const PENDING = 999999715;    // на розгляді
    private const IDS = [self::CLIENT, self::A, self::B, self::C, self::PENDING];

    protected function setUp(): void
    {
        Notify::$sent = [];
        Settings::reset();
        $this->cleanup();
        foreach ([self::A => 'Петро Дизайнер', self::B => 'Оксана', self::C => 'Сергій'] as $id => $name) {
            $this->designer($id, $name, DesignerStatus::APPROVED);
        }
        $this->designer(self::PENDING, 'Новачок', DesignerStatus::PENDING);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        Notify::$sent = [];
    }

    private function cleanup(): void
    {
        $in = implode(',', self::IDS);
        Sql::exec("DELETE m FROM design_messages m JOIN design_offers o ON o.id = m.offer_id
                   LEFT JOIN design_orders d ON d.id = o.order_id
                   WHERE o.designer_id IN ($in) OR d.customer_id IN ($in)");
        Sql::exec("DELETE o FROM design_offers o LEFT JOIN design_orders d ON d.id = o.order_id
                   WHERE o.designer_id IN ($in) OR d.customer_id IN ($in)");
        Sql::exec("DELETE FROM design_orders WHERE customer_id IN ($in)");
        Sql::exec("DELETE FROM design_designers WHERE customer_id IN ($in)");
    }

    private function designer(int $id, string $name, int $status): void
    {
        Sql::insert('design_designers', ['customer_id' => $id, 'status' => $status, 'name' => $name, 'photo' => 'avatar-0123abcd']);
    }

    private function order(int $customerId = self::CLIENT, int $status = OrderStatus::COLLECTING, int $mode = 1, ?array $answers = null, string $published = 'NOW'): int
    {
        $answers ??= [['q' => 'Розмір', 'a' => '90×50'], ['q' => 'Текст', 'a' => 'ПІБ'], ['q' => 'Стиль', 'a' => 'строго']];
        $id = Sql::insert('design_orders', [
            'customer_id' => $customerId,
            'product_id' => Products::visible()[0]['id'],
            'mode' => $mode,
            'status' => $status,
            'answers' => json_encode($answers, JSON_UNESCAPED_UNICODE),
        ]);
        if ($status !== OrderStatus::DRAFT) {
            Sql::exec('UPDATE design_orders SET published_at = ' . ($published === 'NOW' ? 'NOW()' : '?') . ' WHERE id = ?',
                $published === 'NOW' ? [$id] : [$published, $id]);
        }
        return $id;
    }

    private function today(): string { return date('Y-m-d'); }
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

    /** Ціна для клієнта, яку він бачив і підтверджує при виборі */
    private function price(int $offerId): int
    {
        return (int)Sql::value('SELECT price_client FROM design_offers WHERE id = ?', [$offerId]);
    }

    public function test_propose_snapshot_and_notify(): void
    {
        $orderId = $this->order();
        $deadline = $this->inDays(3);
        $id = Offers::propose($orderId, self::A, "  Зроблю 2 варіанти <b>і</b> м'ята  ", 850, $deadline);

        $row = $this->offerRow($id);
        $k = Settings::markupPercent();
        $p = Price::client(850, $k, Settings::priceRound());
        $this->assertSame(OfferStatus::SENT, (int)$row['status']);
        $this->assertSame($orderId, (int)$row['order_id']);
        $this->assertSame(self::A, (int)$row['designer_id']);
        $this->assertSame("Зроблю 2 варіанти <b>і</b> м'ята", $row['text']);
        $this->assertSame(850, (int)$row['price_designer']);
        $this->assertSame($k, (float)$row['markup_percent']);
        $this->assertSame($p, (int)$row['price_client']);
        $this->assertSame($deadline, $row['deadline']);

        $this->assertCount(1, Notify::$sent);
        $n = Notify::$sent[0];
        $this->assertSame(self::CLIENT, $n['customer_id']);
        $this->assertMatchesRegularExpression(
            '~^Майстерня дизайну: нова пропозиція від Петро Дизайнер по замовленню №' . $orderId . ' — '
            . preg_quote(number_format($p, 0, ',', "\u{00A0}"), '~') . ' грн\. '
            . preg_quote(Config::siteUrl() . '/orders/' . $orderId . '/', '~') . ' \(\d\d\.\d\d \d\d:\d\d\)$~u',
            $n['msg']
        );
        $this->assertSame('Майстерня дизайну: нова пропозиція по №' . $orderId . '. design.wolf.ua', $n['msgSMS']);

        // get: рядок + замовлення
        $got = Offers::get($id);
        $this->assertSame($id, $got['id']);
        $this->assertSame($orderId, $got['order_id']);
        $this->assertSame(self::CLIENT, $got['customer_id']);
        $this->assertSame(OfferStatus::SENT, $got['status']);
        $this->assertSame($p, $got['price_client']);
        $this->assertNull(Offers::get(0));

        // повторне — лише через «Змінити»
        $this->assertError('Ви вже запропонували — змініть пропозицію', fn() => Offers::propose($orderId, self::A, 'Ще раз', 900, $deadline));
        $this->assertCount(1, Notify::$sent);
    }

    public function test_propose_rules(): void
    {
        $orderId = $this->order();
        $tomorrow = $this->inDays(1);

        $this->assertError('Лише схвалені дизайнери можуть пропонувати', fn() => Offers::propose($orderId, self::PENDING, 'Текст', 100, $tomorrow));
        $this->assertError('Лише схвалені дизайнери можуть пропонувати', fn() => Offers::propose($orderId, self::CLIENT, 'Текст', 100, $tomorrow));

        // своє замовлення (дизайнер теж клієнт)
        $own = $this->order(self::A);
        $this->assertError('Це ваше замовлення — пропонувати на нього не можна', fn() => Offers::propose($own, self::A, 'Текст', 100, $tomorrow));

        // замовлення не в зборі пропозицій або режим 2
        foreach ([$this->order(self::CLIENT, OrderStatus::DRAFT), $this->order(self::CLIENT, OrderStatus::IN_WORK),
                  $this->order(self::CLIENT, OrderStatus::COLLECTING, 2), $this->order(self::CLIENT, OrderStatus::WAITING_DESIGNER, 2), 0] as $closed) {
            $this->assertError('Замовлення вже не приймає пропозицій', fn() => Offers::propose($closed, self::A, 'Текст', 100, $tomorrow));
        }

        $this->assertError('Опишіть, що зробите', fn() => Offers::propose($orderId, self::A, "  \n ", 100, $tomorrow));
        $this->assertError('Опис — не більше 2000 символів', fn() => Offers::propose($orderId, self::A, str_repeat('я', Offers::TEXT_MAX + 1), 100, $tomorrow));
        $this->assertError('Вкажіть суму від 1 грн', fn() => Offers::propose($orderId, self::A, 'Текст', 0, $tomorrow));
        $this->assertError('Вкажіть суму від 1 грн', fn() => Offers::propose($orderId, self::A, 'Текст', -5, $tomorrow));
        $this->assertError('Сума — не більше 1 000 000 грн', fn() => Offers::propose($orderId, self::A, 'Текст', 1000001, $tomorrow));
        $this->assertError('Дата — не раніше сьогодні', fn() => Offers::propose($orderId, self::A, 'Текст', 100, $this->inDays(-1)));
        foreach (['', '2026-02-30', '19.11.2026', '2026-11-19 10:00'] as $bad) {
            $this->assertError('Вкажіть дату «Готово до»', fn() => Offers::propose($orderId, self::A, 'Текст', 100, $bad));
        }

        $this->assertSame(0, (int)Sql::value('SELECT COUNT(*) FROM design_offers WHERE designer_id IN (?, ?, ?)', [self::A, self::PENDING, self::CLIENT]));
        $this->assertSame([], Notify::$sent);

        // межі: сьогодні, 1 грн, рівно 2000 символів — можна
        $id = Offers::propose($orderId, self::A, str_repeat('я', Offers::TEXT_MAX), 1, $this->today());
        $this->assertSame($this->today(), $this->offerRow($id)['deadline']);
    }

    public function test_update_price_change_makes_system_message_and_uses_current_markup(): void
    {
        $orderId = $this->order();
        $d1 = $this->inDays(2);
        $id = Offers::propose($orderId, self::A, 'Текст', 1000, $d1);
        Notify::$sent = [];
        // знімок зроблено при старій націнці 10%
        Sql::exec('UPDATE design_offers SET markup_percent = 10, price_client = 1100 WHERE id = ?', [$id]);

        // лише текст — знімок ціни не змінюється, системних повідомлень немає
        Offers::update($id, self::A, 'Новий текст', 1000, $d1);
        $row = $this->offerRow($id);
        $this->assertSame('Новий текст', $row['text']);
        $this->assertSame(1100, (int)$row['price_client']);
        $this->assertSame(10.0, (float)$row['markup_percent']);
        $this->assertSame([], Messages::list($id));

        // нова ціна — за поточною націнкою
        $k = Settings::markupPercent();
        $newP = Price::client(1200, $k, Settings::priceRound());
        $this->assertNotSame(1100, $newP);
        Offers::update($id, self::A, 'Новий текст', 1200, $d1);
        $row = $this->offerRow($id);
        $this->assertSame(1200, (int)$row['price_designer']);
        $this->assertSame($k, (float)$row['markup_percent']);
        $this->assertSame($newP, (int)$row['price_client']);

        $msgs = Messages::list($id);
        $this->assertCount(1, $msgs);
        $this->assertSame(Messages::ROLE_SYSTEM, $msgs[0]['role']);
        $this->assertSame('Ціну змінено з 1' . "\u{00A0}" . '100 на ' . number_format($newP, 0, ',', "\u{00A0}") . ' грн', $msgs[0]['text']);
        $data = $msgs[0]['data'];
        ksort($data);
        $this->assertSame(['event' => 'price', 'from' => 1100, 'to' => $newP], $data);

        // термін
        $d2 = $this->inDays(5);
        Offers::update($id, self::A, 'Новий текст', 1200, $d2);
        $msgs = Messages::list($id);
        $this->assertCount(2, $msgs);
        $this->assertSame('Термін змінено з ' . \Design\View::date($d1) . ' на ' . \Design\View::date($d2), $msgs[1]['text']);
        $data = $msgs[1]['data'];
        ksort($data);
        $this->assertSame(['event' => 'deadline', 'from' => $d1, 'to' => $d2], $data);
        $this->assertSame($d2, $this->offerRow($id)['deadline']);

        // клієнт бачить позначку «Ціну змінено»
        $card = array_values(array_filter(Offers::forOrder($orderId, self::CLIENT), fn($o) => $o['id'] === $id))[0];
        $this->assertTrue($card['price_changed']);
        $this->assertSame($newP, $card['price_client']);

        // правила ті самі, що й у propose; чужу пропозицію змінити не можна
        $this->assertError('Вкажіть суму від 1 грн', fn() => Offers::update($id, self::A, 'Текст', 0, $d2));
        $this->assertError('Дата — не раніше сьогодні', fn() => Offers::update($id, self::A, 'Текст', 100, $this->inDays(-1)));
        $this->assertError('Пропозицію не знайдено', fn() => Offers::update($id, self::B, 'Текст', 100, $d2));

        // після відкликання — не змінюється
        Offers::withdraw($id, self::A);
        $this->assertError('Пропозицію вже не можна змінити', fn() => Offers::update($id, self::A, 'Текст', 100, $d2));
        $this->assertSame([], Notify::$sent);
    }

    public function test_withdraw_and_repropose_same_row(): void
    {
        $orderId = $this->order();
        $id = Offers::propose($orderId, self::A, 'Перша', 500, $this->inDays(1));

        $this->assertError('Пропозицію не знайдено', fn() => Offers::withdraw($id, self::B));
        Offers::withdraw($id, self::A);
        $this->assertSame(OfferStatus::WITHDRAWN, (int)$this->offerRow($id)['status']);
        $this->assertError('Пропозицію вже не можна відкликати', fn() => Offers::withdraw($id, self::A));

        // у клієнта — сіра картка «відкликав»
        $card = array_values(array_filter(Offers::forOrder($orderId, self::CLIENT), fn($o) => $o['id'] === $id))[0];
        $this->assertSame(OfferStatus::WITHDRAWN, $card['status']);

        Notify::$sent = [];
        $again = Offers::propose($orderId, self::A, 'Друга', 700, $this->inDays(2));
        $this->assertSame($id, $again);
        $row = $this->offerRow($id);
        $this->assertSame(OfferStatus::SENT, (int)$row['status']);
        $this->assertSame('Друга', $row['text']);
        $this->assertSame(700, (int)$row['price_designer']);
        $this->assertSame(1, (int)Sql::value('SELECT COUNT(*) FROM design_offers WHERE order_id = ? AND designer_id = ?', [$orderId, self::A]));
        $this->assertCount(1, Notify::$sent);

        // відмова (режим 2, рядок 2) після «Збирати пропозиції» — теж той самий рядок
        $declined = Sql::insert('design_offers', ['order_id' => $orderId, 'designer_id' => self::B, 'status' => OfferStatus::DECLINED]);
        $this->assertSame($declined, Offers::propose($orderId, self::B, 'Тепер можу', 400, $this->inDays(1)));
        $this->assertSame(OfferStatus::SENT, (int)$this->offerRow($declined)['status']);
    }

    public function test_choose_twice_and_withdrawn_not_choosable(): void
    {
        $orderId = $this->order();
        $a = Offers::propose($orderId, self::A, 'A', 500, $this->inDays(1));
        $b = Offers::propose($orderId, self::B, 'B', 600, $this->inDays(1));
        $c = Offers::propose($orderId, self::C, 'C', 700, $this->inDays(1));
        Offers::withdraw($c, self::C);
        Notify::$sent = [];

        $this->assertError('Пропозиція недоступна', fn() => Offers::choose($orderId, self::CLIENT, $c, $this->price($c)));
        $this->assertError('Замовлення не знайдено', fn() => Offers::choose($orderId, self::A, $b, $this->price($b)));
        $this->assertError('Пропозиція недоступна', fn() => Offers::choose($this->order(), self::CLIENT, $b, $this->price($b)));   // з іншого замовлення
        $this->assertSame(OrderStatus::COLLECTING, (int)$this->orderRow($orderId)['status']);

        Offers::choose($orderId, self::CLIENT, $b, $this->price($b));
        $order = $this->orderRow($orderId);
        $this->assertSame(OrderStatus::IN_WORK, (int)$order['status']);
        $this->assertSame($b, (int)$order['chosen_offer_id']);
        $this->assertNotNull($order['chosen_at']);
        $this->assertSame(OfferStatus::CHOSEN, (int)$this->offerRow($b)['status']);
        $this->assertSame(OfferStatus::OTHER_CHOSEN, (int)$this->offerRow($a)['status']);
        $this->assertSame(OfferStatus::WITHDRAWN, (int)$this->offerRow($c)['status']);

        $this->assertCount(1, Notify::$sent);
        $n = Notify::$sent[0];
        $this->assertSame(self::B, $n['customer_id']);
        $this->assertMatchesRegularExpression(
            '~^Майстерня дизайну: клієнт обрав вашу пропозицію по №' . $orderId . '\. '
            . preg_quote(Config::siteUrl() . '/feed/' . $orderId . '/', '~') . ' \(\d\d\.\d\d \d\d:\d\d\)$~u',
            $n['msg']
        );
        $this->assertSame('Майстерня дизайну: вас обрали виконавцем №' . $orderId . '. design.wolf.ua', $n['msgSMS']);

        // повтор (подвійний клік, друга вкладка) — і та сама, і інша пропозиція
        $this->assertError('Дизайнера вже обрано', fn() => Offers::choose($orderId, self::CLIENT, $b, $this->price($b)));
        $this->assertError('Дизайнера вже обрано', fn() => Offers::choose($orderId, self::CLIENT, $a, $this->price($a)));
        $this->assertSame($b, (int)$this->orderRow($orderId)['chosen_offer_id']);
        $this->assertCount(1, Notify::$sent);

        // після вибору ціна й термін не змінюються, відкликати не можна
        $this->assertError('Пропозицію вже не можна змінити', fn() => Offers::update($b, self::B, 'B', 900, $this->inDays(1)));
        $this->assertError('Пропозицію вже не можна відкликати', fn() => Offers::withdraw($b, self::B));
        // і нову пропозицію не надіслати
        $this->assertError('Замовлення вже не приймає пропозицій', fn() => Offers::propose($orderId, self::C, 'C', 700, $this->inDays(1)));
    }

    public function test_choose_refused_when_price_changed(): void
    {
        $orderId = $this->order();
        $a = Offers::propose($orderId, self::A, 'A', 500, $this->inDays(1));
        $seen = $this->price($a);
        // дизайнер підняв ціну, поки клієнт тиснув «Обрати»
        Offers::update($a, self::A, 'A', 900, $this->inDays(1));
        $this->assertNotSame($seen, $this->price($a));
        Notify::$sent = [];

        $this->assertError('Ціну змінено — перегляньте пропозицію', fn() => Offers::choose($orderId, self::CLIENT, $a, $seen));
        $order = $this->orderRow($orderId);
        $this->assertSame(OrderStatus::COLLECTING, (int)$order['status']);
        $this->assertNull($order['chosen_offer_id']);
        $this->assertNull($order['chosen_at']);
        $this->assertSame(OfferStatus::SENT, (int)$this->offerRow($a)['status']);
        $this->assertSame([], Notify::$sent);

        // з новою ціною — обирається
        Offers::choose($orderId, self::CLIENT, $a, $this->price($a));
        $this->assertSame(OfferStatus::CHOSEN, (int)$this->offerRow($a)['status']);
    }

    public function test_choose_blocked_designer_refused(): void
    {
        $orderId = $this->order();
        $a = Offers::propose($orderId, self::A, 'A', 500, $this->inDays(1));
        $b = Offers::propose($orderId, self::B, 'B', 600, $this->inDays(1));
        Sql::exec('UPDATE design_designers SET status = ? WHERE customer_id = ?', [DesignerStatus::BLOCKED, self::B]);
        Notify::$sent = [];

        $cards = array_column(Offers::forOrder($orderId, self::CLIENT), null, 'id');
        $this->assertFalse($cards[$b]['available']);
        $this->assertTrue($cards[$a]['available']);

        $this->assertError('Дизайнер недоступний', fn() => Offers::choose($orderId, self::CLIENT, $b, $this->price($b)));
        $this->assertSame(OrderStatus::COLLECTING, (int)$this->orderRow($orderId)['status']);
        $this->assertNull($this->orderRow($orderId)['chosen_offer_id']);
        $this->assertSame(OfferStatus::SENT, (int)$this->offerRow($b)['status']);
        $this->assertSame([], Notify::$sent);

        // заблокований у стрічці не бачить нічого
        $this->assertSame(['items' => [], 'total' => 0, 'pages' => 1], Offers::feed(self::B, 1));
        $this->assertSame(0, Offers::feedNewCount(self::B));

        Offers::choose($orderId, self::CLIENT, $a, $this->price($a));
        $this->assertSame(OfferStatus::CHOSEN, (int)$this->offerRow($a)['status']);
        $this->assertSame(OfferStatus::OTHER_CHOSEN, (int)$this->offerRow($b)['status']);
    }

    public function test_for_order_fields_and_hides_invited(): void
    {
        $orderId = $this->order();
        $a = Offers::propose($orderId, self::A, 'Текст A', 500, $this->inDays(1));
        $invited = Sql::insert('design_offers', ['order_id' => $orderId, 'designer_id' => self::B, 'status' => OfferStatus::INVITED]);
        Messages::add($a, self::CLIENT, Messages::ROLE_CLIENT, 'Привіт');
        Messages::add($a, self::A, Messages::ROLE_DESIGNER, 'Вітаю');
        Messages::system($a, 'Термін змінено', ['event' => 'deadline']);

        $this->assertSame([], Offers::forOrder($orderId, self::A));     // чуже замовлення
        $list = Offers::forOrder($orderId, self::CLIENT);
        $this->assertSame([$a], array_column($list, 'id'));
        $this->assertNotContains($invited, array_column($list, 'id'));
        $card = $list[0];
        $this->assertSame(OfferStatus::SENT, $card['status']);
        $this->assertSame(self::A, $card['designer_id']);
        $this->assertSame('Петро Дизайнер', $card['designer_name']);
        $this->assertSame('avatar-0123abcd', $card['designer_photo']);
        $this->assertTrue($card['available']);
        $this->assertSame('Текст A', $card['text']);
        $this->assertSame(Price::client(500, Settings::markupPercent(), Settings::priceRound()), $card['price_client']);
        $this->assertSame($this->inDays(1), $card['deadline']);
        $this->assertSame(2, $card['messages']);
        $this->assertFalse($card['price_changed']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $card['updated']);
        $this->assertArrayNotHasKey('price_designer', $card);
    }

    public function test_feed_excludes_own_and_mode2_and_shows_other_chosen(): void
    {
        $answers = [['q' => 'Розмір', 'a' => ''], ['q' => 'Сторін', 'a' => 'дві'], ['q' => 'Текст', 'a' => '  '], ['q' => 'Стиль', 'a' => 'строго'], ['q' => 'Ще', 'a' => 'ні']];
        $open = $this->order(self::CLIENT, OrderStatus::COLLECTING, 1, $answers, date('Y-m-d H:i:s', time() - 60));
        $newer = $this->order(self::CLIENT, OrderStatus::COLLECTING, 1, null, date('Y-m-d H:i:s', time() - 30));
        $own = $this->order(self::A);
        $mode2 = $this->order(self::CLIENT, OrderStatus::COLLECTING, 2);
        $waiting = $this->order(self::CLIENT, OrderStatus::WAITING_DESIGNER, 2);
        $draft = $this->order(self::CLIENT, OrderStatus::DRAFT);
        $taken = $this->order(self::CLIENT, OrderStatus::COLLECTING, 1, null, date('Y-m-d H:i:s', time() - 120));

        $mine = Offers::propose($open, self::A, 'Моя', 1000, $this->inDays(4));
        Offers::propose($open, self::B, 'Його', 900, $this->inDays(2));
        $c = Offers::propose($open, self::C, 'Відкликана', 900, $this->inDays(2));
        Offers::withdraw($c, self::C);
        Offers::propose($taken, self::A, 'A', 500, $this->inDays(1));
        $won = Offers::propose($taken, self::B, 'B', 500, $this->inDays(1));
        Offers::choose($taken, self::CLIENT, $won, $this->price($won));

        $feed = Offers::feed(self::A, 1);
        $byId = array_column($feed['items'], null, 'order_id');
        foreach ([$own, $mode2, $waiting, $draft] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $byId);
        }
        $this->assertArrayHasKey($open, $byId);
        $this->assertArrayHasKey($newer, $byId);
        $this->assertArrayHasKey($taken, $byId);

        // нові зверху (за published_at)
        $ours = array_values(array_filter(array_column($feed['items'], 'order_id'), fn($id) => in_array($id, [$open, $newer, $taken], true)));
        $this->assertSame([$newer, $open, $taken], $ours);

        $card = $byId[$open];
        $product = Products::visible()[0]['name'];
        $this->assertSame($product, $card['product_name']);
        $this->assertSame($product, $card['title']);
        $this->assertSame([['q' => 'Сторін', 'a' => 'дві'], ['q' => 'Стиль', 'a' => 'строго']], $card['preview']);
        $this->assertSame(2, $card['offers']);   // відкликана не рахується
        $this->assertFalse($card['chosen_other']);
        $this->assertSame($mine, $card['mine']['id']);
        $this->assertSame(OfferStatus::SENT, $card['mine']['status']);
        $this->assertSame(1000, $card['mine']['price_designer']);
        $this->assertSame($this->inDays(4), $card['mine']['deadline']);
        $this->assertNull($byId[$newer]['mine']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $card['published_at']);

        $this->assertTrue($byId[$taken]['chosen_other']);
        $this->assertSame(1, $byId[$taken]['offers']);   // обрана

        // хто не пропонував — замовлення в роботі не бачить; обраний — теж (воно в «Мої пропозиції → В роботі»)
        $this->assertNotContains($taken, array_column(Offers::feed(self::C, 1)['items'], 'order_id'));
        $this->assertNotContains($taken, array_column(Offers::feed(self::B, 1)['items'], 'order_id'));
        // свої замовлення клієнта-дизайнера бачать інші
        $this->assertContains($own, array_column(Offers::feed(self::C, 1)['items'], 'order_id'));

        // не схвалений — порожньо
        $this->assertSame(['items' => [], 'total' => 0, 'pages' => 1], Offers::feed(self::PENDING, 1));
        $this->assertSame(24, Offers::PER_PAGE);
        $this->assertGreaterThanOrEqual(3, $feed['total']);
        $this->assertSame(max(1, (int)ceil($feed['total'] / Offers::PER_PAGE)), $feed['pages']);
    }

    public function test_feed_new_count_and_seen(): void
    {
        $before = Offers::feedNewCount(self::A);      // feed_seen_at порожній — нові всі
        $old = $this->order(self::CLIENT, OrderStatus::COLLECTING, 1, null, date('Y-m-d H:i:s', time() - 3600));
        $this->order(self::A);                        // своє — не рахується
        $this->order(self::CLIENT, OrderStatus::COLLECTING, 2);   // режим 2 — не рахується
        $this->assertSame($before + 1, Offers::feedNewCount(self::A));
        $this->assertSame(0, Offers::feedNewCount(self::PENDING));

        Offers::markFeedSeen(self::A);
        $this->assertNotNull(Sql::value('SELECT feed_seen_at FROM design_designers WHERE customer_id = ?', [self::A]));
        $this->assertSame(0, Offers::feedNewCount(self::A));
        $byId = array_column(Offers::feed(self::A, 1)['items'], null, 'order_id');
        $this->assertFalse($byId[$old]['is_new']);

        // дизайнер дивився стрічку пів години тому: старий бриф — переглянутий, свіжий — новий
        Sql::exec('UPDATE design_designers SET feed_seen_at = NOW() - INTERVAL 30 MINUTE WHERE customer_id = ?', [self::A]);
        // на спільній БД dev за ці пів години могли зʼявитися справжні брифи — рахуємо від них
        $baseline = Offers::feedNewCount(self::A);
        $fresh = $this->order();
        $this->assertSame($baseline + 1, Offers::feedNewCount(self::A));
        $byId = array_column(Offers::feed(self::A, 1)['items'], null, 'order_id');
        $this->assertTrue($byId[$fresh]['is_new']);
        $this->assertFalse($byId[$old]['is_new']);
    }

    public function test_access_matrix(): void
    {
        $orderId = $this->order();
        $a = Offers::propose($orderId, self::A, 'A', 500, $this->inDays(1));
        $b = Offers::propose($orderId, self::B, 'B', 600, $this->inDays(1));
        $c = Offers::propose($orderId, self::C, 'C', 600, $this->inDays(1));
        Offers::withdraw($c, self::C);

        $client = Offers::access($a, self::CLIENT);
        $this->assertSame(Messages::ROLE_CLIENT, $client['role']);
        $this->assertTrue($client['write']);
        $this->assertSame($a, $client['offer']['id']);
        $designer = Offers::access($a, self::A);
        $this->assertSame(Messages::ROLE_DESIGNER, $designer['role']);
        $this->assertTrue($designer['write']);

        $this->assertNull(Offers::access($a, self::B));      // інший дизайнер того ж замовлення
        $this->assertNull(Offers::access($a, self::PENDING));
        $this->assertNull(Offers::access(0, self::CLIENT));

        // відкликана — лише читання
        $this->assertFalse(Offers::access($c, self::CLIENT)['write']);
        $this->assertFalse(Offers::access($c, self::C)['write']);

        // запрошення (режим 2) без відповіді — лише читання для обох: до «Беру» листування немає
        $invitedOrder = $this->order(self::CLIENT, OrderStatus::WAITING_DESIGNER, 2);
        $inv = Sql::insert('design_offers', ['order_id' => $invitedOrder, 'designer_id' => self::A, 'status' => OfferStatus::INVITED]);
        $this->assertFalse(Offers::access($inv, self::CLIENT)['write']);
        $this->assertFalse(Offers::access($inv, self::A)['write']);
        $this->assertSame(Messages::ROLE_DESIGNER, Offers::access($inv, self::A)['role']);

        // після вибору — лише обрана
        Offers::choose($orderId, self::CLIENT, $b, $this->price($b));
        $this->assertTrue(Offers::access($b, self::CLIENT)['write']);
        $this->assertTrue(Offers::access($b, self::B)['write']);
        $this->assertFalse(Offers::access($a, self::CLIENT)['write']);
        $this->assertFalse(Offers::access($a, self::A)['write']);
        $this->assertSame(Messages::ROLE_DESIGNER, Offers::access($a, self::A)['role']);

        // скасоване замовлення — лише читання навіть для обраної
        Sql::exec('UPDATE design_orders SET status = ? WHERE id = ?', [OrderStatus::CANCELLED, $orderId]);
        $this->assertFalse(Offers::access($b, self::CLIENT)['write']);
        $this->assertFalse(Offers::access($b, self::B)['write']);
    }

    /** Дизайнер більше не схвалений (заблокований, на розгляді): листування лише для читання з обох боків, навіть обране */
    public function test_access_unapproved_designer_read_only(): void
    {
        $orderId = $this->order();
        $a = Offers::propose($orderId, self::A, 'A', 500, $this->inDays(1));
        $b = Offers::propose($orderId, self::B, 'B', 600, $this->inDays(1));
        Messages::add($a, self::A, Messages::ROLE_DESIGNER, 'Привіт');

        foreach ([DesignerStatus::BLOCKED, DesignerStatus::PENDING] as $status) {
            Sql::exec('UPDATE design_designers SET status = ? WHERE customer_id = ?', [$status, self::A]);
            $designer = Offers::access($a, self::A);
            $this->assertNotNull($designer, 'читати може');
            $this->assertSame(Messages::ROLE_DESIGNER, $designer['role']);
            $this->assertFalse($designer['write']);
            $client = Offers::access($a, self::CLIENT);
            $this->assertSame(Messages::ROLE_CLIENT, $client['role']);
            $this->assertFalse($client['write']);
        }
        // профіль дизайнера видалено — так само
        Sql::exec('DELETE FROM design_designers WHERE customer_id = ?', [self::A]);
        $this->assertFalse(Offers::access($a, self::A)['write']);
        $this->assertFalse(Offers::access($a, self::CLIENT)['write']);

        // інша пропозиція того ж замовлення не зачеплена
        $this->assertTrue(Offers::access($b, self::CLIENT)['write']);
        $this->assertTrue(Offers::access($b, self::B)['write']);

        // обраний, а потім заблокований — теж лише читання
        Offers::choose($orderId, self::CLIENT, $b, $this->price($b));
        $this->assertTrue(Offers::access($b, self::CLIENT)['write']);
        Sql::exec('UPDATE design_designers SET status = ? WHERE customer_id = ?', [DesignerStatus::BLOCKED, self::B]);
        $this->assertFalse(Offers::access($b, self::CLIENT)['write']);
        $this->assertFalse(Offers::access($b, self::B)['write']);
    }

    /** Пропозиція з іншого замовлення того ж клієнта: вибір відхилено, у жодному із замовлень нічого не змінилося */
    public function test_choose_offer_of_other_own_order_refused(): void
    {
        $x = $this->order();
        $y = $this->order();
        $ax = Offers::propose($x, self::A, 'A на X', 500, $this->inDays(1));
        $by = Offers::propose($y, self::B, 'B на Y', 600, $this->inDays(1));
        Notify::$sent = [];

        $this->assertError('Пропозиція недоступна', fn() => Offers::choose($x, self::CLIENT, $by, $this->price($by)));

        foreach ([$x, $y] as $id) {
            $order = $this->orderRow($id);
            $this->assertSame(OrderStatus::COLLECTING, (int)$order['status']);
            $this->assertNull($order['chosen_offer_id']);
            $this->assertNull($order['chosen_at']);
        }
        $this->assertSame(OfferStatus::SENT, (int)$this->offerRow($by)['status']);
        $this->assertSame(OfferStatus::SENT, (int)$this->offerRow($ax)['status']);
        $this->assertSame([], Notify::$sent);
    }

    public function test_mine_tabs(): void
    {
        $o1 = $this->order();
        $o2 = $this->order();
        $o3 = $this->order();
        $o4 = $this->order(self::CLIENT, OrderStatus::WAITING_DESIGNER, 2);
        $o5 = $this->order();

        $sent = Offers::propose($o1, self::A, 'Надіслана', 500, $this->inDays(1));
        $chosen = Offers::propose($o2, self::A, 'Обрана', 800, $this->inDays(2));
        $withdrawn = Offers::propose($o3, self::A, 'Відкликана', 500, $this->inDays(1));
        Offers::withdraw($withdrawn, self::A);
        $invited = Sql::insert('design_offers', ['order_id' => $o4, 'designer_id' => self::A, 'status' => OfferStatus::INVITED]);
        $lost = Offers::propose($o5, self::A, 'Програла', 500, $this->inDays(1));
        $winner = Offers::propose($o5, self::B, 'Виграла', 400, $this->inDays(1));
        Offers::choose($o5, self::CLIENT, $winner, $this->price($winner));
        Offers::choose($o2, self::CLIENT, $chosen, $this->price($chosen));

        $mine = Offers::mine(self::A);
        $offers = array_column($mine['offers'], null, 'id');
        $this->assertEqualsCanonicalizing([$sent, $withdrawn, $invited, $lost], array_keys($offers));
        $this->assertSame([$chosen], array_column($mine['work'], 'id'));

        $this->assertSame(OfferStatus::OTHER_CHOSEN, $offers[$lost]['status']);
        $this->assertSame($o1, $offers[$sent]['order_id']);
        $this->assertSame(500, $offers[$sent]['price_designer']);
        $this->assertSame('Клієнт', $offers[$sent]['client']);   // фіктивного клієнта немає в customers
        $this->assertSame(Products::visible()[0]['name'], $offers[$sent]['title']);

        $work = $mine['work'][0];
        $this->assertSame($o2, $work['order_id']);
        $this->assertSame(OrderStatus::IN_WORK, $work['order_status']);
        $this->assertSame(800, $work['price_designer']);

        $this->assertSame(['offers' => [], 'work' => []], Offers::mine(self::C));
    }
}
