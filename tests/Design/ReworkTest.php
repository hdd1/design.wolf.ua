<?php

use Design\DesignerException;
use Design\DesignerStatus;
use Design\Messages;
use Design\Notify;
use Design\OfferStatus;
use Design\OrderStatus;
use Design\Products;
use Design\Purchases;
use Design\Rework;
use Design\Sql;
use PHPUnit\Framework\TestCase;

/**
 * Доопрацювання (етап 5, Task 6): дизайнер пропонує нову повну ціну й дату, клієнт приймає / відмовляється / скасовує.
 * Справжні гроші на dev лише в тестах купівлі на доопрацюванні: клієнт DESIGN_TEST_CUSTOMER_ID (29), дизайнер
 * DESIGN_TEST_DESIGNER_ID (25). Тест ставить їм потрібні inv_sum / credit / overdraft_*, а в tearDown (і при фатальній
 * помилці — функцією завершення) повертає все як було, видаляє свої рядки transaction (id > max до тесту, '[design]%'),
 * свої design_* і рядок design_designers 25, якщо створив його сам (є чужий — пропуск).
 * Замовлення «Куплено» з обраною пропозицією (CHOSEN) і макети (рядки design_files без файлів) тест вставляє сам.
 * @group db
 */
final class ReworkTest extends TestCase
{
    private const NB = "\u{00A0}";
    private const MONEY_COLS = ['inv_sum', 'credit', 'credit_ra', 'temp_credit_ra', 'parent_id', 'overdraft_start', 'overdraft_days'];

    private int $client;
    private int $designer;
    /** @var array<int, array> рядки customers до тесту */
    private array $saved = [];
    private int $maxTransaction = 0;
    /** @var list<int> */
    private array $orders = [];
    private bool $ownsDesigner = false;

    protected function setUp(): void
    {
        $this->client = (int)getenv('DESIGN_TEST_CUSTOMER_ID');
        $this->designer = (int)getenv('DESIGN_TEST_DESIGNER_ID');
        if ($this->client <= 0 || $this->designer <= 0) self::markTestSkipped('DESIGN_TEST_CUSTOMER_ID / DESIGN_TEST_DESIGNER_ID не задані');
        if (Sql::value('SELECT customer_id FROM design_designers WHERE customer_id = ?', [$this->designer]) !== null) {
            self::markTestSkipped("у клієнта {$this->designer} вже є профіль дизайнера — тест не чіпає справжні дані");
        }
        Notify::$sent = [];
        $this->maxTransaction = (int)Sql::value('SELECT COALESCE(MAX(id), 0) FROM transaction');
        foreach ([$this->client, $this->designer] as $id) {
            $row = Sql::one('SELECT ' . implode(', ', self::MONEY_COLS) . ' FROM customers WHERE id = ?', [$id]);
            if ($row === null) self::markTestSkipped("Клієнта $id немає в customers");
            $this->saved[$id] = $row;
        }
        register_shutdown_function(function (): void {
            if (!$this->saved) return;
            foreach ($this->moneySteps() as $step) {
                try {
                    $step();
                } catch (\Throwable) {
                }
            }
            $this->saved = [];
        });
        Sql::insert('design_designers', ['customer_id' => $this->designer, 'status' => DesignerStatus::APPROVED, 'name' => 'Тест Дизайнер доопрацювання']);
        $this->ownsDesigner = true;
    }

    /** @return list<\Closure(): void> */
    private function moneySteps(): array
    {
        return [
            static function (): void {
                if (Sql::pdo()->inTransaction()) Sql::pdo()->rollBack();
            },
            function (): void {
                foreach ($this->saved as $id => $row) {
                    Sql::exec('UPDATE customers SET ' . implode(', ', array_map(static fn($c) => "$c = ?", self::MONEY_COLS)) . ' WHERE id = ?',
                        [...array_values($row), $id]);
                }
            },
            function (): void {
                if ($this->saved) {
                    Sql::exec("DELETE FROM transaction WHERE id > ? AND paymethod LIKE '[design]%' AND customer_id IN (?, ?)",
                        [$this->maxTransaction, $this->client, $this->designer]);
                }
            },
        ];
    }

