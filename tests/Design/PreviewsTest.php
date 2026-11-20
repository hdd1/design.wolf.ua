<?php

use Design\DesignerException;
use Design\DesignerStatus;
use Design\Messages;
use Design\Notify;
use Design\OfferStatus;
use Design\OrderStatus;
use Design\Previews;
use Design\Products;
use Design\Settings;
use Design\Sql;
use Design\Workspace;
use PHPUnit\Framework\TestCase;

/**
 * Прев'ю: версії vN замовлення в роботі, повідомлення з preview_id, «на основі», приховування, сповіщення клієнта.
 * Клієнт і дизайнери — фіктивні customer_id 999999801+; замовлення й пропозиції тест вставляє сам (id — автоінкремент).
 * DESIGN_WORK_ROOT — тимчасова папка; forOrder будує адреси i.fastcdn (симлінки) — їх прибирає CdnLinksGuard
 * @group db
 */
final class PreviewsTest extends TestCase
{
    private const CLIENT = 999999801;
    private const A = 999999802;          // обраний дизайнер
    private const B = 999999803;          // інший схвалений дизайнер
    private const IDS = [self::CLIENT, self::A, self::B];

    private string $root;
    private string|false $oldRoot;
    private array $links;

    protected function setUp(): void
    {
        Notify::$sent = [];
        Settings::reset();
        $this->links = CdnLinksGuard::snapshot();
        $this->cleanup();
        Sql::insert('design_designers', ['customer_id' => self::A, 'status' => DesignerStatus::APPROVED, 'name' => 'Петро Дизайнер']);
        Sql::insert('design_designers', ['customer_id' => self::B, 'status' => DesignerStatus::APPROVED, 'name' => 'Оксана']);
        $this->oldRoot = getenv('DESIGN_WORK_ROOT');
        $this->root = sys_get_temp_dir() . '/design-previews-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
        putenv('DESIGN_WORK_ROOT=' . $this->root);
    }

    protected function tearDown(): void
    {
        Previews::$beforeRender = null;
        putenv($this->oldRoot === false ? 'DESIGN_WORK_ROOT' : 'DESIGN_WORK_ROOT=' . $this->oldRoot);
        self::rmTree($this->root);
        $this->cleanup();
        CdnLinksGuard::cleanup($this->links);
        Notify::$sent = [];
    }

