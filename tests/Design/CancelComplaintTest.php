<?php

use Design\BriefException;
use Design\CloudCopy;
use Design\Complaints;
use Design\DesignerException;
use Design\DesignerStatus;
use Design\Layouts;
use Design\Notify;
use Design\Offers;
use Design\OfferStatus;
use Design\Orders;
use Design\OrderStatus;
use Design\Paths;
use Design\Previews;
use Design\Products;
use Design\Purchases;
use Design\Rework;
use Design\Settings;
use Design\Sql;
use Design\Workspace;
use PHPUnit\Framework\TestCase;

/**
 * Скасування замовлення клієнтом і скарга після купівлі (етап 5, Task 7).
 * Клієнт — DESIGN_TEST_CUSTOMER_ID (29, у нього є куратор), обраний / запрошений дизайнер — DESIGN_TEST_DESIGNER_ID (25:
 * тест робить його схваленим дизайнером, є свій профіль — пропуск), ще два дизайнери — фіктивні 999999821, 999999822.
 * Замовлення, пропозиції, покупки (рядки design_purchases без руху грошей) і макети тест вставляє сам.
 * Прев'ю й макети на диску — у тимчасовому DESIGN_WORK_ROOT; симлінки i.fastcdn прев'ю прибирає скасування, порожні шарди —
 * CdnLinksGuard. Покупку на скасованому замовленні тест лише пробує (відмова до будь-якого списання) — гроші 29/25 все одно
 * запам'ятовуються й повертаються, рядки transaction '[design]%' після max id — видаляються.
 * @group db
 */
final class CancelComplaintTest extends TestCase
{
    private const D2 = 999999821;
    private const D3 = 999999822;
    private const MONEY_COLS = ['inv_sum', 'credit', 'credit_ra', 'temp_credit_ra', 'parent_id', 'overdraft_start', 'overdraft_days'];

    private int $client;
    private int $designer;
    private int $curator;
    /** @var list<int> */
    private array $orders = [];
    private bool $ownsDesigner = false;
    private array $saved = [];
    private int $maxTransaction = 0;
    private string $root = '';
    private string|false $oldRoot = false;
    private array $links = [];

    protected function setUp(): void
    {
        $this->client = (int)getenv('DESIGN_TEST_CUSTOMER_ID');
        $this->designer = (int)getenv('DESIGN_TEST_DESIGNER_ID');
        if ($this->client <= 0 || $this->designer <= 0) self::markTestSkipped('DESIGN_TEST_CUSTOMER_ID / DESIGN_TEST_DESIGNER_ID не задані');
        if (Sql::value('SELECT customer_id FROM design_designers WHERE customer_id = ?', [$this->designer]) !== null) {
            self::markTestSkipped("у клієнта {$this->designer} вже є профіль дизайнера — тест не чіпає справжні дані");
        }
        $this->curator = (int)Sql::value('SELECT curator_id FROM customers WHERE id = ?', [$this->client]);
        Notify::$sent = [];
        Settings::reset();
        $this->links = CdnLinksGuard::snapshot();
        $this->maxTransaction = (int)Sql::value('SELECT COALESCE(MAX(id), 0) FROM transaction');
        foreach ([$this->client, $this->designer] as $id) {
            $this->saved[$id] = Sql::one('SELECT ' . implode(', ', self::MONEY_COLS) . ' FROM customers WHERE id = ?', [$id]);
        }
        Sql::exec('DELETE FROM design_designers WHERE customer_id IN (?, ?)', [self::D2, self::D3]);
        Sql::insert('design_designers', ['customer_id' => $this->designer, 'status' => DesignerStatus::APPROVED, 'name' => 'Тест Дизайнер скасування']);
        $this->ownsDesigner = true;
        Sql::insert('design_designers', ['customer_id' => self::D2, 'status' => DesignerStatus::APPROVED, 'name' => 'Оксана']);
        Sql::insert('design_designers', ['customer_id' => self::D3, 'status' => DesignerStatus::APPROVED, 'name' => 'Ігор']);
        $this->oldRoot = getenv('DESIGN_WORK_ROOT');
        $this->root = sys_get_temp_dir() . '/design-cancel-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
        putenv('DESIGN_WORK_ROOT=' . $this->root);
    }

