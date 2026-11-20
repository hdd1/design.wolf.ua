<?php

use Design\DesignerException;
use Design\Messages;
use Design\Notify;
use Design\OfferStatus;
use Design\OrderStatus;
use Design\Products;
use Design\Purchases;
use Design\Sql;
use PHPUnit\Framework\TestCase;

/**
 * Купівля з балансу (етап 5, Task 3): справжні гроші на dev — клієнт DESIGN_TEST_CUSTOMER_ID (29), дизайнер
 * DESIGN_TEST_DESIGNER_ID (25). Тест ставить їм потрібні inv_sum / credit / credit_ra / overdraft_*, а в tearDown (і при
 * падінні) повертає все як було, видаляє свої рядки transaction (id > max до тесту, paymethod '[design]%') і свої design_*.
 * Замовлення, обрану пропозицію (CHOSEN) і макети (рядки design_files без файлів) тест вставляє сам.
 * @group db
 */
final class PurchasesTest extends TestCase
{
    private const NB = "\u{00A0}";
    /** Колонки customers, які тест змінює і повертає */
    private const MONEY_COLS = ['inv_sum', 'credit', 'credit_ra', 'temp_credit_ra', 'parent_id', 'overdraft_start', 'overdraft_days'];

    private int $client;
    private int $designer;
    /** @var array<int, array> рядки customers до тесту */
    private array $saved = [];
    private int $maxTransaction = 0;
    /** @var list<int> */
    private array $orders = [];
    /** @var resource|null паралельний процес (test_concurrent_buy_debits_once) */
    private $child = null;

    protected function setUp(): void
    {
        $this->client = (int)getenv('DESIGN_TEST_CUSTOMER_ID');
        $this->designer = (int)getenv('DESIGN_TEST_DESIGNER_ID');
        if ($this->client <= 0 || $this->designer <= 0) self::markTestSkipped('DESIGN_TEST_CUSTOMER_ID / DESIGN_TEST_DESIGNER_ID не задані');
        Notify::$sent = [];
        $this->maxTransaction = (int)Sql::value('SELECT COALESCE(MAX(id), 0) FROM transaction');
        foreach ([$this->client, $this->designer] as $id) {
            $row = Sql::one('SELECT ' . implode(', ', self::MONEY_COLS) . ' FROM customers WHERE id = ?', [$id]);
            if ($row === null) self::markTestSkipped("Клієнта $id немає в customers");
            $this->saved[$id] = $row;
        }
    }

