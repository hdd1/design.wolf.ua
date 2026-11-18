<?php

use Design\Customers;
use Design\Notify;
use Design\Offers;
use Design\OfferStatus;
use Design\OrderStatus;
use Design\Products;
use Design\Settings;
use Design\Sql;
use PHPUnit\Framework\TestCase;

/**
 * Крон «бриф без пропозицій». Куратор — справжнього клієнта DESIGN_TEST_CUSTOMER_ID (якщо в нього є куратор),
 * шлях «куратора немає» — фіктивний клієнт 999999741 (рядка в customers нема). Замовлення — автоінкремент, published_at
 * відсуваємо SQL-ом. Справжні старі брифи dev-бази (якщо є) тест не чіпає: перед запуском їх «заморожено»,
 * після — notified_no_offers_at повертається в NULL
 * @group db
 */
final class NoOffersCronTest extends TestCase
{
    private const NOBODY = 999999741;     // клієнт без куратора
    private const DESIGNER = 999999742;

    /** @var list<int> */
    private array $created = [];
    /** @var list<int> справжні замовлення, які теж підпадають під крон і будуть оброблені тестом */
    private array $foreign = [];
    private string $log;
    private string|false $oldLog;

    protected function setUp(): void
    {
        Notify::$sent = [];
        Settings::reset();
        $this->log = tempnam(sys_get_temp_dir(), 'noffers');
        $this->oldLog = ini_set('error_log', $this->log);
        $this->foreign = array_map('intval', array_column(Sql::all(
            'SELECT id FROM design_orders WHERE status = ? AND mode = 1 AND notified_no_offers_at IS NULL AND published_at < NOW() - INTERVAL ' . Settings::noOffersHours() . ' HOUR',
            [OrderStatus::COLLECTING]), 'id'));
    }

    protected function tearDown(): void
    {
        $orders = $this->created === [] ? '0' : implode(',', $this->created);
        Sql::exec("DELETE FROM design_offers WHERE order_id IN ($orders)");
        Sql::exec("DELETE FROM design_orders WHERE id IN ($orders)");
        if ($this->foreign !== []) {
            Sql::exec('UPDATE design_orders SET notified_no_offers_at = NULL WHERE id IN (' . implode(',', $this->foreign) . ')');
        }
        Notify::$sent = [];
        ini_set('error_log', $this->oldLog === false ? '' : $this->oldLog);
        @unlink($this->log);
    }

    private function realClient(): int
    {
        $id = (int)getenv('DESIGN_TEST_CUSTOMER_ID');
        if ($id <= 0) self::markTestSkipped('DESIGN_TEST_CUSTOMER_ID не задан');
        return $id;
    }

    private function realClientWithCurator(): array
    {
        $id = $this->realClient();
        $curator = (int)Sql::value('SELECT curator_id FROM customers WHERE id = ?', [$id]);
        if ($curator <= 0) self::markTestSkipped('У DESIGN_TEST_CUSTOMER_ID немає куратора');
        return [$id, $curator];
    }

    /** Замовлення «збір пропозицій», опубліковане $hoursAgo годин тому */
    private function order(int $customerId, float $hoursAgo, int $status = OrderStatus::COLLECTING, int $mode = 1): int
    {
        $id = Sql::insert('design_orders', [
            'customer_id' => $customerId,
            'product_id' => Products::visible()[0]['id'],
            'mode' => $mode,
            'status' => $status,
            'answers' => json_encode([['q' => 'Розмір', 'a' => '90×50']], JSON_UNESCAPED_UNICODE),
        ]);
        $this->created[] = $id;
        Sql::exec('UPDATE design_orders SET published_at = NOW() - INTERVAL ? MINUTE WHERE id = ?', [(int)round($hoursAgo * 60), $id]);
        return $id;
    }

    private function offer(int $orderId, int $status): void
    {
        Sql::insert('design_offers', ['order_id' => $orderId, 'designer_id' => self::DESIGNER, 'status' => $status]);
    }

    private function notified(int $orderId): ?string
    {
        $v = Sql::value('SELECT notified_no_offers_at FROM design_orders WHERE id = ?', [$orderId]);
        return $v === null || $v === false ? null : (string)$v;
    }