    protected function tearDown(): void
    {
        $steps = [
            static function (): void {
                if (Sql::pdo()->inTransaction()) Sql::pdo()->rollBack();
            },
            function (): void {
                foreach ($this->saved as $id => $row) {
                    if ($row === null) continue;
                    Sql::exec('UPDATE customers SET ' . implode(', ', array_map(static fn($c) => "$c = ?", self::MONEY_COLS)) . ' WHERE id = ?',
                        [...array_values($row), $id]);
                }
                if ($this->saved) {
                    Sql::exec("DELETE FROM transaction WHERE id > ? AND paymethod LIKE '[design]%' AND customer_id IN (?, ?)",
                        [$this->maxTransaction, $this->client, $this->designer]);
                }
                $this->saved = [];
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
                if ($this->ownsDesigner) Sql::exec('DELETE FROM design_designers WHERE customer_id = ?', [$this->designer]);
                $this->ownsDesigner = false;
                Sql::exec('DELETE FROM design_designers WHERE customer_id IN (?, ?)', [self::D2, self::D3]);
            },
            function (): void {
                if ($this->root === '') return;
                putenv($this->oldRoot === false ? 'DESIGN_WORK_ROOT' : 'DESIGN_WORK_ROOT=' . $this->oldRoot);
                self::rmTree($this->root);
                $this->root = '';
            },
            function (): void {
                if ($this->links) CdnLinksGuard::cleanup($this->links);
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
        Notify::$sent = [];
        Settings::reset();
        if ($error !== null) throw $error;
    }

    // ---------- підготовка ----------

    /** Замовлення клієнта в статусі $status (режим $mode), без пропозицій */
    private function order(int $status, int $mode = 1, ?int $customer = null): int
    {
        $id = Sql::insert('design_orders', [
            'customer_id' => $customer ?? $this->client,
            'product_id' => Products::visible()[0]['id'],
            'mode' => $mode,
            'status' => $status,
            'title' => 'Тест скасування',
            'answers' => '[]',
        ]);
        $this->orders[] = $id;
        if ($status !== OrderStatus::DRAFT) Sql::exec('UPDATE design_orders SET published_at = NOW() WHERE id = ?', [$id]);
        return $id;
    }

    private function offer(int $orderId, int $designerId, int $status, int $d = 800, int $p = 1000): int
    {
        return Sql::insert('design_offers', [
            'order_id' => $orderId, 'designer_id' => $designerId, 'status' => $status,
            'text' => 'Зроблю', 'price_designer' => $d, 'markup_percent' => 25, 'price_client' => $p, 'deadline' => date('Y-m-d', strtotime('+3 days')),
        ]);
    }

    /** Замовлення з обраною пропозицією 25 (і «обрано іншого» від D2) у статусі $status → [orderId, offerId] */
    private function chosen(int $status = OrderStatus::IN_WORK): array
    {
        $orderId = $this->order($status);
        $offerId = $this->offer($orderId, $this->designer, OfferStatus::CHOSEN);
        $this->offer($orderId, self::D2, OfferStatus::OTHER_CHOSEN);
        Sql::exec('UPDATE design_orders SET chosen_offer_id = ?, chosen_at = NOW() WHERE id = ?', [$offerId, $orderId]);
        return [$orderId, $offerId];
    }

    /** Проведена (status 2) або інша покупка — лише рядок, без грошей */
    private function purchase(int $orderId, int $offerId, int $status = 2): int
    {
        return Sql::insert('design_purchases', [
            'order_id' => $orderId, 'offer_id' => $offerId, 'payer_id' => $this->client, 'price_client' => 1000, 'price_designer' => 800,
            'markup_percent' => 25, 'amount_client' => 1000, 'amount_designer' => 800, 'status' => $status,
        ]);
    }

    /** Рядок макета і (за потреби) його файл у Paths::layouts → [id, шлях] */
    private function layout(int $orderId, ?int $purchaseId = null, bool $file = true): array
    {
        $id = Sql::insert('design_files', [
            'order_id' => $orderId, 'label' => 'Макет', 'original_name' => 'Макет.pdf', 'file' => 'x.pdf',
            'size' => 4, 'md5' => md5('x'), 'status' => Layouts::ACTIVE, 'purchase_id' => $purchaseId,
        ]);
        Sql::exec('UPDATE design_files SET file = ? WHERE id = ?', ["$id.pdf", $id]);
        $path = Paths::layouts($orderId) . "/$id.pdf";
        if ($file) file_put_contents($path, '%PDF');
        return [$id, $path];
    }

    /** Тимчасовий JPG «як після склеювання» */
    private function upload(): string
    {
        $im = new \Imagick();
        $im->newImage(300, 200, new \ImagickPixel('#3366cc'));
        $im->setImageFormat('jpeg');
        $path = $this->root . '/upload-' . bin2hex(random_bytes(4)) . '.jpg';
        $im->writeImage($path);
        $im->clear();
        return $path;
    }

    private function row(int $orderId): array
    {
        return Sql::one('SELECT status, cancel_reason, cancelled_at, published_at FROM design_orders WHERE id = ?', [$orderId]);
    }

    private function assertRefused(callable $fn, string $msg = '', string $class = DesignerException::class): void
    {
        try {
            $fn();
            $this->fail('очікувалась відмова' . ($msg !== '' ? ': ' . $msg : ''));
        } catch (DesignerException|BriefException $e) {
            $this->assertInstanceOf($class, $e);
            if ($msg !== '') $this->assertSame($msg, $e->getMessage());
        }
    }

    private static function rmTree(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (array_diff(scandir($path), ['.', '..']) as $f) self::rmTree("$path/$f");
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }

    // ---------- скасування ----------

    public function test_cancel_statuses_and_reason(): void
    {
        foreach ([OrderStatus::DRAFT, OrderStatus::COLLECTING, OrderStatus::WAITING_DESIGNER, OrderStatus::IN_WORK] as $status) {
            $id = $this->order($status);
            Orders::cancel($id, $this->client, "  Знайшов дизайнера в іншому місці  \n");
            $r = $this->row($id);
            $this->assertSame(OrderStatus::CANCELLED, (int)$r['status'], "статус $status → скасовано");
            $this->assertSame('Знайшов дизайнера в іншому місці', $r['cancel_reason']);
            $this->assertNotNull($r['cancelled_at']);
            $this->assertEqualsWithDelta(time(), strtotime($r['cancelled_at']), 60);
        }

        $id = $this->order(OrderStatus::COLLECTING);
        $this->assertRefused(fn() => Orders::cancel($id, $this->client, ''), 'Вкажіть причину');
        $this->assertRefused(fn() => Orders::cancel($id, $this->client, " \n\t "), 'Вкажіть причину');
        $this->assertRefused(fn() => Orders::cancel($id, $this->client, str_repeat('я', 2001)), 'Причина — не довше 2000 символів');
        $this->assertRefused(fn() => Orders::cancel($id, $this->designer, 'чуже'), 'Замовлення не знайдено');
        $this->assertSame(OrderStatus::COLLECTING, (int)$this->row($id)['status'], 'відмови нічого не змінили');
        $this->assertNull($this->row($id)['cancel_reason']);

        Orders::cancel($id, $this->client, str_repeat('я', 2000));
        $this->assertSame(2000, mb_strlen($this->row($id)['cancel_reason']));
        $this->assertRefused(fn() => Orders::cancel($id, $this->client, 'ще раз'), 'Замовлення вже скасовано');
        $this->assertSame(str_repeat('я', 2000), $this->row($id)['cancel_reason'], 'повтор не переписав причину');

        // Пропозиції статусів не змінюють
        $id = $this->order(OrderStatus::COLLECTING);
        $sent = $this->offer($id, $this->designer, OfferStatus::SENT);
        $withdrawn = $this->offer($id, self::D2, OfferStatus::WITHDRAWN);
        Orders::cancel($id, $this->client, 'Передумав');
        $this->assertSame(OfferStatus::SENT, (int)Sql::value('SELECT status FROM design_offers WHERE id = ?', [$sent]));
        $this->assertSame(OfferStatus::WITHDRAWN, (int)Sql::value('SELECT status FROM design_offers WHERE id = ?', [$withdrawn]));

        // «Скасовано: {причина}» — «чий хід» і рядки Orders
        $this->assertSame('Скасовано: Передумав', OrderStatus::cancelled('Передумав'));
        $this->assertSame('Передумав', Orders::get($id, $this->client)['cancel_reason']);
        $row = array_values(array_filter(Orders::forCustomer($this->client), static fn($o) => $o['id'] === $id))[0];
        $this->assertSame('Передумав', $row['cancel_reason']);
    }

    public function test_cancel_after_purchase_refused(): void
    {
        foreach ([OrderStatus::PURCHASED, OrderStatus::REWORK] as $status) {
            [$id, $offerId] = $this->chosen($status);
            $this->purchase($id, $offerId);
            $this->assertRefused(fn() => Orders::cancel($id, $this->client, 'Не подобається'), 'Скасувати можна лише до першої купівлі');
            $this->assertSame($status, (int)$this->row($id)['status']);
        }
        // Проведена покупка при статусі «В роботі» (захист: статус не мав би таким лишитися) — теж відмова
        [$id, $offerId] = $this->chosen();
        $this->purchase($id, $offerId);
        $this->assertRefused(fn() => Orders::cancel($id, $this->client, 'Не подобається'), 'Скасувати можна лише до першої купівлі');
        // Скасована / неоплачена покупка не заважає
        [$id, $offerId] = $this->chosen();
        $this->purchase($id, $offerId, 3);
        $this->purchase($id, $offerId, 1);
        Orders::cancel($id, $this->client, 'Не подобається');
        $this->assertSame(OrderStatus::CANCELLED, (int)$this->row($id)['status']);
    }

    public function test_cancel_deletes_files_keeps_rows(): void
    {
        [$orderId] = $this->chosen();
        $v1 = Previews::add($orderId, $this->designer, $this->upload(), 'v1', null);
        $v2 = Previews::add($orderId, $this->designer, $this->upload(), 'v2', null);
        Previews::hide($v1['id'], $this->designer);
        $list = Previews::forOrder($orderId);   // url → ImageGlobal::encode створює симлінки i.fastcdn
        $link = static fn(string $url): string => CdnLinksGuard::ROOTS[0] . '/' . substr($url, strpos($url, '/ci/') + 4);
        $links = array_map($link, [$list[$v2['id']]['urls'][0]['view'], $list[$v2['id']]['urls'][0]['card']]);
        foreach ($links as $l) $this->assertTrue(is_link($l));
        $previewDir = $this->root . "/previews/$orderId";
        $this->assertCount(2, glob("$previewDir/*.webp"));

        [$fresh, $freshPath] = $this->layout($orderId);
        [$bought, $boughtPath] = $this->layout($orderId, 999999);   // купленого в скасованому бути не може — не чіпаємо все одно
        [, $missingPath] = $this->layout($orderId, null, false);    // файла вже немає — не помилка
        // Чужий файл у папці макетів (не рядок цього замовлення) — не чіпаємо
        file_put_contents(dirname($freshPath) . '/stranger.txt', 'x');
        // Макети іншого замовлення лишаються
        [$other] = $this->chosen();
        [, $otherPath] = $this->layout($other);

        Orders::cancel($orderId, $this->client, 'Довго');
        clearstatcache();

        $this->assertSame([], glob("$previewDir/*.webp"), "картинки прев'ю видалено");
        foreach ($links as $l) $this->assertFalse(is_link($l), "симлінк i.fastcdn $l видалено");
        $this->assertFalse(is_file($freshPath), 'некуплений макет видалено');
        $this->assertTrue(is_file($boughtPath), 'куплений макет не чіпаємо');
        $this->assertFalse(is_file($missingPath));
        $this->assertTrue(is_file(dirname($freshPath) . '/stranger.txt'));
        $this->assertTrue(is_file($otherPath), 'макети іншого замовлення лишились');

        // Рядки лишаються: прев'ю (з підписами), макети (активні)
        $this->assertSame(2, (int)Sql::value('SELECT COUNT(*) FROM design_previews WHERE order_id = ?', [$orderId]));
        $this->assertSame(3, (int)Sql::value('SELECT COUNT(*) FROM design_files WHERE order_id = ? AND status = ?', [$orderId, Layouts::ACTIVE]));
        $this->assertSame(1, (int)Sql::value('SELECT COUNT(*) FROM design_files WHERE id = ?', [$fresh]));
        $this->assertSame(1, (int)Sql::value('SELECT COUNT(*) FROM design_files WHERE id = ?', [$bought]));

        // Листування показує прев'ю скасованого замовлення як видалене — без адрес (і без нових симлінків)
        $after = Previews::forOrder($orderId);
        $this->assertTrue($after[$v2['id']]['removed']);
        $this->assertSame([], $after[$v2['id']]['urls']);
        $this->assertSame('v2', $after[$v2['id']]['caption']);
        $this->assertTrue($after[$v1['id']]['hidden']);
        clearstatcache();
        foreach ($links as $l) $this->assertFalse(is_link($l));
    }

    public function test_cancel_notifies_designers_and_curator(): void
    {
        if ($this->curator <= 0) $this->markTestSkipped("у клієнта {$this->client} немає куратора");
        // Збір пропозицій: надіслані (25, D2) — так; відкликана (D3) — ні
        $id = $this->order(OrderStatus::COLLECTING);
        $this->offer($id, $this->designer, OfferStatus::SENT);
        $this->offer($id, self::D2, OfferStatus::SENT);
        $this->offer($id, self::D3, OfferStatus::WITHDRAWN);
        Orders::cancel($id, $this->client, "Знайшов\nіншого");
        $this->assertSame([], Notify::$sent, 'дія сама не сповіщає — сторінка після відповіді');
        Orders::notifyCancel($id);

        $designers = array_values(array_filter(Notify::$sent, static fn($n) => isset($n['customer_id'])));
        $curator = array_values(array_filter(Notify::$sent, static fn($n) => isset($n['user_id'])));
        $this->assertEqualsCanonicalizing([$this->designer, self::D2], array_column($designers, 'customer_id'));
        foreach ($designers as $n) {
            $this->assertStringStartsWith("Майстерня дизайну: замовлення №$id скасовано клієнтом. ", $n['msg']);
            $this->assertStringContainsString("/feed/$id/", $n['msg']);
            $this->assertStringNotContainsString('іншого', $n['msg'], 'причину дизайнери не бачать');
            $this->assertSame("Майстерня дизайну: замовлення №$id скасовано клієнтом. design.wolf.ua", $n['msgSMS']);
        }
        $this->assertCount(1, $curator);
        $this->assertSame($this->curator, $curator[0]['user_id']);
        $this->assertStringStartsWith('Майстерня дизайну: клієнт ', $curator[0]['msg']);
        $this->assertStringContainsString("(id {$this->client}) скасував замовлення №$id. Причина: Знайшов іншого", $curator[0]['msg']);
        $this->assertSame("Майстерня дизайну: клієнт скасував №$id. design.wolf.ua", $curator[0]['msgSMS']);

        // В роботі: лише обраний (25), «обрано іншого» (D2) — ні
        Notify::$sent = [];
        [$id] = $this->chosen();
        Orders::cancel($id, $this->client, 'Довго');
        Orders::notifyCancel($id);
        $this->assertSame([$this->designer], array_column(array_filter(Notify::$sent, static fn($n) => isset($n['customer_id'])), 'customer_id'));
        $this->assertCount(1, array_filter(Notify::$sent, static fn($n) => isset($n['user_id'])));

        // Режим 2, чекаємо відповіді: запрошений (25)
        Notify::$sent = [];
        $id = $this->order(OrderStatus::WAITING_DESIGNER, 2);
        $this->offer($id, $this->designer, OfferStatus::INVITED, 0, 0);
        $this->offer($id, self::D2, OfferStatus::DECLINED, 0, 0);
        Orders::cancel($id, $this->client, 'Довго');
        Orders::notifyCancel($id);
        $this->assertSame([$this->designer], array_column(array_filter(Notify::$sent, static fn($n) => isset($n['customer_id'])), 'customer_id'));

        // Чернетка (published_at NULL — її ніхто не бачив): нікому, ні куратору; нескасоване замовлення — нічого
        Notify::$sent = [];
        $id = $this->order(OrderStatus::DRAFT);
        Orders::cancel($id, $this->client, 'Не треба');
        $this->assertNull($this->row($id)['published_at']);
        Orders::notifyCancel($id);
        $this->assertSame([], Notify::$sent, 'скасована чернетка — без сповіщень');
        Notify::$sent = [];
        Orders::notifyCancel($this->order(OrderStatus::COLLECTING));
        $this->assertSame([], Notify::$sent);
    }

    public function test_cancelled_order_read_only(): void
    {
        // В роботі з обраним 25: скасовано → ні листування, ні прев'ю, ні макетів, ні покупки, ні доопрацювання
        [$orderId, $offerId] = $this->chosen();
        $preview = Previews::add($orderId, $this->designer, $this->upload(), 'v1', null);
        [$layoutId] = $this->layout($orderId);
        $before = Sql::one('SELECT inv_sum, credit FROM customers WHERE id = ?', [$this->client]);
        Orders::cancel($orderId, $this->client, 'Довго');

        $this->assertFalse(Offers::access($offerId, $this->client)['write'], 'клієнт не пише');
        $this->assertFalse(Offers::access($offerId, $this->designer)['write'], 'дизайнер не пише');
        $this->assertNull(Previews::canWork($orderId, $this->designer));
        $this->assertRefused(fn() => Previews::add($orderId, $this->designer, $this->upload(), 'v2', null));
        $this->assertRefused(fn() => Previews::hide($preview['id'], $this->designer));
        $this->assertRefused(fn() => Layouts::precheck($orderId, $this->designer, 100));
        $tmp = $this->root . '/l.pdf';
        file_put_contents($tmp, '%PDF');
        $this->assertRefused(fn() => Layouts::add($orderId, $this->designer, $tmp, 'Новий.pdf'));
        $this->assertRefused(fn() => Layouts::rename($layoutId, $this->designer, 'Інший'));
        $this->assertRefused(fn() => Layouts::remove($layoutId, $this->designer));
        $this->assertNull(Purchases::quote($orderId, $this->client), 'вікна оплати немає');
        $this->assertRefused(fn() => Purchases::buy($orderId, $this->client, 1000));
        $this->assertRefused(fn() => Rework::propose($orderId, $this->designer, 900, date('Y-m-d', strtotime('+1 day'))));
        $this->assertRefused(fn() => Rework::cancel($orderId, $this->client));
        $this->assertRefused(fn() => Complaints::add($orderId, $this->client, 'Скарга'));
        $this->assertRefused(fn() => CloudCopy::request($layoutId, $this->client));
        $this->assertSame(1, (int)Sql::value('SELECT COUNT(*) FROM design_previews WHERE order_id = ?', [$orderId]));
        $this->assertSame('Макет', Sql::value('SELECT label FROM design_files WHERE id = ?', [$layoutId]));
        $this->assertSame(0, (int)Sql::value('SELECT COUNT(*) FROM design_purchases WHERE order_id = ?', [$orderId]));
        $this->assertSame($before, Sql::one('SELECT inv_sum, credit FROM customers WHERE id = ?', [$this->client]), 'гроші не рухались');
        $this->assertSame(OrderStatus::CANCELLED, (int)$this->row($orderId)['status']);
        $turn = Workspace::turn(Offers::get($offerId), \Design\Messages::ROLE_CLIENT);
        $this->assertSame('Скасовано: Довго', $turn['text']);
        $this->assertFalse($turn['mine']);
        $this->assertSame('Замовлення скасовано клієнтом', Workspace::turn(Offers::get($offerId), \Design\Messages::ROLE_DESIGNER)['text']);

        // Збір пропозицій: пропозиції на стрічці — ні нової, ні зміни, ні відкликання; клієнт — ні вибору, ні запрошення
        $id = $this->order(OrderStatus::COLLECTING);
        $sent = $this->offer($id, $this->designer, OfferStatus::SENT);
        Orders::cancel($id, $this->client, 'Передумав');
        $this->assertFalse(Offers::access($sent, $this->designer)['write']);
        $this->assertRefused(fn() => Offers::propose($id, self::D2, 'Візьмусь', 500, date('Y-m-d', strtotime('+3 days'))));
        $this->assertRefused(fn() => Offers::update($sent, $this->designer, 'Інакше', 900, date('Y-m-d', strtotime('+3 days'))), 'Замовлення скасовано');
        $this->assertRefused(fn() => Offers::withdraw($sent, $this->designer), 'Замовлення скасовано');
        $this->assertRefused(fn() => Offers::choose($id, $this->client, $sent, 1000));
        $this->assertRefused(fn() => Offers::invite($id, $this->client, self::D2));
        $this->assertRefused(fn() => Offers::collect($id, $this->client));
        $this->assertSame(OfferStatus::SENT, (int)Sql::value('SELECT status FROM design_offers WHERE id = ?', [$sent]));
        $this->assertSame(800, (int)Sql::value('SELECT price_designer FROM design_offers WHERE id = ?', [$sent]));
        $this->assertSame(0, (int)Sql::value('SELECT COUNT(*) FROM design_offers WHERE order_id = ? AND designer_id = ?', [$id, self::D2]));

        // Режим 2: запрошення — ні «Так», ні «Ні»
        $id = $this->order(OrderStatus::WAITING_DESIGNER, 2);
        $invite = $this->offer($id, $this->designer, OfferStatus::INVITED, 0, 0);
        Orders::cancel($id, $this->client, 'Передумав');
        $this->assertRefused(fn() => Offers::accept($invite, $this->designer, 'Беру', 900, date('Y-m-d', strtotime('+3 days'))));
        $this->assertRefused(fn() => Offers::decline($invite, $this->designer, ''));
        $this->assertSame(OfferStatus::INVITED, (int)Sql::value('SELECT status FROM design_offers WHERE id = ?', [$invite]));

        // Чернетка: ні зміни брифу, ні публікації
        $id = $this->order(OrderStatus::DRAFT);
        Orders::cancel($id, $this->client, 'Не треба');
        $this->assertRefused(fn() => Orders::updateDraft($id, $this->client, ['нове'], 'Нова'), '', BriefException::class);
        $this->assertRefused(fn() => Orders::publish($id, $this->client), '', BriefException::class);
        $this->assertSame(OrderStatus::CANCELLED, (int)$this->row($id)['status']);
    }

    /** Повідомлення пишеться під спільним замком рядка замовлення: скасування в процесі — чекає; після — відмова */
    public function test_message_refused_after_cancel_under_order_lock(): void
    {
        [$orderId, $offerId] = $this->chosen();
        $before = \Design\Messages::add($offerId, $this->client, \Design\Messages::ROLE_CLIENT, 'Привіт');
        $this->assertGreaterThan(0, $before);

        // Скасування в іншому з'єднанні тримає FOR UPDATE замовлення: запис повідомлення мусить чекати (тут — тайм-аут 1 с)
        $other = new \PDO(
            'mysql:host=' . \CdnConfig::get('db_host') . ';port=' . \CdnConfig::get('db_port') . ';dbname=w2p;charset=utf8mb4',
            \CdnConfig::get('db_username'), \CdnConfig::get('db_password'), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $other->beginTransaction();
        $other->query("SELECT id FROM design_orders WHERE id = $orderId FOR UPDATE");
        $timeout = (int)Sql::value('SELECT @@SESSION.innodb_lock_wait_timeout');
        Sql::exec('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            \Design\Messages::add($offerId, $this->designer, \Design\Messages::ROLE_DESIGNER, 'Встиг?');
            $this->fail('запис повідомлення мав чекати замок замовлення');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('Lock wait timeout', $e->getMessage());
            $this->assertFalse(Sql::pdo()->inTransaction(), 'власну транзакцію відкочено');
        } finally {
            Sql::exec('SET SESSION innodb_lock_wait_timeout = ' . $timeout);
        }
        $other->exec("UPDATE design_orders SET status = 8, cancel_reason = 'Довго', cancelled_at = NOW() WHERE id = $orderId");
        $other->commit();

        foreach ([[$this->client, \Design\Messages::ROLE_CLIENT], [$this->designer, \Design\Messages::ROLE_DESIGNER]] as [$who, $role]) {
            $this->assertRefused(fn() => \Design\Messages::add($offerId, $who, $role, 'Після скасування'), 'Замовлення скасовано — листування закрите');
        }
        $this->assertSame(1, (int)Sql::value('SELECT COUNT(*) FROM design_messages WHERE offer_id = ?', [$offerId]));

        // Усередині чужої транзакції власної не відкриває
        [, $offer2] = $this->chosen();
        Sql::pdo()->beginTransaction();
        \Design\Messages::add($offer2, $this->client, \Design\Messages::ROLE_CLIENT, 'У транзакції');
        $this->assertTrue(Sql::pdo()->inTransaction());
        Sql::pdo()->rollBack();
        $this->assertSame(0, (int)Sql::value('SELECT COUNT(*) FROM design_messages WHERE offer_id = ?', [$offer2]));
    }

    /** Збій прибирання файлів після коммиту не ламає скасування: статус записано, лише журнал */
    public function test_cleanup_failure_does_not_break_cancel(): void
    {
        [$orderId] = $this->chosen();
        [, $freshPath] = $this->layout($orderId);
        Orders::$afterCommit = static function (): void {
            throw new \RuntimeException('диск недоступний');
        };
        $log = ini_set('error_log', $this->root . '/php.log');
        try {
            Orders::cancel($orderId, $this->client, 'Довго');
        } finally {
            Orders::$afterCommit = null;
            ini_set('error_log', $log === false ? '' : $log);
        }
        $this->assertSame(OrderStatus::CANCELLED, (int)$this->row($orderId)['status']);
        clearstatcache();
        $this->assertFalse(is_file($freshPath), 'збій першого прибирання не пропускає видалення макетів');
        $this->assertStringContainsString("design: прибирання файлів скасованого замовлення $orderId", (string)@file_get_contents($this->root . '/php.log'));
    }

    // ---------- скарга ----------

    public function test_complaint_only_after_purchase(): void
    {
        foreach ([OrderStatus::IN_WORK, OrderStatus::CANCELLED] as $status) {
            [$id, $offerId] = $this->chosen($status);
            $this->assertRefused(fn() => Complaints::add($id, $this->client, 'Погано'), 'Поскаржитися можна після купівлі макетів');
        }
        foreach ([OrderStatus::COLLECTING, OrderStatus::WAITING_DESIGNER] as $status) {
            $id = $this->order($status);
            $this->assertRefused(fn() => Complaints::add($id, $this->client, 'Погано'), 'Поскаржитися можна після купівлі макетів');
        }

        [$id, $offerId] = $this->chosen(OrderStatus::PURCHASED);
        $this->assertNull(Complaints::lastForOrder($id));
        // «Куплено» без проведеної покупки (лише неоплачена) — немає до чого прив'язати: відмова
        $this->purchase($id, $offerId, 1);
        $this->assertRefused(fn() => Complaints::add($id, $this->client, 'Погано'), 'Поскаржитися можна після купівлі макетів');
        $first = $this->purchase($id, $offerId);
        $last = $this->purchase($id, $offerId);
        $this->purchase($id, $offerId, 3);

        $this->assertRefused(fn() => Complaints::add($id, $this->client, "  \n "), 'Опишіть скаргу');
        $this->assertRefused(fn() => Complaints::add($id, $this->client, str_repeat('я', 2001)), 'Скарга — не довше 2000 символів');
        $this->assertRefused(fn() => Complaints::add($id, $this->designer, 'Погано'), 'Замовлення не знайдено');
        $this->assertSame(0, (int)Sql::value('SELECT COUNT(*) FROM design_complaints WHERE order_id = ?', [$id]));

        $cid = Complaints::add($id, $this->client, "  Макет з помилкою\nу телефоні  ");
        $row = Sql::one('SELECT order_id, purchase_id, text, status, created FROM design_complaints WHERE id = ?', [$cid]);
        $this->assertSame($id, (int)$row['order_id']);
        $this->assertSame($last, (int)$row['purchase_id'], 'остання проведена покупка');
        $this->assertNotSame($first, (int)$row['purchase_id']);
        $this->assertSame("Макет з помилкою\nу телефоні", $row['text']);
        $this->assertSame(1, (int)$row['status']);
        $this->assertSame(OrderStatus::PURCHASED, (int)$this->row($id)['status'], 'замовлення лишається «Куплено»');

        // Повторна — можна; lastForOrder — найсвіжіша
        Sql::exec('UPDATE design_complaints SET created = NOW() - INTERVAL 1 DAY WHERE id = ?', [$cid]);
        $second = Complaints::add($id, $this->client, str_repeat('я', 2000));
        $this->assertGreaterThan($cid, $second);
        $this->assertSame(Sql::value('SELECT created FROM design_complaints WHERE id = ?', [$second]), Complaints::lastForOrder($id)['created']);

        // Доопрацювання — теж можна
        [$id, $offerId] = $this->chosen(OrderStatus::REWORK);
        $this->purchase($id, $offerId);
        $this->assertGreaterThan(0, Complaints::add($id, $this->client, 'Довго'));
    }

    public function test_complaint_notifies_curator_and_user1(): void
    {
        if ($this->curator <= 0) $this->markTestSkipped("у клієнта {$this->client} немає куратора");
        [$id, $offerId] = $this->chosen(OrderStatus::PURCHASED);
        $this->purchase($id, $offerId);
        $cid = Complaints::add($id, $this->client, "Макет з помилкою\nу телефоні");
        $this->assertSame([], Notify::$sent, 'дія сама не сповіщає — сторінка після відповіді');
        Complaints::notify($cid);

        $this->assertEqualsCanonicalizing([$this->curator, 1], array_column(Notify::$sent, 'user_id'));
        $this->assertSame([], array_column(Notify::$sent, 'customer_id'), 'дизайнеру — ні');
        foreach (Notify::$sent as $n) {
            $this->assertStringStartsWith("Майстерня дизайну: скарга на замовлення №$id від клієнта ", $n['msg']);
            $this->assertStringContainsString("(id {$this->client}): Макет з помилкою у телефоні", $n['msg']);
            $this->assertSame("Майстерня дизайну: скарга по №$id. design.wolf.ua", $n['msgSMS']);
        }
        Notify::$sent = [];
        Complaints::notify(999999999);
        $this->assertSame([], Notify::$sent);
    }
}
