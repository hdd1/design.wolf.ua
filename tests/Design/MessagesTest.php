<?php

use Design\DesignerException;
use Design\DesignerStatus;
use Design\Messages;
use Design\Notify;
use Design\OfferStatus;
use Design\Sql;
use PHPUnit\Framework\TestCase;

final class MessagesTest extends TestCase
{
    private const FAKE_ORDER = 999999701;
    private const FAKE_DESIGNER = 999999702;
    private const FAKE_CLIENT = 999999703;

    private int $offerId;

    protected function setUp(): void
    {
        Notify::$sent = [];
        $this->cleanup();
        // співрозмовник-дизайнер — схвалений (не схваленому сповіщення не йдуть)
        Sql::insert('design_designers', ['customer_id' => self::FAKE_DESIGNER, 'status' => DesignerStatus::APPROVED, 'name' => 'Тест']);
        $this->offerId = Sql::insert('design_offers', [
            'order_id' => self::FAKE_ORDER,
            'designer_id' => self::FAKE_DESIGNER,
            'status' => 3,
        ]);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        Notify::$sent = [];
    }

    private function cleanup(): void
    {
        Sql::exec('DELETE m FROM design_messages m JOIN design_offers o ON o.id = m.offer_id WHERE o.order_id = ?', [self::FAKE_ORDER]);
        Sql::exec('DELETE FROM design_offers WHERE order_id = ?', [self::FAKE_ORDER]);
        Sql::exec('DELETE FROM design_designers WHERE customer_id = ?', [self::FAKE_DESIGNER]);
    }

    private function updated(): string
    {
        return (string)Sql::value('SELECT updated FROM design_offers WHERE id = ?', [$this->offerId]);
    }

    public function test_add_validates_and_keeps_text(): void
    {
        foreach (['', "  \n\t "] as $empty) {
            try {
                Messages::add($this->offerId, self::FAKE_CLIENT, Messages::ROLE_CLIENT, $empty);
                $this->fail('Порожнє повідомлення має відхилятись');
            } catch (DesignerException $e) {
                $this->assertSame('Напишіть повідомлення', $e->getMessage());
            }
        }
        try {
            Messages::add($this->offerId, self::FAKE_CLIENT, Messages::ROLE_CLIENT, str_repeat('я', Messages::TEXT_MAX + 1));
            $this->fail('Задовге повідомлення має відхилятись');
        } catch (DesignerException $e) {
            $this->assertSame('Повідомлення — не більше 2000 символів', $e->getMessage());
        }
        // рівно 2000 символів (кирилиця — по 2 байти) проходить
        $this->assertGreaterThan(0, Messages::add($this->offerId, self::FAKE_CLIENT, Messages::ROLE_CLIENT, str_repeat('я', Messages::TEXT_MAX)));

        $text = "О'Брайен <b>жирний</b> & \"лапки\"";
        $id = Messages::add($this->offerId, self::FAKE_DESIGNER, Messages::ROLE_DESIGNER, "  $text \n");
        $row = Sql::one('SELECT * FROM design_messages WHERE id = ?', [$id]);
        $this->assertSame($text, $row['text']);
        $this->assertSame(self::FAKE_DESIGNER, (int)$row['author_id']);
        $this->assertSame(Messages::ROLE_DESIGNER, (int)$row['author_role']);
    }