    protected function tearDown(): void
    {
        $steps = [
            ...$this->moneySteps(),
            function (): void {
                if (!$this->orders) return;
                $in = implode(',', array_map('intval', $this->orders));
                Sql::exec("DELETE FROM design_purchases WHERE order_id IN ($in)");
                Sql::exec("DELETE FROM design_files WHERE order_id IN ($in)");
                Sql::exec("DELETE m FROM design_messages m JOIN design_offers f ON f.id = m.offer_id WHERE f.order_id IN ($in)");
                Sql::exec("DELETE FROM design_offers WHERE order_id IN ($in)");
                Sql::exec("DELETE FROM design_orders WHERE id IN ($in)");
            },
            function (): void {
                if ($this->ownsDesigner) Sql::exec('DELETE FROM design_designers WHERE customer_id = ?', [$this->designer]);
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
        $this->orders = [];
        $this->saved = [];
        Notify::$sent = [];
        if ($error !== null) throw $error;
    }

    // ---------- підготовка ----------

    private function money(int $id, int $invSum, int $credit = 0): void
    {
        Sql::exec('UPDATE customers SET inv_sum = ?, credit = ?, credit_ra = 0, temp_credit_ra = 0, parent_id = 0,
                   overdraft_start = NULL, overdraft_days = NULL WHERE id = ?', [$invSum, $credit, $id]);
    }

    /**
     * Куплене замовлення: обрана пропозиція (D, P, k 25 %) = сплаченому, один куплений макет
     * @return array{0:int, 1:int} [orderId, offerId]
     */
    private function purchased(int $p = 1000, int $d = 800, string $deadline = '2026-11-10', int $status = OrderStatus::PURCHASED): array
    {
        $orderId = Sql::insert('design_orders', [
            'customer_id' => $this->client,
            'product_id' => Products::visible()[0]['id'],
            'status' => $status,
            'title' => 'Тест доопрацювання',
            'answers' => '[]',
            'paid_client' => $p,
            'paid_designer' => $d,
        ]);
        $this->orders[] = $orderId;
        $offerId = Sql::insert('design_offers', [
            'order_id' => $orderId, 'designer_id' => $this->designer, 'status' => OfferStatus::CHOSEN,
            'text' => 'Зроблю', 'price_designer' => $d, 'markup_percent' => 25, 'price_client' => $p, 'deadline' => $deadline,
        ]);
        Sql::exec('UPDATE design_orders SET chosen_offer_id = ?, chosen_at = NOW(), purchased_at = NOW(), notified_deadline_at = NOW() WHERE id = ?',
            [$offerId, $orderId]);
        $this->layout($orderId, 'Куплений', 999999);
        return [$orderId, $offerId];
    }

    private function layout(int $orderId, string $label, ?int $purchaseId = null): int
    {
        return Sql::insert('design_files', [
            'order_id' => $orderId, 'label' => $label, 'original_name' => "$label.pdf", 'file' => bin2hex(random_bytes(4)) . '.pdf',
            'size' => 1000, 'md5' => md5($label), 'status' => 1, 'purchase_id' => $purchaseId,
        ]);
    }

    private function order(int $orderId): array
    {
        return Sql::one('SELECT * FROM design_orders WHERE id = ?', [$orderId]);
    }

    private function offer(int $offerId): array
    {
        $r = Sql::one('SELECT price_client, price_designer, deadline FROM design_offers WHERE id = ?', [$offerId]);
        return [(int)$r['price_client'], (int)$r['price_designer'], (string)$r['deadline']];
    }

    /** @return list<array{text:string, data:?array}> системні повідомлення пропозиції */
    private function system(int $offerId): array
    {
        return array_map(static fn(array $m): array => ['text' => $m['text'], 'data' => $m['data']],
            array_values(array_filter(Messages::list($offerId), static fn(array $m): bool => $m['role'] === Messages::ROLE_SYSTEM)));
    }

    private function assertRefused(callable $fn, string $msg): void
    {
        try {
            $fn();
            $this->fail('очікувалась відмова: ' . $msg);
        } catch (DesignerException $e) {
            $this->assertSame($msg, $e->getMessage());
        }
    }

    private static function tomorrow(): string
    {
        return date('Y-m-d', strtotime('+1 day'));
    }

    // ---------- тести ----------

    public function test_propose_rules(): void
    {
        [$orderId, $offerId] = $this->purchased();
        $d = self::tomorrow();

        $this->assertRefused(fn() => Rework::propose($orderId, $this->client, 900, $d), 'Запропонувати доопрацювання може лише обраний дизайнер');
        $this->assertRefused(fn() => Rework::propose($orderId, $this->designer, 799, $d), 'Сума — не менше вже нарахованих 800 грн');
        $this->assertRefused(fn() => Rework::propose($orderId, $this->designer, 900, date('Y-m-d', strtotime('-1 day'))), 'Дата — не раніше сьогодні');
        $this->assertRefused(fn() => Rework::propose($orderId, $this->designer, 900, '2026-13-01'), 'Вкажіть дату «Готово до»');
        $this->assertRefused(fn() => Rework::propose($orderId, $this->designer, 1000001, $d), 'Сума — не більше 1 000 000 грн');
        $this->assertNull($this->order($orderId)['rework_price_client'], 'відмови нічого не записали');

        // Не «Куплено»: в роботі / доопрацюванні — не можна
        [$inWork] = $this->purchased(1000, 800, '2026-11-10', OrderStatus::IN_WORK);
        $this->assertRefused(fn() => Rework::propose($inWork, $this->designer, 900, $d), 'Доопрацювання можна запропонувати лише для купленого замовлення');
        [$rework] = $this->purchased(1000, 800, '2026-11-10', OrderStatus::REWORK);
        $this->assertRefused(fn() => Rework::propose($rework, $this->designer, 900, $d), 'Доопрацювання можна запропонувати лише для купленого замовлення');

        // Заблокований дизайнер — ні
        Sql::exec('UPDATE design_designers SET status = ? WHERE customer_id = ?', [DesignerStatus::BLOCKED, $this->designer]);
        $this->assertRefused(fn() => Rework::propose($orderId, $this->designer, 900, $d), 'Запропонувати доопрацювання може лише обраний дизайнер');
        Sql::exec('UPDATE design_designers SET status = ? WHERE customer_id = ?', [DesignerStatus::APPROVED, $this->designer]);

        // Сьогодні — можна; P' = D'·1,25 вгору до 10 (1 000 → 1 250), клієнт доплатить 250
        Rework::propose($orderId, $this->designer, 1000, date('Y-m-d'));
        $o = $this->order($orderId);
        $this->assertSame([1000, 1250, date('Y-m-d'), OrderStatus::PURCHASED],
            [(int)$o['rework_price_designer'], (int)$o['rework_price_client'], $o['rework_deadline'], (int)$o['status']]);
        $this->assertNotNull($o['rework_proposed_at']);
        $this->assertSame([1000, 800, '2026-11-10'], $this->offer($offerId), 'пропозиція поки без змін');
        $this->assertSame(['price_client' => 1250, 'extra' => 250, 'deadline' => date('Y-m-d'), 'price_designer' => 1000], Rework::pending($o));
        $sys = $this->system($offerId);
        $this->assertSame('Дизайнер пропонує доопрацювання: +250 грн, до ' . date('d.m.Y'), end($sys)['text']);
        $this->assertSame('rework_propose', end($sys)['data']['event']);

        // Друга пропозиція, поки клієнт не відповів, — ні
        $this->assertRefused(fn() => Rework::propose($orderId, $this->designer, 1200, $d), 'Пропозицію вже надіслано — чекаємо відповіді клієнта');
    }

    /** P' не менше вже сплаченого: та сама сума дизайнеру — безкоштовно; націнка дала менше за сплачене — до сплаченого */
    public function test_propose_client_price_floor(): void
    {
        [$orderId] = $this->purchased(1010, 800);
        Rework::propose($orderId, $this->designer, 800, self::tomorrow());   // 800·1,25 = 1 000 < 1 010
        $this->assertSame(['price_client' => 1010, 'extra' => 0, 'deadline' => self::tomorrow(), 'price_designer' => 800],
            Rework::pending($this->order($orderId)));

        // pending лише для «Куплено» з пропозицією
        $this->assertNull(Rework::pending(['status' => OrderStatus::PURCHASED, 'rework_price_client' => null, 'rework_price_designer' => null,
            'rework_deadline' => null, 'paid_client' => 1000]));
        $this->assertNull(Rework::pending(['status' => OrderStatus::REWORK, 'rework_price_client' => 1200, 'rework_price_designer' => 900,
            'rework_deadline' => '2026-12-01', 'paid_client' => 1000]));
    }

    public function test_accept_updates_offer_and_status(): void
    {
        [$orderId, $offerId] = $this->purchased();
        $this->assertRefused(fn() => Rework::accept($orderId, $this->client), 'Пропозиції доопрацювання немає — оновіть сторінку');
        Rework::propose($orderId, $this->designer, 1000, self::tomorrow());

        $this->assertRefused(fn() => Rework::accept($orderId, $this->designer), 'Замовлення не знайдено');
        Rework::accept($orderId, $this->client);

        $o = $this->order($orderId);
        $this->assertSame(OrderStatus::REWORK, (int)$o['status']);
        $this->assertSame([null, null, null, null, null],
            [$o['rework_price_designer'], $o['rework_price_client'], $o['rework_deadline'], $o['rework_proposed_at'], $o['notified_deadline_at']]);
        $this->assertSame([1000, 800], [(int)$o['paid_client'], (int)$o['paid_designer']], 'сплачене не змінюється');
        $this->assertSame([1250, 1000, self::tomorrow()], $this->offer($offerId));
        $sys = $this->system($offerId);
        $this->assertSame('Клієнт прийняв доопрацювання. Ціну змінено з 1' . self::NB . '000 на 1' . self::NB . '250 грн, термін — до '
            . date('d.m.Y', strtotime('+1 day')), end($sys)['text']);
        $this->assertSame('rework_accept', end($sys)['data']['event']);

        // Повторне «Прийняти» (друга вкладка) — пропозиції вже немає
        $this->assertRefused(fn() => Rework::accept($orderId, $this->client), 'Пропозиції доопрацювання немає — оновіть сторінку');
        // На доопрацюванні дизайнер працює як «В роботі»: прев'ю й макети
        $this->assertNotNull(\Design\Previews::canWork($orderId, $this->designer));
    }

    public function test_accept_free_rework_keeps_price(): void
    {
        [$orderId, $offerId] = $this->purchased();
        Rework::propose($orderId, $this->designer, 800, self::tomorrow());
        Rework::accept($orderId, $this->client);
        $this->assertSame([1000, 800, self::tomorrow()], $this->offer($offerId));
        $sys = $this->system($offerId);
        $this->assertSame('Клієнт прийняв доопрацювання без доплати, термін — до ' . date('d.m.Y', strtotime('+1 day')), end($sys)['text']);
    }

    public function test_decline_resets(): void
    {
        [$orderId, $offerId] = $this->purchased();
        $this->assertRefused(fn() => Rework::decline($orderId, $this->client), 'Пропозиції доопрацювання немає — оновіть сторінку');
        Rework::propose($orderId, $this->designer, 1000, self::tomorrow());
        $this->assertRefused(fn() => Rework::decline($orderId, $this->designer), 'Замовлення не знайдено');
        Rework::decline($orderId, $this->client);

        $o = $this->order($orderId);
        $this->assertSame(OrderStatus::PURCHASED, (int)$o['status']);
        $this->assertSame([null, null, null, null], [$o['rework_price_designer'], $o['rework_price_client'], $o['rework_deadline'], $o['rework_proposed_at']]);
        $this->assertNull(Rework::pending($o));
        $this->assertSame([1000, 800, '2026-11-10'], $this->offer($offerId));
        $sys = $this->system($offerId);
        $this->assertSame('Клієнт відмовився від доопрацювання', end($sys)['text']);
        $this->assertSame('rework_decline', end($sys)['data']['event']);
        $this->assertNull(\Design\Previews::canWork($orderId, $this->designer), 'після відмови — як «Куплено»');

        // Дизайнер може запропонувати знову
        Rework::propose($orderId, $this->designer, 900, self::tomorrow());
        $this->assertSame(900, (int)$this->order($orderId)['rework_price_designer']);
    }

    public function test_cancel_rework_reverts_prices(): void
    {
        [$orderId, $offerId] = $this->purchased();
        $this->assertRefused(fn() => Rework::cancel($orderId, $this->client), 'Скасувати можна лише доопрацювання, яке триває');
        Rework::propose($orderId, $this->designer, 1000, self::tomorrow());
        Rework::accept($orderId, $this->client);
        $new = $this->layout($orderId, 'Новий');

        $this->assertRefused(fn() => Rework::cancel($orderId, $this->designer), 'Замовлення не знайдено');
        Rework::cancel($orderId, $this->client);

        $o = $this->order($orderId);
        $this->assertSame(OrderStatus::PURCHASED, (int)$o['status']);
        $this->assertSame([1000, 800, self::tomorrow()], $this->offer($offerId), 'ціни — до сплачених, дата — та сама');
        $this->assertNull(Sql::value('SELECT purchase_id FROM design_files WHERE id = ?', [$new]), 'новий макет лишається закритим');
        $this->assertNull(Purchases::quote($orderId, $this->client), 'купити вже не можна');
        $sys = $this->system($offerId);
        $this->assertSame('Клієнт скасував доопрацювання. Ціна повернулася до сплаченої: 1' . self::NB . '000 грн', end($sys)['text']);
        $this->assertSame('rework_cancel', end($sys)['data']['event']);
        $this->assertRefused(fn() => Rework::cancel($orderId, $this->client), 'Скасувати можна лише доопрацювання, яке триває');

        // Дизайнер може запропонувати знову
        Rework::propose($orderId, $this->designer, 1100, self::tomorrow());
        $this->assertSame(1100, (int)$this->order($orderId)['rework_price_designer']);
    }

    public function test_buy_in_rework_charges_difference(): void
    {
        [$orderId] = $this->purchased();
        Rework::propose($orderId, $this->designer, 1000, self::tomorrow());
        Rework::accept($orderId, $this->client);
        $new = $this->layout($orderId, 'Новий');
        $this->money($this->client, 5000);
        $this->money($this->designer, 70);

        $q = Purchases::quote($orderId, $this->client);
        $this->assertSame([250, 200, 1250, 1000, true, 1], [$q['amount_client'], $q['amount_designer'], $q['price_client'],
            $q['price_designer'], $q['can'], $q['new_layouts']]);

        $r = Purchases::buy($orderId, $this->client, 250);
        $this->assertSame([250, 200], [$r['amount_client'], $r['amount_designer']]);
        $this->assertEquals(4750, (float)Sql::value('SELECT inv_sum FROM customers WHERE id = ?', [$this->client]));
        $this->assertEquals(270, (float)Sql::value('SELECT inv_sum FROM customers WHERE id = ?', [$this->designer]));
        $tr = Sql::all("SELECT customer_id, payment_done FROM transaction WHERE id > ? AND paymethod LIKE '[design]%' AND customer_id IN (?, ?) ORDER BY id",
            [$this->maxTransaction, $this->client, $this->designer]);
        $this->assertSame([[$this->client, -250], [$this->designer, 200]],
            array_map(static fn($t) => [(int)$t['customer_id'], (int)$t['payment_done']], $tr));
        $o = $this->order($orderId);
        $this->assertSame([OrderStatus::PURCHASED, 1250, 1000], [(int)$o['status'], (int)$o['paid_client'], (int)$o['paid_designer']]);
        $this->assertSame($r['purchase_id'], (int)Sql::value('SELECT purchase_id FROM design_files WHERE id = ?', [$new]));
    }

    public function test_free_rework_zero_amount_opens_layouts(): void
    {
        [$orderId] = $this->purchased();
        Rework::propose($orderId, $this->designer, 800, self::tomorrow());
        $this->assertSame(0, Rework::pending($this->order($orderId))['extra']);
        Rework::accept($orderId, $this->client);
        $new = $this->layout($orderId, 'Новий');
        $this->money($this->client, 0);
        $this->money($this->designer, 0);

        $q = Purchases::quote($orderId, $this->client);
        $this->assertSame([0, 0, true], [$q['amount_client'], $q['amount_designer'], $q['can']]);
        $r = Purchases::buy($orderId, $this->client, 0);
        $this->assertSame([], Sql::all("SELECT id FROM transaction WHERE id > ? AND paymethod LIKE '[design]%' AND customer_id IN (?, ?)",
            [$this->maxTransaction, $this->client, $this->designer]));
        $this->assertSame($r['purchase_id'], (int)Sql::value('SELECT purchase_id FROM design_files WHERE id = ?', [$new]));
        $this->assertEquals(0, (float)Sql::value('SELECT inv_sum FROM customers WHERE id = ?', [$this->client]));
        $this->assertSame(OrderStatus::PURCHASED, (int)$this->order($orderId)['status']);
    }

    /** Сповіщення — окремо від дії (сторінка шле їх після відповіді браузеру): клієнту пропозиція, дизайнеру відповіді */
    public function test_notify(): void
    {
        [$orderId] = $this->purchased();
        Rework::propose($orderId, $this->designer, 1000, self::tomorrow());
        $this->assertSame([], Notify::$sent, 'дія сама не сповіщає');
        Rework::notify($orderId, 'propose');
        Rework::accept($orderId, $this->client);
        Rework::notify($orderId, 'accept');
        Rework::cancel($orderId, $this->client);
        Rework::notify($orderId, 'cancel');
        Rework::propose($orderId, $this->designer, 900, self::tomorrow());
        Rework::decline($orderId, $this->client);
        Rework::notify($orderId, 'decline');

        $this->assertSame([$this->client, $this->designer, $this->designer, $this->designer], array_column(Notify::$sent, 'customer_id'));
        $d = date('d.m', strtotime('+1 day'));
        $this->assertStringStartsWith("Майстерня дизайну: дизайнер пропонує доопрацювання №$orderId: +250 грн, до $d. Прийміть або відмовтеся: ",
            Notify::$sent[0]['msg']);
        $this->assertStringContainsString("/orders/$orderId/", Notify::$sent[0]['msg']);
        $this->assertSame("Майстерня дизайну: пропозиція доопрацювання №$orderId. design.wolf.ua", Notify::$sent[0]['msgSMS']);
        $this->assertStringStartsWith("Майстерня дизайну: клієнт прийняв доопрацювання №$orderId, до $d. ", Notify::$sent[1]['msg']);
        $this->assertStringContainsString("/feed/$orderId/", Notify::$sent[1]['msg']);
        $this->assertStringStartsWith("Майстерня дизайну: клієнт скасував доопрацювання №$orderId. ", Notify::$sent[2]['msg']);
        $this->assertStringStartsWith("Майстерня дизайну: клієнт відмовився від доопрацювання №$orderId. ", Notify::$sent[3]['msg']);
        foreach (Notify::$sent as $n) $this->assertNotSame('', $n['msgSMS']);
    }
}