    /** @return list<array> сповіщення, що стосуються замовлень тесту */
    private function sentFor(array $orderIds): array
    {
        return array_values(array_filter(Notify::$sent, static function (array $p) use ($orderIds): bool {
            foreach ($orderIds as $id) if (str_contains($p['msg'], 'бриф №' . $id . ' ')) return true;
            return false;
        }));
    }

    public function test_only_old_unanswered_mode1_once(): void
    {
        [$client, $curator] = $this->realClientWithCurator();
        $old = $this->order($client, 30);                                   // кандидат
        $declinedOnly = $this->order($client, 30);                          // лише відмова/відкликання — теж «без пропозицій»
        $this->offer($declinedOnly, OfferStatus::DECLINED);
        $fresh = $this->order($client, 1);                                  // ще рано
        $withOffer = $this->order($client, 30);
        $this->offer($withOffer, OfferStatus::SENT);
        $withChosen = $this->order($client, 30);
        $this->offer($withChosen, OfferStatus::CHOSEN);
        $mode2 = $this->order($client, 30, OrderStatus::COLLECTING, 2);
        $draft = $this->order($client, 30, OrderStatus::DRAFT);
        $inWork = $this->order($client, 30, OrderStatus::IN_WORK);

        $n = Offers::notifyNoOffers();
        $this->assertGreaterThanOrEqual(2, $n);
        $this->assertSame(2 + count($this->foreign), $n, 'лише два замовлення тесту (плюс справжні старі брифи dev-бази, якщо є)');

        $this->assertNotNull($this->notified($old));
        $this->assertNotNull($this->notified($declinedOnly));
        foreach ([$fresh, $withOffer, $withChosen, $mode2, $draft, $inWork] as $id) {
            $this->assertNull($this->notified($id), "замовлення $id не мало потрапити під крон");
        }

        $sent = $this->sentFor([$old, $declinedOnly]);
        $this->assertCount(2, $sent);
        $this->assertCount(0, $this->sentFor([$fresh, $withOffer, $withChosen, $mode2, $draft, $inWork]));
        $product = (string)Sql::value('SELECT name FROM design_products WHERE id = ?', [Sql::value('SELECT product_id FROM design_orders WHERE id = ?', [$old])]);
        $name = Customers::header($client)['name'];
        $first = $this->sentFor([$old])[0];
        $this->assertSame($curator, $first['user_id']);
        $this->assertArrayNotHasKey('customer_id', $first);
        $this->assertSame('Майстерня дизайну: бриф №' . $old . ' (' . $product . ') клієнта ' . $name . ' (id ' . $client . ') добу без пропозицій (' . date('d.m H:i') . ')', $first['msg']);
        $this->assertSame('Майстерня дизайну: бриф №' . $old . ' без пропозицій. design.wolf.ua', $first['msgSMS']);

        // повторний запуск: тих самих замовлень вже немає, куратору нічого не йде
        Notify::$sent = [];
        Offers::notifyNoOffers();
        $this->assertCount(0, $this->sentFor([$old, $declinedOnly]));
    }

    public function test_curator_zero_logged_not_sent(): void
    {
        $id = $this->order(self::NOBODY, 30);

        $n = Offers::notifyNoOffers();

        $this->assertGreaterThanOrEqual(1, $n);
        $this->assertCount(0, $this->sentFor([$id]), 'куратора немає — не шлемо');
        $this->assertNotNull($this->notified($id), 'позначено, щоб не повторювати щогодини');
        $log = (string)file_get_contents($this->log);
        $this->assertStringContainsString('немає куратора', $log);
        $this->assertStringContainsString('бриф №' . $id . ' ', $log);

        file_put_contents($this->log, '');
        Offers::notifyNoOffers();
        $this->assertStringNotContainsString('бриф №' . $id . ' ', (string)file_get_contents($this->log));
    }

    public function test_hours_threshold_from_settings(): void
    {
        $id = $this->order(self::NOBODY, 23);
        Offers::notifyNoOffers();
        $this->assertNull($this->notified($id), '23 год < 24 год');
    }
}
