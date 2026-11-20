<?php

use Design\Customers;
use Design\Notify;
use Design\Offers;
use Design\OfferStatus;
use Design\OrderStatus;
use Design\Products;
use Design\Rework;
use Design\Sql;
use PHPUnit\Framework\TestCase;

/**
 * Крон «термін минув, а купівлі немає». Клієнт — справжній DESIGN_TEST_CUSTOMER_ID (його куратор), «куратора немає» — фіктивний
 * 999999751. Замовлення й пропозиції (обрана — chosen_offer_id) тест вставляє сам; справжні замовлення dev-бази, що теж
 * підпадають під крон, після тесту повертаються в notified_deadline_at = NULL. Сповіщення — DESIGN_NOTIFY=off (Notify::$sent)
 * @group db
 */
final class DeadlineCronTest extends TestCase
{
    private const NOBODY = 999999751;
    private const DESIGNER = 999999743;

    /** @var list<int> */
    private array $created = [];
    /** @var list<int> справжні замовлення, які теж підпадають під крон */
    private array $foreign = [];
    private string $log;
    private string|false $oldLog;

    protected function setUp(): void
    {
        Notify::$sent = [];
        $this->log = tempnam(sys_get_temp_dir(), 'ndeadline');
        $this->oldLog = ini_set('error_log', $this->log);
        $this->foreign = array_map('intval', array_column(Sql::all(
            'SELECT o.id FROM design_orders o JOIN design_offers f ON f.id = o.chosen_offer_id
             WHERE o.status IN (?, ?) AND o.notified_deadline_at IS NULL AND f.deadline < CURDATE()',
            [OrderStatus::IN_WORK, OrderStatus::REWORK]), 'id'));
    }

    protected function tearDown(): void
    {
        $orders = $this->created === [] ? '0' : implode(',', $this->created);
        Sql::exec("DELETE m FROM design_messages m JOIN design_offers f ON f.id = m.offer_id WHERE f.order_id IN ($orders)");
        Sql::exec("DELETE FROM design_offers WHERE order_id IN ($orders)");
        Sql::exec("DELETE FROM design_orders WHERE id IN ($orders)");
        if ($this->foreign !== []) {
            Sql::exec('UPDATE design_orders SET notified_deadline_at = NULL WHERE id IN (' . implode(',', $this->foreign) . ')');
        }
        Notify::$sent = [];
        ini_set('error_log', $this->oldLog === false ? '' : $this->oldLog);
        @unlink($this->log);
    }

    private function realClientWithCurator(): array
    {
        $id = (int)getenv('DESIGN_TEST_CUSTOMER_ID');
        if ($id <= 0) self::markTestSkipped('DESIGN_TEST_CUSTOMER_ID не задан');
        $curator = (int)Sql::value('SELECT curator_id FROM customers WHERE id = ?', [$id]);
        if ($curator <= 0) self::markTestSkipped('У DESIGN_TEST_CUSTOMER_ID немає куратора');
        return [$id, $curator];
    }

    /** Замовлення зі статусом і обраною пропозицією, дата якої — сьогодні + $days днів (SQL-ом, як CURDATE() крону) */
    private function order(int $customerId, int $status, ?int $days, bool $chosen = true): int
    {
        $id = Sql::insert('design_orders', [
            'customer_id' => $customerId,
            'product_id' => Products::visible()[0]['id'],
            'mode' => 1,
            'status' => $status,
            'answers' => json_encode([['q' => 'Розмір', 'a' => '90×50']], JSON_UNESCAPED_UNICODE),
        ]);
        $this->created[] = $id;
        if ($chosen) {
            $offer = Sql::insert('design_offers', [
                'order_id' => $id, 'designer_id' => self::DESIGNER, 'status' => OfferStatus::CHOSEN,
                'text' => 'Зроблю', 'price_designer' => 1000, 'markup_percent' => 20, 'price_client' => 1200,
            ]);
            Sql::exec('UPDATE design_offers SET deadline = ' . ($days === null ? 'NULL' : 'CURDATE() + INTERVAL ' . $days . ' DAY') . ' WHERE id = ?', [$offer]);
            Sql::exec('UPDATE design_orders SET chosen_offer_id = ? WHERE id = ?', [$offer, $id]);
        }
        return $id;
    }

    private function notified(int $orderId): ?string
    {
        $v = Sql::value('SELECT notified_deadline_at FROM design_orders WHERE id = ?', [$orderId]);
        return $v === null || $v === false ? null : (string)$v;
    }

    /** @return list<array> сповіщення про замовлення тесту */
    private function sentFor(array $orderIds): array
    {
        return array_values(array_filter(Notify::$sent, static function (array $p) use ($orderIds): bool {
            foreach ($orderIds as $id) if (str_contains($p['msg'], '№' . $id . ' —')) return true;
            return false;
        }));
    }

