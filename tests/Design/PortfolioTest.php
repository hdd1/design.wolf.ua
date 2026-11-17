<?php

use Design\DesignerException;
use Design\Designers;
use Design\DesignerStatus;
use Design\Portfolio;
use Design\Sql;
use PHPUnit\Framework\TestCase;

/** @group db */
final class PortfolioTest extends TestCase
{
    private const A = 999999201;
    private const B = 999999202;

    private string $root;
    /** @var string|false */
    private $oldRoot;

    protected function setUp(): void
    {
        $this->oldRoot = getenv('DESIGN_STORAGE_ROOT');
        $this->root = sys_get_temp_dir() . '/design-portfolio-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
        putenv('DESIGN_STORAGE_ROOT=' . $this->root);
        $this->wipe();
    }

    protected function tearDown(): void
    {
        $this->wipe();
        self::rmTree($this->root);
        putenv($this->oldRoot === false ? 'DESIGN_STORAGE_ROOT' : 'DESIGN_STORAGE_ROOT=' . $this->oldRoot);
    }

    private function wipe(): void
    {
        $ids = [self::A, self::B];
        Sql::exec('DELETE FROM design_portfolio WHERE designer_id IN (?, ?)', $ids);
        Sql::exec('DELETE FROM design_designers WHERE customer_id IN (?, ?)', $ids);
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

    private function designer(int $cid, int $status): void
    {
        Sql::insert('design_designers', ['customer_id' => $cid, 'status' => $status, 'name' => 'Тест']);
    }

    private function image(string $name = 'src.jpg', int $w = 800, int $h = 600): string
    {
        $im = new Imagick();
        $im->newPseudoImage($w, $h, 'gradient:red-blue');
        $im->setImageFormat('jpeg');
        $path = $this->root . '/' . $name;
        $im->writeImage("jpeg:$path");
        $im->clear();
        return $path;
    }

    /** @return int[] id доданих робіт */
    private function addMany(int $cid, int $n): array
    {
        $src = $this->image();
        $ids = [];
        for ($i = 0; $i < $n; $i++) $ids[] = Portfolio::add($cid, $src)['id'];
        return $ids;
    }

    private function assertFails(string $message, callable $fn): void
    {
        try {
            $fn();
        } catch (DesignerException $e) {
            $this->assertSame($message, $e->getMessage());
            return;
        }
        $this->fail("Очікувалась помилка: $message");
    }

    private function files(int $cid): array
    {
        $dir = Portfolio::dir($cid);
        return array_values(array_diff(scandir($dir), ['.', '..']));
    }

    public function test_add_stores_files_and_row(): void
    {
        $this->designer(self::A, DesignerStatus::DRAFT);
        $id = Portfolio::add(self::A, $this->image())['id'];

        $this->assertGreaterThan(0, $id);
        $dir = $this->root . '/portfolio/' . self::A;
        $this->assertSame($dir, Portfolio::dir(self::A));
        $this->assertFileExists("$dir/$id.jpg");
        $this->assertFileExists("$dir/$id-card.webp");
        $this->assertFileExists("$dir/$id-view.webp");
        $row = Sql::one('SELECT * FROM design_portfolio WHERE id = ?', [$id]);
        $this->assertSame("$id.jpg", $row['file']);
        $this->assertSame(800, (int)$row['width']);
        $this->assertSame(600, (int)$row['height']);
        $this->assertSame(self::A, (int)$row['designer_id']);

        $second = Portfolio::add(self::A, $this->image())['id'];
        $all = Portfolio::all(self::A);
        $this->assertSame([$id, $second], array_column($all, 'id'));
        $this->assertSame([1, 2], array_column($all, 'pos'));
        $this->assertTrue($all[0]['shw']);
        $this->assertSame('', $all[0]['title']);
        $this->assertSame(800, $all[0]['width']);
        $this->assertSame(2, Portfolio::count(self::A));
    }

    public function test_failed_store_leaves_no_row(): void
    {
        $this->designer(self::A, DesignerStatus::DRAFT);
        $bad = $this->root . '/bad.jpg';
        file_put_contents($bad, 'це не картинка');

        $this->expectException(DesignerException::class);
        try {
            Portfolio::add(self::A, $bad);
        } finally {
            $this->assertSame(0, Portfolio::count(self::A));
            $this->assertSame([], $this->files(self::A));
        }
    }

    public function test_apply_limit_10_and_approved_limit_30(): void
    {
        $this->assertSame(10, Designers::APPLY_MAX);
        $this->designer(self::A, DesignerStatus::DRAFT);
        $this->addMany(self::A, 10);
        $this->assertFails('Уже 10 робіт — це максимум', fn() => Portfolio::add(self::A, $this->image()));
        $this->assertSame(10, Portfolio::count(self::A));

        Sql::exec('UPDATE design_designers SET status = ? WHERE customer_id = ?', [DesignerStatus::REJECTED, self::A]);
        $this->assertFails('Уже 10 робіт — це максимум', fn() => Portfolio::add(self::A, $this->image()));

        Sql::exec('UPDATE design_designers SET status = ? WHERE customer_id = ?', [DesignerStatus::APPROVED, self::A]);
        $this->addMany(self::A, 20);
        $this->assertSame(30, Portfolio::count(self::A));
        $this->assertFails('Уже 30 робіт — це максимум', fn() => Portfolio::add(self::A, $this->image()));
        $this->assertSame(30, Portfolio::count(self::A));
    }

    public function test_pending_and_blocked_are_read_only(): void
    {
        $this->designer(self::A, DesignerStatus::DRAFT);
        [$id, $id2] = $this->addMany(self::A, 2);
        $src = $this->image();

        foreach ([DesignerStatus::PENDING, DesignerStatus::BLOCKED] as $status) {
            Sql::exec('UPDATE design_designers SET status = ? WHERE customer_id = ?', [$status, self::A]);
            $msg = 'Зараз роботи не можна змінювати';
            $this->assertFails($msg, fn() => Portfolio::add(self::A, $src));
            $this->assertFails($msg, fn() => Portfolio::remove(self::A, $id));
            $this->assertFails($msg, fn() => Portfolio::setShw(self::A, $id, false));
            $this->assertFails($msg, fn() => Portfolio::setTitle(self::A, $id, 'Назва'));
            $this->assertFails($msg, fn() => Portfolio::reorder(self::A, [$id2, $id]));
            // читати можна
            $this->assertSame([$id, $id2], array_column(Portfolio::all(self::A), 'id'));
        }
        $this->assertSame(2, Portfolio::count(self::A));
        $this->assertTrue(Portfolio::all(self::A)[0]['shw']);
    }

    public function test_no_designer_row_is_read_only(): void
    {
        $this->assertFails('Зараз роботи не можна змінювати', fn() => Portfolio::add(self::A, $this->image()));
        $this->assertSame([], Portfolio::all(self::A));
    }

    public function test_remove_deletes_files(): void
    {
        $this->designer(self::A, DesignerStatus::DRAFT);
        [$id, $keep] = $this->addMany(self::A, 2);
        Portfolio::remove(self::A, $id);

        $dir = Portfolio::dir(self::A);
        $this->assertFileDoesNotExist("$dir/$id.jpg");
        $this->assertFileDoesNotExist("$dir/$id-card.webp");
        $this->assertFileDoesNotExist("$dir/$id-view.webp");
        $this->assertFileExists("$dir/$keep.jpg");
        $this->assertSame([$keep], array_column(Portfolio::all(self::A), 'id'));
    }

    public function test_foreign_work_rejected(): void
    {
        $this->designer(self::A, DesignerStatus::DRAFT);
        $this->designer(self::B, DesignerStatus::DRAFT);
        [$mine] = $this->addMany(self::A, 1);
        [$foreign] = $this->addMany(self::B, 1);

        $msg = 'Роботу не знайдено';
        $this->assertFails($msg, fn() => Portfolio::remove(self::A, $foreign));
        $this->assertFails($msg, fn() => Portfolio::setShw(self::A, $foreign, false));
        $this->assertFails($msg, fn() => Portfolio::setTitle(self::A, $foreign, 'Чуже'));
        $this->assertFails($msg, fn() => Portfolio::remove(self::A, 0));

        $row = Portfolio::all(self::B)[0];
        $this->assertSame($foreign, $row['id']);
        $this->assertTrue($row['shw']);
        $this->assertSame('', $row['title']);
        $this->assertFileExists(Portfolio::dir(self::B) . "/$foreign.jpg");
        $this->assertSame([$mine], array_column(Portfolio::all(self::A), 'id'));
    }

    public function test_reorder_needs_full_set(): void
    {
        $this->designer(self::A, DesignerStatus::DRAFT);
        $this->designer(self::B, DesignerStatus::DRAFT);
        [$a, $b, $c] = $this->addMany(self::A, 3);
        [$foreign] = $this->addMany(self::B, 1);

        Portfolio::reorder(self::A, [$c, $a, $b]);
        $all = Portfolio::all(self::A);
        $this->assertSame([$c, $a, $b], array_column($all, 'id'));
        $this->assertSame([1, 2, 3], array_column($all, 'pos'));

        $msg = 'Список робіт змінився — оновіть сторінку';
        $this->assertFails($msg, fn() => Portfolio::reorder(self::A, [$a, $b]));
        $this->assertFails($msg, fn() => Portfolio::reorder(self::A, [$a, $b, $c, $foreign]));
        $this->assertFails($msg, fn() => Portfolio::reorder(self::A, [$a, $b, $foreign]));
        $this->assertFails($msg, fn() => Portfolio::reorder(self::A, [$a, $a, $b]));
        $this->assertSame([$c, $a, $b], array_column(Portfolio::all(self::A), 'id'));
    }

    public function test_visible_skips_hidden(): void
    {
        $this->designer(self::A, DesignerStatus::DRAFT);
        [$a, $b, $c] = $this->addMany(self::A, 3);
        Portfolio::setShw(self::A, $b, false);

        $this->assertSame([$a, $c], array_column(Portfolio::visible(self::A), 'id'));
        $this->assertSame([$a, $b, $c], array_column(Portfolio::all(self::A), 'id'));
        $this->assertSame(3, Portfolio::count(self::A));

        Portfolio::setShw(self::A, $b, true);
        $this->assertCount(3, Portfolio::visible(self::A));
    }

    public function test_set_title_trims_and_limits(): void
    {
        $this->designer(self::A, DesignerStatus::DRAFT);
        [$id] = $this->addMany(self::A, 1);

        Portfolio::setTitle(self::A, $id, "  Логотип кав'ярні \n");
        $this->assertSame("Логотип кав'ярні", Portfolio::all(self::A)[0]['title']);

        Portfolio::setTitle(self::A, $id, str_repeat('я', 120));
        $this->assertSame(120, mb_strlen(Portfolio::all(self::A)[0]['title']));
        $this->assertFails('Назва — не більше 120 символів', fn() => Portfolio::setTitle(self::A, $id, str_repeat('я', 121)));

        Portfolio::setTitle(self::A, $id, '');
        $this->assertSame('', Portfolio::all(self::A)[0]['title']);
    }

    public function test_approved_can_edit(): void
    {
        $this->designer(self::A, DesignerStatus::APPROVED);
        [$a, $b] = $this->addMany(self::A, 2);
        Portfolio::setShw(self::A, $a, false);
        Portfolio::reorder(self::A, [$b, $a]);
        $this->assertSame([$b], array_column(Portfolio::visible(self::A), 'id'));
        Portfolio::remove(self::A, $a);
        $this->assertSame(1, Portfolio::count(self::A));
    }

    /** Рядок, який зараз обробляється (Images::store ще працює): file = '' */
    private function inFlight(int $cid, int $n = 1): void
    {
        for ($i = 0; $i < $n; $i++) Sql::insert('design_portfolio', ['designer_id' => $cid, 'file' => '', 'pos' => 99]);
    }

    public function test_in_flight_rows_are_hidden_but_count_for_limit(): void
    {
        $this->designer(self::A, DesignerStatus::DRAFT);
        [$a, $b] = $this->addMany(self::A, 2);
        $this->inFlight(self::A);
        $flightId = (int)Sql::value("SELECT id FROM design_portfolio WHERE designer_id = ? AND file = ''", [self::A]);

        $this->assertSame([$a, $b], array_column(Portfolio::all(self::A), 'id'));
        $this->assertSame([$a, $b], array_column(Portfolio::visible(self::A), 'id'));
        $this->assertSame(2, Portfolio::count(self::A));

        // reorder бачить лише готові роботи, а до робіт у обробці не дістатися
        Portfolio::reorder(self::A, [$b, $a]);
        $this->assertSame([$b, $a], array_column(Portfolio::all(self::A), 'id'));
        $this->assertFails('Роботу не знайдено', fn() => Portfolio::remove(self::A, $flightId));
        $this->assertFails('Роботу не знайдено', fn() => Portfolio::setShw(self::A, $flightId, false));
        $this->assertSame(1, (int)Sql::value('SELECT COUNT(*) FROM design_portfolio WHERE id = ?', [$flightId]));

        // 2 готові + 8 у обробці = 10: паралельні завантаження ліміт не обходять
        $this->inFlight(self::A, 7);
        $this->assertSame(2, Portfolio::count(self::A));
        $this->assertFails('Уже 10 робіт — це максимум', fn() => Portfolio::add(self::A, $this->image()));
    }
}
