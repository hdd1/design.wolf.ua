<?php

use Design\DesignerException;
use Design\Messages;
use Design\Notify;
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
}