    public function test_list_after_id_and_count_skips_system(): void
    {
        $this->assertSame(0, Messages::count($this->offerId));
        $this->assertSame([], Messages::list($this->offerId));

        $a = Messages::add($this->offerId, self::FAKE_CLIENT, Messages::ROLE_CLIENT, 'Перше');
        $s = Messages::system($this->offerId, 'Ціну змінено', ['event' => 'price', 'from' => 800, 'to' => 1000]);
        $b = Messages::add($this->offerId, self::FAKE_DESIGNER, Messages::ROLE_DESIGNER, 'Друге');

        $this->assertSame(2, Messages::count($this->offerId));

        $all = Messages::list($this->offerId);
        $this->assertSame([$a, $s, $b], array_column($all, 'id'));
        $this->assertSame([Messages::ROLE_CLIENT, Messages::ROLE_SYSTEM, Messages::ROLE_DESIGNER], array_column($all, 'role'));
        $this->assertSame(0, $all[1]['author_id']);
        $data = $all[1]['data'];
        ksort($data);   // MySQL json перевпорядковує ключі
        $this->assertSame(['event' => 'price', 'from' => 800, 'to' => 1000], $data);
        $this->assertNull($all[0]['data']);
        $this->assertSame('Перше', $all[0]['text']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $all[0]['created']);

        $this->assertSame([$s, $b], array_column(Messages::list($this->offerId, $a), 'id'));
        $this->assertSame([], Messages::list($this->offerId, $b));
        $this->assertSame([$a, $s], array_column(Messages::list($this->offerId, 0, 2), 'id'), 'limit — перші за порядком');
        $this->assertSame([$s], array_column(Messages::list($this->offerId, $a, 1), 'id'));
    }

    public function test_seen_sets_column_for_role(): void
    {
        Messages::seen($this->offerId, Messages::ROLE_CLIENT);
        $row = Sql::one('SELECT client_seen_at, designer_seen_at FROM design_offers WHERE id = ?', [$this->offerId]);
        $this->assertNotNull($row['client_seen_at']);
        $this->assertNull($row['designer_seen_at']);

        Messages::seen($this->offerId, Messages::ROLE_DESIGNER);
        $row = Sql::one('SELECT designer_seen_at FROM design_offers WHERE id = ?', [$this->offerId]);
        $this->assertNotNull($row['designer_seen_at']);
    }

    public function test_notify_texts_and_recipient(): void
    {
        $this->assertTrue(Messages::notifyNew($this->offerId, Messages::ROLE_CLIENT, self::FAKE_CLIENT, 'Іван', self::FAKE_ORDER));
        $this->assertTrue(Messages::notifyNew($this->offerId, Messages::ROLE_DESIGNER, self::FAKE_DESIGNER, 'Тарас Г.', self::FAKE_ORDER));
        $this->assertCount(2, Notify::$sent);

        [$toClient, $toDesigner] = Notify::$sent;
        $this->assertSame(self::FAKE_CLIENT, $toClient['customer_id']);
        $this->assertMatchesRegularExpression(
            '~^Майстерня дизайну: нове повідомлення від Іван по №' . self::FAKE_ORDER . '\. '
            . preg_quote(\Design\Config::siteUrl() . '/orders/' . self::FAKE_ORDER . '/', '~') . ' \(\d\d\.\d\d \d\d:\d\d\)$~u',
            $toClient['msg']
        );
        $this->assertSame('Майстерня дизайну: нове повідомлення по №' . self::FAKE_ORDER . '. design.wolf.ua', $toClient['msgSMS']);

        $this->assertSame(self::FAKE_DESIGNER, $toDesigner['customer_id']);
        $this->assertStringContainsString(\Design\Config::siteUrl() . '/feed/' . self::FAKE_ORDER . '/', $toDesigner['msg']);
        $this->assertStringContainsString('від Тарас Г.', $toDesigner['msg']);

        $row = Sql::one('SELECT client_notified_at, designer_notified_at FROM design_offers WHERE id = ?', [$this->offerId]);
        $this->assertNotNull($row['client_notified_at']);
        $this->assertNotNull($row['designer_notified_at']);
    }