    public function test_only_overdue_in_work_or_rework_once(): void
    {
        [$client, $curator] = $this->realClientWithCurator();
        $inWork = $this->order($client, OrderStatus::IN_WORK, -1);          // кандидат
        $rework = $this->order($client, OrderStatus::REWORK, -3);           // кандидат
        $today = $this->order($client, OrderStatus::IN_WORK, 0);            // термін сьогодні — ще не минув
        $future = $this->order($client, OrderStatus::IN_WORK, 2);
        $noDate = $this->order($client, OrderStatus::IN_WORK, null);
        $noOffer = $this->order($client, OrderStatus::IN_WORK, null, false); // обраної пропозиції немає
        $purchased = $this->order($client, OrderStatus::PURCHASED, -1);     // куплено — термін уже не важливий
        $cancelled = $this->order($client, OrderStatus::CANCELLED, -1);
        $collecting = $this->order($client, OrderStatus::COLLECTING, -1);

        $n = Offers::notifyDeadline();
        $this->assertSame(2 + count($this->foreign), $n, 'лише два замовлення тесту (плюс справжні прострочені dev-бази, якщо є)');

        $this->assertNotNull($this->notified($inWork));
        $this->assertNotNull($this->notified($rework));
        foreach ([$today, $future, $noDate, $noOffer, $purchased, $cancelled, $collecting] as $id) {
            $this->assertNull($this->notified($id), "замовлення $id не мало потрапити під крон");
            $this->assertCount(0, $this->sentFor([$id]));
        }

        $name = Customers::header($client)['name'];
        $sent = $this->sentFor([$inWork]);
        $this->assertCount(1, $sent);
        $this->assertSame($curator, $sent[0]['user_id']);
        $this->assertArrayNotHasKey('customer_id', $sent[0]);
        $this->assertSame('Майстерня дизайну: №' . $inWork . ' — термін минув, макети не куплено (клієнт ' . $name . ', id ' . $client . ')', $sent[0]['msg']);
        $this->assertSame('Майстерня дизайну: №' . $inWork . ' — термін минув, макети не куплено. design.wolf.ua', $sent[0]['msgSMS']);
        $this->assertCount(1, $this->sentFor([$rework]));

        // повтор: відмітка стоїть — куратору нічого не йде
        Notify::$sent = [];
        $this->assertSame(0, Offers::notifyDeadline());
        $this->assertCount(0, $this->sentFor([$inWork, $rework]));
    }

    public function test_after_accepted_rework_notifies_again(): void
    {
        [$client] = $this->realClientWithCurator();
        $id = $this->order($client, OrderStatus::PURCHASED, -2);
        Offers::notifyDeadline();
        $this->assertNull($this->notified($id), 'у статусі «Куплено» крон мовчить');

        // «Доопрацювання» з минулою датою: повідомлено один раз
        Sql::exec('UPDATE design_orders SET status = ? WHERE id = ?', [OrderStatus::REWORK, $id]);
        Offers::notifyDeadline();
        $this->assertNotNull($this->notified($id));
        $this->assertCount(1, $this->sentFor([$id]));
        Notify::$sent = [];

        // дизайнер запропонував доопрацювання (минула дата — лише щоб побачити повтор), клієнт прийняв: Rework::accept скидає відмітку
        Sql::exec('UPDATE design_orders SET status = ?, rework_price_designer = 1500, rework_price_client = 1800,
                   rework_deadline = CURDATE() - INTERVAL 1 DAY, rework_proposed_at = NOW() WHERE id = ?', [OrderStatus::PURCHASED, $id]);
        Rework::accept($id, $client);
        $this->assertNull($this->notified($id), 'прийнята доробка скидає відмітку');
        Offers::notifyDeadline();
        $this->assertNotNull($this->notified($id));
        $this->assertCount(1, $this->sentFor([$id]), 'після прийнятої доробки — знову, один раз');
    }

    public function test_curator_zero_logged_not_sent(): void
    {
        $id = $this->order(self::NOBODY, OrderStatus::IN_WORK, -1);

        Offers::notifyDeadline();

        $this->assertCount(0, $this->sentFor([$id]), 'куратора немає — не шлемо');
        $this->assertNotNull($this->notified($id), 'позначено, щоб не повторювати щогодини');
        $this->assertStringContainsString('немає куратора', (string)file_get_contents($this->log));
        $this->assertStringContainsString('№' . $id . ' —', (string)file_get_contents($this->log));

        file_put_contents($this->log, '');
        Offers::notifyDeadline();
        $this->assertStringNotContainsString('№' . $id . ' —', (string)file_get_contents($this->log));
    }

    public function test_claim_is_conditional(): void
    {
        // Паралельний запуск: відмітку вже поставив інший процес — другий нічого не шле
        $id = $this->order(self::NOBODY, OrderStatus::IN_WORK, -1);
        Sql::exec('UPDATE design_orders SET notified_deadline_at = NOW() WHERE id = ?', [$id]);
        Offers::notifyDeadline();
        $this->assertStringNotContainsString('№' . $id . ' —', (string)file_get_contents($this->log));
    }
}