    private function cleanup(): void
    {
        $in = implode(',', self::IDS);
        Sql::exec("DELETE p FROM design_previews p JOIN design_orders d ON d.id = p.order_id WHERE d.customer_id IN ($in)");
        Sql::exec("DELETE m FROM design_messages m JOIN design_offers o ON o.id = m.offer_id
                   LEFT JOIN design_orders d ON d.id = o.order_id WHERE o.designer_id IN ($in) OR d.customer_id IN ($in)");
        Sql::exec("DELETE o FROM design_offers o LEFT JOIN design_orders d ON d.id = o.order_id
                   WHERE o.designer_id IN ($in) OR d.customer_id IN ($in)");
        Sql::exec("DELETE FROM design_orders WHERE customer_id IN ($in)");
        Sql::exec("DELETE FROM design_designers WHERE customer_id IN ($in)");
    }

    /** Замовлення «В роботі» з обраною пропозицією дизайнера A (і надісланою «обрано іншого» від B) → [orderId, offerId] */
    private function inWork(): array
    {
        $orderId = Sql::insert('design_orders', [
            'customer_id' => self::CLIENT,
            'product_id' => Products::visible()[0]['id'],
            'status' => OrderStatus::IN_WORK,
            'answers' => '[]',
        ]);
        $offerId = Sql::insert('design_offers', ['order_id' => $orderId, 'designer_id' => self::A, 'status' => OfferStatus::CHOSEN]);
        Sql::insert('design_offers', ['order_id' => $orderId, 'designer_id' => self::B, 'status' => OfferStatus::OTHER_CHOSEN]);
        Sql::exec('UPDATE design_orders SET chosen_offer_id = ?, chosen_at = NOW() WHERE id = ?', [$offerId, $orderId]);
        return [$orderId, $offerId];
    }

    /** Тимчасовий JPG «як після склеювання» */
    private function upload(string $color = '#3366cc'): string
    {
        $im = new \Imagick();
        $im->newImage(300, 200, new \ImagickPixel($color));
        $im->setImageFormat('jpeg');
        $path = $this->root . '/upload-' . bin2hex(random_bytes(4)) . '.jpg';
        $im->writeImage($path);
        $im->clear();
        return $path;
    }

    private function files(int $orderId): array
    {
        $files = glob($this->root . "/previews/$orderId/*") ?: [];
        return array_map('basename', $files);
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

    public function test_add_numbers_and_message(): void
    {
        [$orderId, $offerId] = $this->inWork();
        $tmp = $this->upload();
        $first = Previews::add($orderId, self::A, $tmp, "  Перший варіант <b>&</b>  ", null);
        $this->assertFileDoesNotExist($tmp, 'тимчасовий файл видалено');
        $second = Previews::add($orderId, self::A, $this->upload('#cc3333'), '', null);

        $this->assertSame([1, 2], [$first['num'], $second['num']]);
        $row = Sql::one('SELECT * FROM design_previews WHERE id = ?', [$first['id']]);
        $this->assertSame($orderId, (int)$row['order_id']);
        $this->assertSame($offerId, (int)$row['offer_id']);
        $this->assertSame($first['message_id'], (int)$row['message_id']);
        $this->assertSame(1, (int)$row['pages']);
        $this->assertSame('image', $row['source_type']);
        $this->assertNull($row['based_on_id']);
        $this->assertNull($row['hidden_at']);

        // прев'ю = повідомлення дизайнера з preview_id; підпис — текст (обрізаний), порожній підпис дозволено
        $msgs = array_column(Messages::list($offerId), null, 'id');
        $this->assertSame(Messages::ROLE_DESIGNER, $msgs[$first['message_id']]['role']);
        $this->assertSame(self::A, $msgs[$first['message_id']]['author_id']);
        $this->assertSame('Перший варіант <b>&</b>', $msgs[$first['message_id']]['text']);
        $this->assertSame($first['id'], $msgs[$first['message_id']]['preview_id']);
        $this->assertSame('', $msgs[$second['message_id']]['text']);
        $this->assertSame($second['id'], $msgs[$second['message_id']]['preview_id']);
        $this->assertNull($msgs[$second['message_id']]['reply_to_id']);

        // файли: view і card на сторінку, 0644, папка замовлення 0755
        $this->assertEqualsCanonicalizing([
            "{$first['id']}-p1-view.webp", "{$first['id']}-p1-card.webp",
            "{$second['id']}-p1-view.webp", "{$second['id']}-p1-card.webp",
        ], $this->files($orderId));
        $this->assertSame(0644, fileperms($this->root . "/previews/$orderId/{$first['id']}-p1-view.webp") & 0777);
        $this->assertSame(0755, fileperms($this->root . "/previews/$orderId") & 0777);

        // forOrder: ключ — id, за номером; по сторінці — адреси view і card
        $list = Previews::forOrder($orderId);
        $this->assertSame([$first['id'], $second['id']], array_keys($list));
        $this->assertSame(['id', 'num', 'pages', 'hidden', 'removed', 'based_on_num', 'caption', 'urls'], array_keys($list[$first['id']]));
        $this->assertSame('Перший варіант <b>&</b>', $list[$first['id']]['caption']);
        $this->assertFalse($list[$first['id']]['hidden']);
        $this->assertFalse($list[$first['id']]['removed'], 'замовлення не скасоване');
        $this->assertNull($list[$first['id']]['based_on_num']);
        $this->assertCount(1, $list[$first['id']]['urls']);
        $this->assertMatchesRegularExpression('~^https://\S+\.webp$~', $list[$first['id']]['urls'][0]['view']);
        $this->assertMatchesRegularExpression('~^https://\S+\.webp$~', $list[$first['id']]['urls'][0]['card']);
        $this->assertNotSame($list[$first['id']]['urls'][0]['view'], $list[$first['id']]['urls'][0]['card']);

        // файлу сторінки немає (видалили вручну) — адреси немає, заглушку encode не підставляємо
        unlink($this->root . "/previews/$orderId/{$second['id']}-p1-card.webp");
        $this->assertSame('', Previews::forOrder($orderId)[$second['id']]['urls'][0]['card']);

        // нумерація — по замовленню: інше замовлення починає з v1
        [$other] = $this->inWork();
        $this->assertSame(1, Previews::add($other, self::A, $this->upload(), 'Інше', null)['num']);
    }

    public function test_only_chosen_designer_in_work(): void
    {
        [$orderId, $offerId] = $this->inWork();
        $this->assertSame($offerId, Previews::canWork($orderId, self::A)['id']);
        $this->assertSame(self::CLIENT, Previews::canWork($orderId, self::A)['customer_id']);

        $denied = function (int $who, string $why) use ($orderId): void {
            $this->assertNull(Previews::canWork($orderId, $who), $why);
            $tmp = $this->upload();
            try {
                Previews::add($orderId, $who, $tmp, 'Спроба', null);
                $this->fail("add має відмовити: $why");
            } catch (DesignerException $e) {
                $this->assertSame("Додавати й приховувати прев'ю може лише обраний дизайнер замовлення в роботі", $e->getMessage(), $why);
            }
            $this->assertFileDoesNotExist($tmp, "тимчасовий файл видалено і при відмові: $why");
        };

        $denied(self::CLIENT, 'клієнт');
        $denied(self::B, 'інший дизайнер');
        $this->assertNull(Previews::canWork($orderId + 1000000, self::A), 'немає замовлення');

        foreach ([OrderStatus::COLLECTING, OrderStatus::PURCHASED, OrderStatus::CANCELLED] as $status) {
            Sql::exec('UPDATE design_orders SET status = ? WHERE id = ?', [$status, $orderId]);
            $denied(self::A, "замовлення в статусі $status");
        }
        Sql::exec('UPDATE design_orders SET status = ? WHERE id = ?', [OrderStatus::IN_WORK, $orderId]);

        // обрана пропозиція не в статусі «обрано» (неузгоджені дані) — теж ні
        Sql::exec('UPDATE design_offers SET status = ? WHERE id = ?', [OfferStatus::SENT, $offerId]);
        $denied(self::A, 'пропозиція не обрана');
        Sql::exec('UPDATE design_offers SET status = ? WHERE id = ?', [OfferStatus::CHOSEN, $offerId]);

        foreach ([DesignerStatus::BLOCKED, DesignerStatus::PENDING] as $status) {
            Sql::exec('UPDATE design_designers SET status = ? WHERE customer_id = ?', [$status, self::A]);
            $denied(self::A, "дизайнер у статусі $status");
        }
        Sql::exec('UPDATE design_designers SET status = ? WHERE customer_id = ?', [DesignerStatus::APPROVED, self::A]);

        $this->assertSame(0, (int)Sql::value('SELECT COUNT(*) FROM design_previews WHERE order_id = ?', [$orderId]));
        $this->assertSame([], Messages::list($offerId));
        $this->assertSame([], $this->files($orderId));
        $this->assertSame([], Notify::$sent);

        // і після всього — обраний схвалений дизайнер може
        $this->assertSame(1, Previews::add($orderId, self::A, $this->upload(), '', null)['num']);
    }

    public function test_failed_render_leaves_nothing(): void
    {
        [$orderId, $offerId] = $this->inWork();
        $bad = $this->root . '/broken.jpg';
        file_put_contents($bad, 'це не картинка');
        try {
            Previews::add($orderId, self::A, $bad, 'Зламане', null);
            $this->fail('Зламаний файл має відхилятись');
        } catch (DesignerException $e) {
            $this->assertSame('Файл пошкоджений або це не картинка', $e->getMessage());
        }
        $this->assertFileDoesNotExist($bad);
        $this->assertSame(0, (int)Sql::value('SELECT COUNT(*) FROM design_previews WHERE order_id = ?', [$orderId]));
        $this->assertSame([], Messages::list($offerId), 'повідомлення відкочено');
        $this->assertSame([], $this->files($orderId));
        $this->assertSame([], Notify::$sent, 'сповіщення лише після коммиту');

        // задовгий підпис — теж нічого не лишає
        $tmp = $this->upload();
        try {
            Previews::add($orderId, self::A, $tmp, str_repeat('я', Messages::TEXT_MAX + 1), null);
            $this->fail('Задовгий підпис має відхилятись');
        } catch (DesignerException $e) {
            $this->assertSame('Підпис — не більше 2000 символів', $e->getMessage());
        }
        $this->assertFileDoesNotExist($tmp);
        $this->assertSame(0, (int)Sql::value('SELECT COUNT(*) FROM design_previews WHERE order_id = ?', [$orderId]));
        $this->assertSame([], Messages::list($offerId));

        // невдалі спроби номер не з'їдають
        $this->assertSame(1, Previews::add($orderId, self::A, $this->upload(), str_repeat('я', Messages::TEXT_MAX), null)['num']);
    }

    public function test_based_on_must_be_visible_same_order(): void
    {
        [$orderId] = $this->inWork();
        [$otherOrder] = $this->inWork();
        $v1 = Previews::add($orderId, self::A, $this->upload(), 'v1', null);
        $v2 = Previews::add($orderId, self::A, $this->upload(), 'v2', null);
        $foreign = Previews::add($otherOrder, self::A, $this->upload(), 'чуже', null);
        Previews::hide($v1['id'], self::A);

        foreach (['приховане' => $v1['id'], 'іншого замовлення' => $foreign['id'], 'немає' => $v2['id'] + 1000000] as $why => $basedOn) {
            $tmp = $this->upload();
            try {
                Previews::add($orderId, self::A, $tmp, 'на основі', $basedOn);
                $this->fail("based_on $why має відхилятись");
            } catch (DesignerException $e) {
                $this->assertSame("Версію, на основі якої зроблено прев'ю, не знайдено", $e->getMessage(), $why);
            }
            $this->assertFileDoesNotExist($tmp);
        }
        $this->assertSame(2, (int)Sql::value('SELECT COUNT(*) FROM design_previews WHERE order_id = ?', [$orderId]));

        $v3 = Previews::add($orderId, self::A, $this->upload(), 'на основі v2', $v2['id']);
        $this->assertSame(3, $v3['num']);
        $this->assertSame($v2['id'], (int)Sql::value('SELECT based_on_id FROM design_previews WHERE id = ?', [$v3['id']]));
        $list = Previews::forOrder($orderId);
        $this->assertSame(2, $list[$v3['id']]['based_on_num']);
        $this->assertNull($list[$v2['id']]['based_on_num']);
    }

    public function test_hide_deletes_files_keeps_caption(): void
    {
        [$orderId, $offerId] = $this->inWork();
        $v1 = Previews::add($orderId, self::A, $this->upload(), 'Темний фон', null);
        $v2 = Previews::add($orderId, self::A, $this->upload(), 'Світлий фон', null);

        foreach ([self::CLIENT => 'клієнт', self::B => 'інший дизайнер'] as $who => $why) {
            try {
                Previews::hide($v1['id'], $who);
                $this->fail("hide має відмовити: $why");
            } catch (DesignerException $e) {
                $this->assertSame("Додавати й приховувати прев'ю може лише обраний дизайнер замовлення в роботі", $e->getMessage());
            }
        }
        try {
            Previews::hide($v2['id'] + 1000000, self::A);
            $this->fail('Неіснуюче прев\'ю');
        } catch (DesignerException $e) {
            $this->assertSame("Додавати й приховувати прев'ю може лише обраний дизайнер замовлення в роботі", $e->getMessage(),
                'неіснуюче — та сама відповідь, що й «немає доступу»: id не перебрати');
        }
        $this->assertNull(Sql::value('SELECT hidden_at FROM design_previews WHERE id = ?', [$v1['id']]));

        Previews::hide($v1['id'], self::A);
        $hiddenAt = Sql::value('SELECT hidden_at FROM design_previews WHERE id = ?', [$v1['id']]);
        $this->assertNotNull($hiddenAt);
        $this->assertEqualsCanonicalizing(["{$v2['id']}-p1-view.webp", "{$v2['id']}-p1-card.webp"], $this->files($orderId),
            'файли v1 видалено, v2 не чіпали');

        $list = Previews::forOrder($orderId);
        $this->assertTrue($list[$v1['id']]['hidden']);
        $this->assertSame([], $list[$v1['id']]['urls']);
        $this->assertSame('Темний фон', $list[$v1['id']]['caption'], 'підпис лишається');
        $this->assertSame(1, $list[$v1['id']]['num']);
        $this->assertFalse($list[$v2['id']]['hidden']);
        $this->assertSame(1, (int)Sql::value('SELECT COUNT(*) FROM design_messages WHERE preview_id = ? AND offer_id = ?', [$v1['id'], $offerId]),
            'повідомлення лишається');

        // повтор — нічого не змінює
        Sql::exec("UPDATE design_previews SET hidden_at = '2026-01-02 03:04:05' WHERE id = ?", [$v1['id']]);
        Previews::hide($v1['id'], self::A);
        $this->assertSame('2026-01-02 03:04:05', Sql::value('SELECT hidden_at FROM design_previews WHERE id = ?', [$v1['id']]));

        // наступна версія — v3: номер прихованої не перевикористовується
        $this->assertSame(3, Previews::add($orderId, self::A, $this->upload(), '', null)['num']);
    }

    /**
     * Приховане прев'ю: симлінки i.fastcdn на його видалені файли теж видаляються (не копляться биті), а посилання
     * інших прев'ю й симлінк на тому ж місці, що веде не на файл цього прев'ю, — не чіпаються
     */
    public function test_hide_removes_cdn_links_of_its_files_only(): void
    {
        [$orderId] = $this->inWork();
        $v1 = Previews::add($orderId, self::A, $this->upload(), 'v1', null);
        $v2 = Previews::add($orderId, self::A, $this->upload(), 'v2', null);
        $v3 = Previews::add($orderId, self::A, $this->upload(), 'v3', null);
        $list = Previews::forOrder($orderId);   // url → ImageGlobal::encode створює симлінки
        $link = static fn(string $url): string => CdnLinksGuard::ROOTS[0] . '/' . substr($url, strpos($url, '/ci/') + 4);
        $links = static fn(array $p): array => array_map($link, [$p['urls'][0]['view'], $p['urls'][0]['card']]);
        $file = fn(int $id, string $size): string => $this->root . "/previews/$orderId/$id-p1-$size.webp";
        foreach ([$v1, $v2, $v3] as $v) {
            foreach ($links($list[$v['id']]) as $i => $l) {
                $this->assertTrue(is_link($l));
                $this->assertSame($file($v['id'], $i === 0 ? 'view' : 'card'), readlink($l));
            }
        }
        // На місці посилання картки v3 — симлінк на інший файл: його hide не чіпає
        [$v3view, $v3card] = $links($list[$v3['id']]);
        unlink($v3card);
        symlink($file($v2['id'], 'card'), $v3card);

        Previews::hide($v1['id'], self::A);
        Previews::hide($v3['id'], self::A);
        clearstatcache();
        foreach ($links($list[$v1['id']]) as $l) $this->assertFalse(is_link($l), "посилання $l на файл прихованого прев'ю видалено");
        $this->assertFalse(is_link($v3view));
        $this->assertTrue(is_link($v3card), 'чужий симлінк на тому ж місці лишився');
        $this->assertSame($file($v2['id'], 'card'), readlink($v3card));
        foreach ($links($list[$v2['id']]) as $l) $this->assertTrue(is_link($l), 'посилання неприхованого прев\'ю лишились');
        $this->assertNotSame([], Previews::forOrder($orderId)[$v2['id']]['urls']);
    }

    public function test_notify_client_first_unread(): void
    {
        [$orderId, $offerId] = $this->inWork();
        $added = [];
        foreach (['v1', 'v2', 'v3'] as $caption) $added[] = Previews::add($orderId, self::A, $this->upload(), $caption, null);
        $this->assertSame([], Notify::$sent, 'сам add не сповіщає — сторінка шле notice після відповіді браузеру');
        $this->assertSame(['offer_id' => $offerId, 'customer_id' => self::CLIENT, 'designer_id' => self::A, 'order_id' => $orderId],
            $added[0]['notice']);
        foreach ($added as $a) Workspace::notifyClient($a['notice']);

        $this->assertCount(1, Notify::$sent, 'пачка прев\'ю — одне сповіщення, поки клієнт не відкрив листування');
        $this->assertSame(self::CLIENT, Notify::$sent[0]['customer_id']);
        $this->assertStringContainsString('нове повідомлення від Петро Дизайнер по №' . $orderId, Notify::$sent[0]['msg']);
        $this->assertStringContainsString('/orders/' . $orderId . '/', Notify::$sent[0]['msg']);
        $this->assertNotNull(Sql::value('SELECT client_notified_at FROM design_offers WHERE id = ?', [$offerId]));
        $this->assertNull(Sql::value('SELECT designer_notified_at FROM design_offers WHERE id = ?', [$offerId]));

        // клієнт відкрив листування (давно) — наступне прев'ю знову сповіщає
        Sql::exec('UPDATE design_offers SET client_notified_at = NOW() - INTERVAL 120 SECOND, client_seen_at = NOW() - INTERVAL 60 SECOND WHERE id = ?', [$offerId]);
        Workspace::notifyClient(Previews::add($orderId, self::A, $this->upload(), 'v4', null)['notice']);
        $this->assertCount(2, Notify::$sent);
    }

    /** Друге зʼєднання з БД — як паралельний запит */
    private static function otherConnection(): \PDO
    {
        return new \PDO(
            'mysql:host=' . \CdnConfig::get('db_host') . ';port=' . \CdnConfig::get('db_port') . ';dbname=w2p;charset=utf8mb4',
            \CdnConfig::get('db_username'), \CdnConfig::get('db_password'),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
    }

    /**
     * Рендер іде поза транзакцією: під час make() транзакції немає, рядок замовлення вільний для інших запитів,
     * і друге прев'ю того самого замовлення проходить, не чекаючи першого (воно отримує v1, перше — v2)
     */
    public function test_render_outside_transaction(): void
    {
        [$orderId] = $this->inWork();
        $other = self::otherConnection();
        $seen = [];
        $nested = null;
        Previews::$beforeRender = function (int $order) use (&$seen, &$nested, $other, $orderId): void {
            $seen[] = $order;
            $this->assertFalse(Sql::pdo()->inTransaction(), 'під час рендеру транзакції немає');
            // інший запит може взяти замок замовлення одразу (NOWAIT кинув би 3572, якби рядок був замкнений)
            $other->beginTransaction();
            $this->assertSame($orderId, (int)$other->query("SELECT id FROM design_orders WHERE id = $orderId FOR UPDATE NOWAIT")->fetchColumn());
            $other->rollBack();
            if ($nested === null) {
                $nested = false;
                $nested = Previews::add($orderId, self::A, $this->upload('#00aa00'), 'Паралельне', null);
            }
        };
        $outer = Previews::add($orderId, self::A, $this->upload(), 'Повільне', null);

        $this->assertSame([$orderId, $orderId], $seen);
        $this->assertSame(1, $nested['num']);
        $this->assertSame(2, $outer['num']);
        $this->assertEqualsCanonicalizing([
            "{$nested['id']}-p1-view.webp", "{$nested['id']}-p1-card.webp",
            "{$outer['id']}-p1-view.webp", "{$outer['id']}-p1-card.webp",
        ], $this->files($orderId), 'тимчасових tmp-* не лишилось');
    }

    /** Доступ чи «на основі» змінились, поки йшов рендер: повторна перевірка під замком відмовляє, картинки tmp-* прибрано */
    public function test_recheck_after_render_removes_temp_files(): void
    {
        [$orderId, $offerId] = $this->inWork();
        $v1 = Previews::add($orderId, self::A, $this->upload(), 'v1', null);

        $cases = [
            'дизайнера заблоковано' => [fn() => Sql::exec('UPDATE design_designers SET status = ? WHERE customer_id = ?', [DesignerStatus::BLOCKED, self::A]),
                null, "Додавати й приховувати прев'ю може лише обраний дизайнер замовлення в роботі"],
            'v1 приховано' => [fn() => Sql::exec('UPDATE design_previews SET hidden_at = NOW() WHERE id = ?', [$v1['id']]),
                $v1['id'], "Версію, на основі якої зроблено прев'ю, не знайдено"],
        ];
        foreach ($cases as $why => [$change, $basedOn, $error]) {
            Previews::$beforeRender = function () use ($change): void {
                $change();
            };
            $tmp = $this->upload();
            try {
                Previews::add($orderId, self::A, $tmp, 'Спроба', $basedOn);
                $this->fail("Повторна перевірка має відмовити: $why");
            } catch (DesignerException $e) {
                $this->assertSame($error, $e->getMessage(), $why);
            }
            $this->assertFileDoesNotExist($tmp);
            $this->assertEqualsCanonicalizing(["{$v1['id']}-p1-view.webp", "{$v1['id']}-p1-card.webp"], $this->files($orderId), $why);
            $this->assertSame(1, (int)Sql::value('SELECT COUNT(*) FROM design_previews WHERE order_id = ?', [$orderId]), $why);
            $this->assertCount(1, Messages::list($offerId), $why);
            Sql::exec('UPDATE design_designers SET status = ? WHERE customer_id = ?', [DesignerStatus::APPROVED, self::A]);
        }
    }

    /** Тимчасова основа імен для PreviewImages — лише «tmp-<16 hex>» */
    public function test_temp_base_name_is_strict(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        \Design\PreviewImages::make($this->upload(), $this->root, '../x');
    }
}