    public function test_first_unread_rule(): void
    {
        // 5 повідомлень поспіль, співрозмовник не заходив — сповіщення лише про перше
        $sent = 0;
        for ($i = 0; $i < 5; $i++) {
            $sent += Messages::notifyNew($this->offerId, Messages::ROLE_CLIENT, self::FAKE_CLIENT, 'Дизайнер', self::FAKE_ORDER) ? 1 : 0;
        }
        $this->assertSame(1, $sent);
        $this->assertCount(1, Notify::$sent);

        // клієнт відкрив листування вже після сповіщення (давно) — наступне повідомлення знову сповіщає
        Sql::exec('UPDATE design_offers SET client_notified_at = NOW() - INTERVAL 120 SECOND, client_seen_at = NOW() - INTERVAL 60 SECOND WHERE id = ?', [$this->offerId]);
        $this->assertTrue(Messages::notifyNew($this->offerId, Messages::ROLE_CLIENT, self::FAKE_CLIENT, 'Дизайнер', self::FAKE_ORDER));
        $this->assertCount(2, Notify::$sent);
        // і знову гасне до наступного візиту
        $this->assertFalse(Messages::notifyNew($this->offerId, Messages::ROLE_CLIENT, self::FAKE_CLIENT, 'Дизайнер', self::FAKE_ORDER));
        $this->assertCount(2, Notify::$sent);

        // клієнт відкрив листування щойно (seen_at = зараз) — він у чаті, не сповіщаємо
        Sql::exec('UPDATE design_offers SET client_notified_at = NOW() - INTERVAL 120 SECOND WHERE id = ?', [$this->offerId]);
        Messages::seen($this->offerId, Messages::ROLE_CLIENT);
        $this->assertFalse(Messages::notifyNew($this->offerId, Messages::ROLE_CLIENT, self::FAKE_CLIENT, 'Дизайнер', self::FAKE_ORDER));
        $this->assertCount(2, Notify::$sent);

        // «щойно» вичерпалось (60 с > 45 с) — сповіщаємо
        Sql::exec('UPDATE design_offers SET client_seen_at = NOW() - INTERVAL 60 SECOND WHERE id = ?', [$this->offerId]);
        $this->assertTrue(Messages::notifyNew($this->offerId, Messages::ROLE_CLIENT, self::FAKE_CLIENT, 'Дизайнер', self::FAKE_ORDER));
        $this->assertCount(3, Notify::$sent);

        // лічильники сторін незалежні: дизайнер ще не сповіщався
        $this->assertTrue(Messages::notifyNew($this->offerId, Messages::ROLE_DESIGNER, self::FAKE_DESIGNER, 'Клієнт', self::FAKE_ORDER));
        $this->assertCount(4, Notify::$sent);
    }

    /** Позначка події в повідомленні автора (причина відмови) — у data, як у системних */
    public function test_add_keeps_event_data(): void
    {
        $plain = Messages::add($this->offerId, self::FAKE_DESIGNER, Messages::ROLE_DESIGNER, 'Питання');
        $marked = Messages::add($this->offerId, self::FAKE_DESIGNER, Messages::ROLE_DESIGNER, 'Зайнятий', ['event' => 'decline']);
        $byId = array_column(Messages::list($this->offerId), null, 'id');
        $this->assertNull($byId[$plain]['data']);
        $this->assertSame(['event' => 'decline'], $byId[$marked]['data']);
        $this->assertSame(Messages::ROLE_DESIGNER, $byId[$marked]['role']);
        $this->assertSame(2, Messages::count($this->offerId), 'позначене повідомлення — звичайне повідомлення дизайнера');
    }

    /** seen / notified — облік, не зміна пропозиції: updated (час запрошення, відмови, сортування) не рухається */
    public function test_seen_and_notify_keep_updated(): void
    {
        Sql::exec("UPDATE design_offers SET updated = '2026-01-02 03:04:05' WHERE id = ?", [$this->offerId]);

        Messages::seen($this->offerId, Messages::ROLE_CLIENT);
        Messages::seen($this->offerId, Messages::ROLE_DESIGNER);
        $this->assertSame('2026-01-02 03:04:05', $this->updated(), 'seen');

        // співрозмовники давно не заходили — сповіщення підуть
        Sql::exec("UPDATE design_offers SET client_seen_at = NULL, designer_seen_at = NULL, updated = '2026-01-02 03:04:05' WHERE id = ?", [$this->offerId]);
        $this->assertTrue(Messages::notifyNew($this->offerId, Messages::ROLE_CLIENT, self::FAKE_CLIENT, 'Іван', self::FAKE_ORDER));
        $this->assertTrue(Messages::notifyNew($this->offerId, Messages::ROLE_DESIGNER, self::FAKE_DESIGNER, 'Тарас Г.', self::FAKE_ORDER));
        $this->assertSame('2026-01-02 03:04:05', $this->updated(), 'notifyNew');
    }