    /** Кожен крок прибирання — окремо: збій одного (напр. класу ще немає) не лишає гроші 29/25 зміненими */
    protected function tearDown(): void
    {
        $steps = [
            function (): void {
                if (is_resource($this->child)) {
                    proc_terminate($this->child);
                    proc_close($this->child);
                }
                $this->child = null;
            },
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
            function (): void {
                if (!$this->orders) return;
                $in = implode(',', array_map('intval', $this->orders));
                Sql::exec("DELETE FROM design_purchases WHERE order_id IN ($in)");
                Sql::exec("DELETE FROM design_files WHERE order_id IN ($in)");
                Sql::exec("DELETE m FROM design_messages m JOIN design_offers f ON f.id = m.offer_id WHERE f.order_id IN ($in)");
                Sql::exec("DELETE FROM design_offers WHERE order_id IN ($in)");
                Sql::exec("DELETE FROM design_orders WHERE id IN ($in)");
            },
            static function (): void {
                Purchases::$afterLock = null;
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

    /** Гроші клієнта/дизайнера на час тесту */
    private function money(int $id, int $invSum, int $credit = 0, array $extra = []): void
    {
        $set = ['inv_sum' => $invSum, 'credit' => $credit, 'credit_ra' => 0, 'temp_credit_ra' => 0, 'parent_id' => 0,
                'overdraft_start' => null, 'overdraft_days' => null];
        $set = array_merge($set, $extra);
        Sql::exec('UPDATE customers SET ' . implode(', ', array_map(static fn($c) => "$c = ?", array_keys($set))) . ' WHERE id = ?',
            [...array_values($set), $id]);
    }

    /**
     * Замовлення клієнта з обраною пропозицією дизайнера (D, P, k), $layouts нових активних макетів
     * @return array{0:int, 1:int} [orderId, offerId]
     */
    private function order(int $p = 800, int $d = 640, int $layouts = 1, int $status = OrderStatus::IN_WORK, array $orderExtra = []): array
    {
        $orderId = Sql::insert('design_orders', [
            'customer_id' => $this->client,
            'product_id' => Products::visible()[0]['id'],
            'status' => $status,
            'title' => 'Тест купівлі',
            'answers' => '[]',
        ] + $orderExtra);
        $this->orders[] = $orderId;
        $offerId = Sql::insert('design_offers', [
            'order_id' => $orderId, 'designer_id' => $this->designer, 'status' => OfferStatus::CHOSEN,
            'text' => 'Зроблю', 'price_designer' => $d, 'markup_percent' => 25, 'price_client' => $p, 'deadline' => date('Y-m-d'),
        ]);
        Sql::exec('UPDATE design_orders SET chosen_offer_id = ?, chosen_at = NOW() WHERE id = ?', [$offerId, $orderId]);
        for ($i = 1; $i <= $layouts; $i++) $this->layout($orderId, "Макет $i");
        return [$orderId, $offerId];
    }

    private function layout(int $orderId, string $label, int $status = 1, ?int $purchaseId = null): int
    {
        return Sql::insert('design_files', [
            'order_id' => $orderId, 'label' => $label, 'original_name' => "$label.pdf", 'file' => bin2hex(random_bytes(4)) . '.pdf',
            'size' => 1000, 'md5' => md5($label), 'status' => $status, 'purchase_id' => $purchaseId,
        ]);
    }

    private function balance(int $id): array
    {
        $r = Sql::one('SELECT inv_sum, credit, overdraft_start, overdraft_days FROM customers WHERE id = ?', [$id]);
        return ['inv_sum' => (int)round((float)$r['inv_sum']), 'credit' => (int)$r['credit'],
                'overdraft_start' => $r['overdraft_start'], 'overdraft_days' => $r['overdraft_days'] === null ? null : (int)$r['overdraft_days']];
    }

    /** @return list<array> рядки transaction цього тесту */
    private function transactions(): array
    {
        return Sql::all("SELECT * FROM transaction WHERE id > ? AND paymethod LIKE '[design]%' AND customer_id IN (?, ?) ORDER BY id",
            [$this->maxTransaction, $this->client, $this->designer]);
    }

    private function purchases(int $orderId): array
    {
        return Sql::all('SELECT * FROM design_purchases WHERE order_id = ? ORDER BY id', [$orderId]);
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

    /** Нічого не рушилось: баланси, transaction, design_purchases, макети, статус */
    private function assertNothingMoved(int $orderId, array $clientBefore, array $designerBefore, int $status = OrderStatus::IN_WORK): void
    {
        $this->assertSame($clientBefore, $this->balance($this->client));
        $this->assertSame($designerBefore, $this->balance($this->designer));
        $this->assertSame([], $this->transactions());
        $this->assertSame([], $this->purchases($orderId));
        $this->assertSame(0, (int)Sql::value('SELECT COUNT(*) FROM design_files WHERE order_id = ? AND purchase_id IS NOT NULL', [$orderId]));
        $o = Sql::one('SELECT status, paid_client, paid_designer, purchased_at FROM design_orders WHERE id = ?', [$orderId]);
        $this->assertSame($status, (int)$o['status']);
        $this->assertNull($o['purchased_at']);
    }

    // ---------- тести ----------

    public function test_quote_enough_and_short(): void
    {
        [$orderId] = $this->order(800, 640, 2);
        $this->money($this->client, 1000);
        $q = Purchases::quote($orderId, $this->client);
        $this->assertSame([
            'amount_client' => 800, 'amount_designer' => 640, 'price_client' => 800, 'price_designer' => 640,
            'balance' => 1000, 'credit' => 0, 'after' => 200, 'can' => true, 'reason' => '', 'new_layouts' => 2,
        ], $q);

        $this->money($this->client, 100, 200);
        $q = Purchases::quote($orderId, $this->client);
        $this->assertFalse($q['can']);
        $this->assertSame('Не вистачає 500 грн', $q['reason']);
        $this->assertSame(-700, $q['after']);
        $this->assertSame(200, $q['credit']);

        $this->money($this->client, -2000, 1000);
        $this->assertSame('Не вистачає 1' . self::NB . '800 грн', Purchases::quote($orderId, $this->client)['reason']);
    }

    public function test_buy_moves_money_and_writes_rows(): void
    {
        [$orderId, $offerId] = $this->order(800, 640, 2);
        $old = $this->layout($orderId, 'Замінений', 2);
        $this->money($this->client, 1000);
        $this->money($this->designer, 50);
        Sql::exec('UPDATE design_orders SET rework_price_designer = 1, rework_price_client = 2, rework_deadline = CURDATE(), rework_proposed_at = NOW() WHERE id = ?', [$orderId]);
        $t0 = time();

        $r = Purchases::buy($orderId, $this->client, 800);

        $this->assertSame(['purchase_id', 'amount_client', 'amount_designer', 'designer_id'], array_keys($r));
        $this->assertSame([800, 640, $this->designer], [$r['amount_client'], $r['amount_designer'], $r['designer_id']]);
        $this->assertSame(200, $this->balance($this->client)['inv_sum']);
        $this->assertSame(690, $this->balance($this->designer)['inv_sum']);
        $this->assertNull($this->balance($this->client)['overdraft_start'], 'у мінус не пішов — овердрафт не починається');

        $tr = $this->transactions();
        $this->assertCount(2, $tr);
        [$c, $d] = $tr;
        $this->assertSame([$this->client, 'COMPLETE', "[design] Оплата замовлення №$orderId", -800, null, 0, 0],
            [(int)$c['customer_id'], $c['orderstatus'], $c['paymethod'], (int)$c['payment_done'], $c['order_id'], (int)$c['type_id'], (int)$c['user_id']]);
        $this->assertSame([$this->designer, 'COMPLETE', "[design] Гонорар за замовлення №$orderId", 640, null, 0, 0],
            [(int)$d['customer_id'], $d['orderstatus'], $d['paymethod'], (int)$d['payment_done'], $d['order_id'], (int)$d['type_id'], (int)$d['user_id']]);
        foreach ($tr as $t) $this->assertEqualsWithDelta($t0, (int)$t['created_ts'], 5);

        $p = $this->purchases($orderId);
        $this->assertCount(1, $p);
        $p = $p[0];
        $this->assertSame($r['purchase_id'], (int)$p['id']);
        $this->assertSame([$offerId, $this->client, 800, 640, 800, 640, 1, 2, (int)$c['id'], (int)$d['id']],
            [(int)$p['offer_id'], (int)$p['payer_id'], (int)$p['price_client'], (int)$p['price_designer'], (int)$p['amount_client'],
             (int)$p['amount_designer'], (int)$p['method'], (int)$p['status'], (int)$p['transaction_client_id'], (int)$p['transaction_designer_id']]);
        $this->assertSame('25.00', $p['markup_percent']);
        $this->assertNotNull($p['paid_at']);

        $files = Sql::all('SELECT id, status, purchase_id FROM design_files WHERE order_id = ? ORDER BY id', [$orderId]);
        foreach ($files as $f) {
            $this->assertSame((int)$f['id'] === $old ? null : $r['purchase_id'], $f['purchase_id'] === null ? null : (int)$f['purchase_id'],
                'purchase_id — лише активним макетам');
        }

        $o = Sql::one('SELECT * FROM design_orders WHERE id = ?', [$orderId]);
        $this->assertSame([OrderStatus::PURCHASED, 800, 640], [(int)$o['status'], (int)$o['paid_client'], (int)$o['paid_designer']]);
        $this->assertNotNull($o['purchased_at']);
        $this->assertSame([null, null, null, null], [$o['rework_price_designer'], $o['rework_price_client'], $o['rework_deadline'], $o['rework_proposed_at']]);

        $msgs = Messages::list($offerId);
        $last = end($msgs);
        $this->assertSame(Messages::ROLE_SYSTEM, $last['role']);
        $this->assertSame('Клієнт купив макети за 800 грн', $last['text']);
        $this->assertSame('purchase', $last['data']['event']);

        $this->assertSame([], Notify::$sent, 'buy() не шле сповіщень — їх шле сторінка після відповіді');
        Purchases::notifyDesigner($r, $orderId);
        $this->assertCount(1, Notify::$sent);
        $this->assertSame($this->designer, Notify::$sent[0]['customer_id']);
        $this->assertStringContainsString("Клієнт купив макети №$orderId, +640 грн", Notify::$sent[0]['msg']);
        $this->assertStringContainsString("№$orderId", Notify::$sent[0]['msgSMS']);

        $earned = array_values(array_filter(Purchases::forDesigner($this->designer), static fn($e) => $e['order_id'] === $orderId));
        $this->assertCount(1, $earned);
        $this->assertSame(['paid_at', 'order_id', 'title', 'amount_designer'], array_keys($earned[0]));
        $this->assertSame(['Тест купівлі', 640], [$earned[0]['title'], $earned[0]['amount_designer']]);
        $this->assertSame([], array_filter(Purchases::forDesigner($this->client), static fn($e) => $e['order_id'] === $orderId));
    }

    public function test_credit_allows_negative_and_starts_overdraft(): void
    {
        [$orderId] = $this->order(800, 640);
        $this->money($this->client, 100, 1000);
        $q = Purchases::quote($orderId, $this->client);
        $this->assertTrue($q['can']);
        $this->assertSame(-700, $q['after']);

        Purchases::buy($orderId, $this->client, 800);
        $this->assertSame(['inv_sum' => -700, 'credit' => 1000, 'overdraft_start' => date('Y-m-d'), 'overdraft_days' => 30],
            $this->balance($this->client));

        // Вже в мінусі з початим овердрафтом (і своїм терміном) — дата й термін не змінюються
        [$second] = $this->order(100, 80);
        $start = date('Y-m-d', strtotime('-5 days'));
        $this->money($this->client, -50, 1000, ['overdraft_start' => $start, 'overdraft_days' => 45]);
        Purchases::buy($second, $this->client, 100);
        $this->assertSame(['inv_sum' => -150, 'credit' => 1000, 'overdraft_start' => $start, 'overdraft_days' => 45],
            $this->balance($this->client));
    }

    public function test_credit_ra_ignored(): void
    {
        [$orderId] = $this->order(800, 640);
        $this->money($this->client, 0, 0, ['credit_ra' => 125000, 'temp_credit_ra' => 5000]);
        $this->money($this->designer, 0);
        $c = $this->balance($this->client);
        $d = $this->balance($this->designer);

        $q = Purchases::quote($orderId, $this->client);
        $this->assertFalse($q['can']);
        $this->assertSame('Не вистачає 800 грн', $q['reason']);
        $this->assertRefused(fn() => Purchases::buy($orderId, $this->client, 800), 'Не вистачає 800 грн');
        $this->assertNothingMoved($orderId, $c, $d);
    }

    public function test_expired_overdraft_blocks(): void
    {
        [$orderId] = $this->order(800, 640);
        $this->money($this->client, -100, 100000, ['overdraft_start' => date('Y-m-d', strtotime('-40 days')), 'overdraft_days' => 30]);
        $c = $this->balance($this->client);
        $d = $this->balance($this->designer);
        $msg = 'Перевищено термін погашення заборгованості на 10 днів';

        $q = Purchases::quote($orderId, $this->client);
        $this->assertFalse($q['can']);
        $this->assertSame($msg, $q['reason']);
        $this->assertRefused(fn() => Purchases::buy($orderId, $this->client, 800), $msg);
        $this->assertNothingMoved($orderId, $c, $d);

        // Останній день терміну — ще можна
        $this->money($this->client, -100, 100000, ['overdraft_start' => date('Y-m-d', strtotime('-30 days')), 'overdraft_days' => 30]);
        $this->assertTrue(Purchases::quote($orderId, $this->client)['can']);
        Purchases::buy($orderId, $this->client, 800);
        $this->assertSame(-900, $this->balance($this->client)['inv_sum']);
    }

    public function test_exact_balance_ok(): void
    {
        [$orderId] = $this->order(800, 640);
        $this->money($this->client, 800);
        $q = Purchases::quote($orderId, $this->client);
        $this->assertTrue($q['can']);
        $this->assertSame(0, $q['after']);

        Purchases::buy($orderId, $this->client, 800);
        $this->assertSame(['inv_sum' => 0, 'credit' => 0, 'overdraft_start' => null, 'overdraft_days' => null], $this->balance($this->client));

        // На гривню менше — відмова
        [$second] = $this->order(800, 640);
        $this->money($this->client, 799);
        $this->assertRefused(fn() => Purchases::buy($second, $this->client, 800), 'Не вистачає 1 грн');
        $this->assertSame(799, $this->balance($this->client)['inv_sum']);
    }

    public function test_price_changed_rejected_nothing_moved(): void
    {
        [$orderId] = $this->order(800, 640);
        $this->money($this->client, 5000);
        $c = $this->balance($this->client);
        $d = $this->balance($this->designer);

        $this->assertRefused(fn() => Purchases::buy($orderId, $this->client, 700), 'Ціна змінилася — перевірте суму');
        $this->assertNothingMoved($orderId, $c, $d);
        $this->assertSame([], Notify::$sent);
    }

    public function test_no_new_layouts_rejected(): void
    {
        [$orderId] = $this->order(800, 640, 0);
        $this->layout($orderId, 'Видалений', 3);
        $this->layout($orderId, 'Замінений', 2);
        $this->money($this->client, 5000);
        $c = $this->balance($this->client);
        $d = $this->balance($this->designer);

        $q = Purchases::quote($orderId, $this->client);
        $this->assertFalse($q['can']);
        $this->assertSame('Немає макетів для купівлі', $q['reason']);
        $this->assertSame(0, $q['new_layouts']);
        $this->assertRefused(fn() => Purchases::buy($orderId, $this->client, 800), 'Немає макетів для купівлі');
        $this->assertNothingMoved($orderId, $c, $d);
    }

    /** Доопрацювання без доплати (paid_* = ціні): купівля 0 — без рядків transaction і без руху балансу, але з design_purchases */
    public function test_zero_amount_no_transactions(): void
    {
        [$orderId, $offerId] = $this->order(800, 640, 0, OrderStatus::REWORK, ['paid_client' => 800, 'paid_designer' => 640]);
        $bought = $this->layout($orderId, 'Куплений', 1, 999999);
        $new = $this->layout($orderId, 'Новий');
        // Без грошей і навіть з простроченим овердрафтом — платити нічого
        $this->money($this->client, -10, 0, ['overdraft_start' => date('Y-m-d', strtotime('-90 days')), 'overdraft_days' => 30]);
        $c = $this->balance($this->client);
        $d = $this->balance($this->designer);

        $q = Purchases::quote($orderId, $this->client);
        $this->assertSame([0, 0, true, '', 1], [$q['amount_client'], $q['amount_designer'], $q['can'], $q['reason'], $q['new_layouts']]);

        $r = Purchases::buy($orderId, $this->client, 0);
        $this->assertSame([0, 0], [$r['amount_client'], $r['amount_designer']]);
        $this->assertSame($c, $this->balance($this->client));
        $this->assertSame($d, $this->balance($this->designer));
        $this->assertSame([], $this->transactions());

        $p = $this->purchases($orderId);
        $this->assertCount(1, $p);
        $this->assertSame([0, 0, 2, null, null], [(int)$p[0]['amount_client'], (int)$p[0]['amount_designer'], (int)$p[0]['status'],
            $p[0]['transaction_client_id'], $p[0]['transaction_designer_id']]);
        $this->assertSame(999999, (int)Sql::value('SELECT purchase_id FROM design_files WHERE id = ?', [$bought]), 'куплений раніше — не чіпаємо');
        $this->assertSame($r['purchase_id'], (int)Sql::value('SELECT purchase_id FROM design_files WHERE id = ?', [$new]));
        $o = Sql::one('SELECT status, paid_client, paid_designer FROM design_orders WHERE id = ?', [$orderId]);
        $this->assertSame([OrderStatus::PURCHASED, 800, 640], [(int)$o['status'], (int)$o['paid_client'], (int)$o['paid_designer']]);
        $this->assertSame([], array_filter(Purchases::forDesigner($this->designer), static fn($e) => $e['order_id'] === $orderId),
            'у «Заробіток» — лише з гонораром');
    }

    /** Доопрацювання з доплатою: до сплати — різниця з уже сплаченим */
    public function test_rework_pays_difference(): void
    {
        [$orderId] = $this->order(1000, 800, 0, OrderStatus::REWORK, ['paid_client' => 800, 'paid_designer' => 640]);
        $this->layout($orderId, 'Куплений', 1, 999999);
        $this->layout($orderId, 'Новий');
        $this->money($this->client, 500);
        $this->money($this->designer, 0);

        $q = Purchases::quote($orderId, $this->client);
        $this->assertSame([200, 160, 300], [$q['amount_client'], $q['amount_designer'], $q['after']]);
        $this->assertRefused(fn() => Purchases::buy($orderId, $this->client, 1000), 'Ціна змінилася — перевірте суму');
        Purchases::buy($orderId, $this->client, 200);
        $this->assertSame(300, $this->balance($this->client)['inv_sum']);
        $this->assertSame(160, $this->balance($this->designer)['inv_sum']);
        $this->assertSame([-200, 160], array_map(static fn($t) => (int)$t['payment_done'], $this->transactions()));
        $o = Sql::one('SELECT paid_client, paid_designer FROM design_orders WHERE id = ?', [$orderId]);
        $this->assertSame([1000, 800], [(int)$o['paid_client'], (int)$o['paid_designer']]);
    }

    /**
     * Дві одночасні купівлі: перша тримає замок рядка замовлення (хук після SELECT … FOR UPDATE), друга — окремий процес
     * PHP зі своїм зʼєднанням — чекає на цьому замку (видно в performance_schema.data_lock_waits; information_schema.innodb_trx для частого опитування
     * не годиться — кеш оновлюється, лише якщо його не читали 0,1 с), а після коммиту першої бачить
     * «Куплено» і відмовляє. Списання одне, пара рядків transaction одна, design_purchases один. Потім — ще й повтор підряд
     */
    public function test_concurrent_buy_debits_once(): void
    {
        [$orderId] = $this->order(800, 640, 2);
        $this->money($this->client, 5000);
        $this->money($this->designer, 0);
        $watch = new \PDO(
            'mysql:host=' . \CdnConfig::get('db_host') . ';port=' . \CdnConfig::get('db_port') . ';dbname=w2p;charset=utf8mb4',
            \CdnConfig::get('db_username'), \CdnConfig::get('db_password'), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

        $code = 'require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ';'
            . 'Design\Sql::exec("SET SESSION innodb_lock_wait_timeout = 15");'
            . 'try { $r = Design\Purchases::buy((int)$argv[1], (int)$argv[2], (int)$argv[3]); echo json_encode(["ok" => $r]); }'
            . 'catch (Design\DesignerException $e) { echo json_encode(["error" => $e->getMessage()], JSON_UNESCAPED_UNICODE); }';
        $pipes = [];
        $waited = false;
        Purchases::$afterLock = function (int $order) use ($orderId, $code, $watch, &$pipes, &$waited): void {
            if ($waited) return;
            $this->assertSame($orderId, $order);
            $this->assertTrue(Sql::pdo()->inTransaction());
            $this->child = proc_open([PHP_BINARY, '-r', $code, (string)$orderId, (string)$this->client, '800'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $deadline = microtime(true) + 10;
            while (microtime(true) < $deadline) {
                $n = (int)$watch->query("SELECT COUNT(*) FROM performance_schema.data_lock_waits w
                    JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID = w.REQUESTING_ENGINE_LOCK_ID
                    WHERE l.OBJECT_SCHEMA = 'w2p' AND l.OBJECT_NAME = 'design_orders' AND l.LOCK_DATA = '$orderId'")->fetchColumn();
                if ($n > 0) { $waited = true; return; }
                // Читати вивід — лише коли процес уже завершився: stream_get_contents чекає кінця потоку
                if (!proc_get_status($this->child)['running']) {
                    $this->fail('друга купівля завершилась, не чекаючи замка: ' . stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]));
                }
                usleep(20000);
            }
            $this->fail('друга купівля не стала чекати на замку замовлення');
        };

        $first = Purchases::buy($orderId, $this->client, 800);
        Purchases::$afterLock = null;
        $this->assertTrue($waited);

        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        proc_close($this->child);
        $this->child = null;
        $this->assertSame(['error' => 'Макети вже куплено'], json_decode($out, true), "вивід другої купівлі: $out $err");

        $this->assertRefused(fn() => Purchases::buy($orderId, $this->client, 800), 'Макети вже куплено');
        $this->assertSame(4200, $this->balance($this->client)['inv_sum']);
        $this->assertSame(640, $this->balance($this->designer)['inv_sum']);
        $this->assertCount(2, $this->transactions());
        $this->assertCount(1, $this->purchases($orderId));
        $this->assertSame($first['purchase_id'], (int)$this->purchases($orderId)[0]['id']);
    }

    public function test_only_owner(): void
    {
        [$orderId] = $this->order(800, 640);
        $this->money($this->client, 5000);
        $this->money($this->designer, 5000);
        $c = $this->balance($this->client);
        $d = $this->balance($this->designer);

        $this->assertNull(Purchases::quote($orderId, $this->designer));
        $this->assertNull(Purchases::quote($orderId + 1000000, $this->client));
        $this->assertRefused(fn() => Purchases::buy($orderId, $this->designer, 800), 'Замовлення не знайдено');
        $this->assertNothingMoved($orderId, $c, $d);

        foreach ([OrderStatus::COLLECTING, OrderStatus::PURCHASED, OrderStatus::CANCELLED] as $status) {
            Sql::exec('UPDATE design_orders SET status = ? WHERE id = ?', [$status, $orderId]);
            $this->assertNull(Purchases::quote($orderId, $this->client), "статус $status");
            $this->assertRefused(fn() => Purchases::buy($orderId, $this->client, 800),
                $status === OrderStatus::PURCHASED ? 'Макети вже куплено' : 'Замовлення зараз не можна купити');
        }
        $this->assertSame([], $this->transactions());
    }

    /** Субакаунт (parent_id ≠ 0) на design нічого не може — і сервіс грошей це перевіряє сам, не лише Guard */
    public function test_sub_account_cannot_buy(): void
    {
        [$orderId] = $this->order(800, 640);
        $this->money($this->client, 5000, 0, ['parent_id' => $this->designer]);
        $c = $this->balance($this->client);
        $d = $this->balance($this->designer);
        $msg = 'З субакаунта купити не можна — потрібен основний акаунт wolf.ua';

        $q = Purchases::quote($orderId, $this->client);
        $this->assertSame([false, $msg], [$q['can'], $q['reason']]);
        $this->assertRefused(fn() => Purchases::buy($orderId, $this->client, 800), $msg);
        $this->assertNothingMoved($orderId, $c, $d);
    }
}