    /** Не схвалений (заблокований, на розгляді, без профілю) дизайнер сповіщень про повідомлення не отримує; клієнт — як завжди */
    public function test_notify_skips_unapproved_designer(): void
    {
        foreach ([DesignerStatus::BLOCKED, DesignerStatus::PENDING, null] as $status) {
            if ($status === null) {
                Sql::exec('DELETE FROM design_designers WHERE customer_id = ?', [self::FAKE_DESIGNER]);
            } else {
                Sql::exec('UPDATE design_designers SET status = ? WHERE customer_id = ?', [$status, self::FAKE_DESIGNER]);
            }
            $this->assertFalse(Messages::notifyNew($this->offerId, Messages::ROLE_DESIGNER, self::FAKE_DESIGNER, 'Тарас Г.', self::FAKE_ORDER), "статус $status");
            $this->assertSame([], Notify::$sent);
            $this->assertNull(Sql::value('SELECT designer_notified_at FROM design_offers WHERE id = ?', [$this->offerId]));
        }
        $this->assertTrue(Messages::notifyNew($this->offerId, Messages::ROLE_CLIENT, self::FAKE_CLIENT, 'Дизайнер', self::FAKE_ORDER));
        $this->assertCount(1, Notify::$sent);
    }

    /** Відповідь (коментар) — лише на наявне повідомлення того самого листування; list віддає reply_to_id і preview_id */
    public function test_reply_to_same_offer_only(): void
    {
        $other = Sql::insert('design_offers', ['order_id' => self::FAKE_ORDER, 'designer_id' => self::FAKE_DESIGNER + 10, 'status' => OfferStatus::SENT]);
        $foreign = Messages::add($other, self::FAKE_CLIENT, Messages::ROLE_CLIENT, 'В іншому листуванні');
        $base = Messages::add($this->offerId, self::FAKE_DESIGNER, Messages::ROLE_DESIGNER, 'Варіант із темним фоном');

        foreach (['інше листування' => $foreign, 'немає' => $base + 1000000] as $why => $replyTo) {
            try {
                Messages::add($this->offerId, self::FAKE_CLIENT, Messages::ROLE_CLIENT, 'Коментар', [], $replyTo);
                $this->fail("Відповідь ($why) має відхилятись");
            } catch (DesignerException $e) {
                $this->assertSame('Повідомлення, на яке ви відповідаєте, не знайдено', $e->getMessage(), $why);
            }
        }
        $this->assertSame([$base], array_column(Messages::list($this->offerId), 'id'), 'відхилені відповіді не записано');

        $reply = Messages::add($this->offerId, self::FAKE_CLIENT, Messages::ROLE_CLIENT, 'Темний подобається', [], $base);
        $plain = Messages::add($this->offerId, self::FAKE_CLIENT, Messages::ROLE_CLIENT, 'Без відповіді');
        $byId = array_column(Messages::list($this->offerId), null, 'id');
        $this->assertSame($base, $byId[$reply]['reply_to_id']);
        $this->assertNull($byId[$reply]['data']);
        $this->assertNull($byId[$plain]['reply_to_id']);
        $this->assertNull($byId[$base]['preview_id']);

        // byIds — лише цього листування
        $this->assertSame([$base, $reply], array_keys(Messages::byIds($this->offerId, [$reply, $base, $foreign, $base])));
        $this->assertSame([], Messages::byIds($this->offerId, []));
    }
}
