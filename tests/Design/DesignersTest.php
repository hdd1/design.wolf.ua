<?php

use Design\Config;
use Design\DesignerException;
use Design\Designers;
use Design\DesignerStatus;
use Design\Notify;
use Design\Sql;
use PHPUnit\Framework\TestCase;

/** @group db */
final class DesignersTest extends TestCase
{
    private const A = 999999101;
    private const B = 999999102;
    private const C = 999999103;
    private const REAL = 29;

    private ?string $host = null;

    protected function setUp(): void
    {
        $this->host = $_SERVER['HTTP_HOST'] ?? null;
        unset($_SERVER['HTTP_HOST']);
        Notify::$sent = [];
        $this->wipe();
    }

    protected function tearDown(): void
    {
        $this->wipe();
        if ($this->host === null) unset($_SERVER['HTTP_HOST']); else $_SERVER['HTTP_HOST'] = $this->host;
        Notify::$sent = [];
    }

    private function wipe(): void
    {
        $ids = [self::A, self::B, self::C];
        $in = implode(',', array_fill(0, count($ids), '?'));
        Sql::exec("DELETE FROM design_portfolio WHERE designer_id IN ($in)", $ids);
        Sql::exec("DELETE FROM design_designers WHERE customer_id IN ($in)", $ids);
    }

    private function works(int $cid, int $n, int $shw = 1): void
    {
        for ($i = 0; $i < $n; $i++) {
            Sql::insert('design_portfolio', ['designer_id' => $cid, 'file' => ($i + 1) . '.jpg', 'pos' => $i, 'shw' => $shw]);
        }
    }

    /** Заповнений профіль із $n роботами */
    private function ready(int $cid, int $n = 3, string $name = 'Тест Дизайнер'): void
    {
        Designers::start($cid);
        Designers::saveProfile($cid, $name, 'Про себе', '');
        $this->works($cid, $n);
    }

    private function approved(int $cid, string $name): void
    {
        $this->ready($cid, 3, $name);
        Designers::submit($cid);
        Designers::approve($cid, 1);
    }

    private function assertFails(string $message, callable $fn): void
    {
        try {
            $fn();
        } catch (DesignerException $e) {
            $this->assertSame($message, $e->getMessage());
            return;
        }
        $this->fail("Очікувалась DesignerException: $message");
    }

    public function test_site_url_follows_host(): void
    {
        $this->assertSame('https://design.wolf.ua', Config::siteUrl());
        $_SERVER['HTTP_HOST'] = 'dev.design.wolf.ua';
        $this->assertSame('https://dev.design.wolf.ua', Config::siteUrl());
        $_SERVER['HTTP_HOST'] = 'design.wolf.ua';
        $this->assertSame('https://design.wolf.ua', Config::siteUrl());
    }

    public function test_notify_off_only_records(): void
    {
        Notify::send(['user_id' => 1, 'msg' => 'x', 'msgSMS' => 'x']);
        $this->assertSame([['user_id' => 1, 'msg' => 'x', 'msgSMS' => 'x']], Notify::$sent);
    }

    public function test_start_creates_draft_and_is_idempotent(): void
    {
        $this->assertNull(Designers::status(self::A));
        $row = Designers::start(self::A);
        $this->assertSame(DesignerStatus::DRAFT, $row['status']);
        $this->assertSame('', $row['name']);   // клієнта 999999101 немає — імʼя порожнє, не «Клієнт»
        Designers::saveProfile(self::A, 'Ім', 'Про', '');
        $this->assertSame('Ім', Designers::start(self::A)['name']);
        $this->assertSame(DesignerStatus::DRAFT, Designers::status(self::A));
    }

    public function test_start_prefills_name_from_customer(): void
    {
        $existed = Sql::one('SELECT 1 FROM design_designers WHERE customer_id = ?', [self::REAL]) !== null;
        if ($existed) $this->markTestSkipped('Клієнт 29 уже дизайнер — не чіпаємо його рядок');
        try {
            $row = Designers::start(self::REAL);
            $this->assertSame(\Design\Customers::header(self::REAL)['name'], $row['name']);
            $this->assertNotSame('', $row['name']);
            $this->assertNotSame('Клієнт', $row['name']);
        } finally {
            Sql::exec('DELETE FROM design_designers WHERE customer_id = ?', [self::REAL]);
        }
    }

    public function test_submit_requires_about_and_3_to_10_works(): void
    {
        Designers::start(self::A);
        $this->works(self::A, 3);
        $this->assertFails('Вкажіть імʼя', fn() => Designers::submit(self::A));
        Designers::saveProfile(self::A, 'Тест', '', '');
        $this->assertFails('Розкажіть про себе', fn() => Designers::submit(self::A));
        Designers::saveProfile(self::A, 'Тест', "  \n ", '');
        $this->assertFails('Розкажіть про себе', fn() => Designers::submit(self::A));
        Designers::saveProfile(self::A, 'Тест', 'Про себе', '');

        Sql::exec('DELETE FROM design_portfolio WHERE designer_id = ? LIMIT 1', [self::A]);   // 2 роботи
        $this->assertFails('Додайте щонайменше 3 роботи', fn() => Designers::submit(self::A));
        $this->works(self::A, 9);                                                              // 11 робіт
        $this->assertFails('У заявці — не більше 10 робіт', fn() => Designers::submit(self::A));
        $this->assertSame(DesignerStatus::DRAFT, Designers::status(self::A));

        Sql::exec('DELETE FROM design_portfolio WHERE designer_id = ? LIMIT 1', [self::A]);   // рівно 10
        Designers::submit(self::A);
        $row = Designers::get(self::A);
        $this->assertSame(DesignerStatus::PENDING, $row['status']);
        $this->assertNotNull($row['submitted_at']);
    }

    public function test_submit_exactly_3_works_ok(): void
    {
        $this->ready(self::A, 3);
        Designers::submit(self::A);
        $this->assertSame(DesignerStatus::PENDING, Designers::status(self::A));
    }

    public function test_submit_without_row_fails(): void
    {
        $this->assertFails('Заявку зараз не можна надіслати', fn() => Designers::submit(self::A));
    }

    public function test_submit_twice_sends_one_notice(): void
    {
        $this->ready(self::A, 3, 'Олена');
        Designers::submit(self::A);
        Designers::submit(self::A);   // повтор (подвійний клік) — нічого
        $this->assertCount(1, Notify::$sent);
        $n = Notify::$sent[0];
        $this->assertSame(1, $n['user_id']);
        $this->assertArrayNotHasKey('customer_id', $n);
        $this->assertSame($n['msg'], $n['msgSMS']);
        $this->assertMatchesRegularExpression(
            '~^Майстерня дизайну: нова заявка дизайнера — Олена, \d\d\.\d\d \d\d:\d\d\. Розглянути: https://adminka\.wolf\.ua/design-applications/$~u',
            $n['msg']
        );
    }

    public function test_notice_uses_dev_hosts(): void
    {
        $_SERVER['HTTP_HOST'] = 'dev.design.wolf.ua';
        $this->ready(self::A, 3);
        Designers::submit(self::A);
        $this->assertStringEndsWith('https://dev.adminka.wolf.ua/design-applications/', Notify::$sent[0]['msg']);
        Designers::approve(self::A, 1);
        $this->assertStringEndsWith('https://dev.design.wolf.ua/designer/?approved=1', Notify::$sent[1]['msg']);
    }

    public function test_approve_and_reject_notify_designer(): void
    {
        $this->ready(self::A);
        $this->ready(self::B);
        Designers::submit(self::A);
        Designers::submit(self::B);
        Notify::$sent = [];

        Designers::approve(self::A, 7);
        $row = Designers::get(self::A);
        $this->assertSame(DesignerStatus::APPROVED, $row['status']);
        $this->assertSame(7, $row['reviewed_by']);
        $this->assertNotNull($row['reviewed_at']);
        $this->assertSame(self::A, Notify::$sent[0]['customer_id']);
        $this->assertArrayNotHasKey('user_id', Notify::$sent[0]);
        $this->assertMatchesRegularExpression(
            '~^Майстерня дизайну: вашу заявку дизайнера схвалено \(\d\d\.\d\d \d\d:\d\d\)\. Заповніть профіль: https://design\.wolf\.ua/designer/\?approved=1$~u',
            Notify::$sent[0]['msg']
        );

        Designers::reject(self::B, 7, 'Додайте більше робіт');
        $this->assertSame(self::B, Notify::$sent[1]['customer_id']);
        $this->assertMatchesRegularExpression(
            '~^Майстерня дизайну: заявку дизайнера відхилено \(\d\d\.\d\d \d\d:\d\d\)\. Коментар і що виправити: https://design\.wolf\.ua/designer/$~u',
            Notify::$sent[1]['msg']
        );
        $this->assertSame(Notify::$sent[1]['msg'], Notify::$sent[1]['msgSMS']);
    }

    public function test_reject_needs_comment_and_resubmit_clears_it(): void
    {
        $this->ready(self::A);
        Designers::submit(self::A);
        $this->assertFails('Напишіть коментар для дизайнера', fn() => Designers::reject(self::A, 7, '  '));
        $this->assertSame(DesignerStatus::PENDING, Designers::status(self::A));

        Designers::reject(self::A, 7, 'Мало робіт');
        $row = Designers::get(self::A);
        $this->assertSame(DesignerStatus::REJECTED, $row['status']);
        $this->assertSame('Мало робіт', $row['reject_comment']);

        Designers::saveProfile(self::A, 'Нове імʼя', 'Про себе', '');   // у REJECTED профіль редагується
        Designers::submit(self::A);
        $row = Designers::get(self::A);
        $this->assertSame(DesignerStatus::PENDING, $row['status']);
        $this->assertSame('', $row['reject_comment']);
    }

    public function test_reject_comment_too_long(): void
    {
        $this->ready(self::A);
        Designers::submit(self::A);
        $this->assertFails('Коментар — не більше 1000 символів', fn() => Designers::reject(self::A, 7, str_repeat('я', 1001)));
        Designers::reject(self::A, 7, str_repeat('я', 1000));
        $this->assertSame(DesignerStatus::REJECTED, Designers::status(self::A));
    }

    public function test_review_race(): void
    {
        $this->ready(self::A);
        Designers::submit(self::A);
        Designers::approve(self::A, 7);
        Notify::$sent = [];
        $this->assertFails('Статус заявки вже змінився — оновіть сторінку', fn() => Designers::approve(self::A, 8));
        $this->assertFails('Статус заявки вже змінився — оновіть сторінку', fn() => Designers::reject(self::A, 8, 'ні'));
        $this->assertSame([], Notify::$sent);
        $this->assertSame(7, Designers::get(self::A)['reviewed_by']);
    }

    public function test_approve_draft_not_allowed(): void
    {
        Designers::start(self::A);
        $this->assertFails('Статус заявки вже змінився — оновіть сторінку', fn() => Designers::approve(self::A, 7));
        $this->assertFails('Статус заявки вже змінився — оновіть сторінку', fn() => Designers::approve(self::C, 7));   // рядка немає
    }

    public function test_block_from_any_status_and_blocked_cannot_submit_or_edit(): void
    {
        $this->ready(self::A);                              // DRAFT
        $this->ready(self::B);
        Designers::submit(self::B);                         // PENDING
        $this->approved(self::C, 'Схвалений');              // APPROVED
        foreach ([self::A, self::B, self::C] as $cid) {
            Designers::block($cid, 9);
            $row = Designers::get($cid);
            $this->assertSame(DesignerStatus::BLOCKED, $row['status']);
            $this->assertSame(9, $row['reviewed_by']);
            $this->assertFails('Профіль зараз не можна змінити', fn() => Designers::saveProfile($cid, 'Х', 'Х', ''));
            $this->assertFails('Заявку зараз не можна надіслати', fn() => Designers::submit($cid));
        }
        $this->assertFails('Статус заявки вже змінився — оновіть сторінку', fn() => Designers::block(self::A, 9));

    }

    public function test_block_rejected(): void
    {
        $this->ready(self::A);
        Designers::submit(self::A);
        Designers::reject(self::A, 7, 'ні');
        Designers::block(self::A, 9);
        $this->assertSame(DesignerStatus::BLOCKED, Designers::status(self::A));
    }

    public function test_unblock_returns_to_approved(): void
    {
        $this->approved(self::A, 'Дизайнер');
        Designers::block(self::A, 9);
        Designers::unblock(self::A, 10);
        $row = Designers::get(self::A);
        $this->assertSame(DesignerStatus::APPROVED, $row['status']);
        $this->assertSame(10, $row['reviewed_by']);
        $this->assertFails('Статус заявки вже змінився — оновіть сторінку', fn() => Designers::unblock(self::A, 10));
    }

    public function test_save_profile_allowed_in_draft_rejected_approved_not_pending(): void
    {
        $this->ready(self::A);
        Designers::saveProfile(self::A, 'Чернетка', 'Про', '');           // DRAFT
        Designers::submit(self::A);
        $this->assertFails('Профіль зараз не можна змінити', fn() => Designers::saveProfile(self::A, 'Х', 'Х', ''));   // PENDING
        Designers::approve(self::A, 7);
        Designers::saveProfile(self::A, 'Схвалений', 'Про', '');          // APPROVED
        $this->assertSame('Схвалений', Designers::get(self::A)['name']);
        $this->assertSame(DesignerStatus::APPROVED, Designers::status(self::A));
        $this->assertFails('Профіль зараз не можна змінити', fn() => Designers::saveProfile(self::C, 'Х', 'Х', ''));   // рядка немає
    }

    public function test_save_profile_length_limits(): void
    {
        Designers::start(self::A);
        $this->assertFails('Імʼя — не більше 80 символів', fn() => Designers::saveProfile(self::A, str_repeat('я', 81), 'Про', ''));
        $this->assertFails('Про себе — не більше 2000 символів', fn() => Designers::saveProfile(self::A, 'Імʼя', str_repeat('я', 2001), ''));
        Designers::saveProfile(self::A, str_repeat('я', 80), str_repeat('я', 2000), '');
        $this->assertSame(80, mb_strlen(Designers::get(self::A)['name']));
        $this->assertSame(2000, mb_strlen(Designers::get(self::A)['about']));
    }

    /** @dataProvider links */
    public function test_link_normalized(string $in, string $out): void
    {
        Designers::start(self::A);
        Designers::saveProfile(self::A, 'Тест', 'Про', $in);
        $this->assertSame($out, Designers::get(self::A)['link']);
    }

    public static function links(): array
    {
        return [
            'порожньо' => ['', ''],
            'пробіли' => ['   ', ''],
            'без схеми' => ['behance.net/olena', 'https://behance.net/olena'],
            'з пробілами' => ['  behance.net/olena  ', 'https://behance.net/olena'],
            'https' => ['https://www.instagram.com/olena', 'https://www.instagram.com/olena'],
            'http' => ['http://olena.com.ua', 'http://olena.com.ua'],
            'регістр схеми' => ['HTTPS://Olena.com', 'HTTPS://Olena.com'],
            'кирилиця в шляху' => ['behance.net/імʼя', 'https://behance.net/імʼя'],
            'порт без схеми' => ['olena.com:8080/x', 'https://olena.com:8080/x'],
        ];
    }

    /** @dataProvider badLinks */
    public function test_link_rejected(string $in): void
    {
        Designers::start(self::A);
        $this->assertFails(
            'Посилання має бути адресою сайту, напр. behance.net/імʼя',
            fn() => Designers::saveProfile(self::A, 'Тест', 'Про', $in)
        );
        $this->assertSame('', Designers::get(self::A)['link']);
    }

    public static function badLinks(): array
    {
        return [
            'javascript' => ['javascript:alert(1)'],
            'javascript з регістром' => ['JaVaScRiPt:alert(1)'],
            'javascript з //' => ['javascript://x.com/%0Aalert(1)'],
            'data' => ['data:text/html,<script>alert(1)</script>'],
            'ftp' => ['ftp://olena.com/x'],
            'mailto' => ['mailto:a@b.com'],
            'пробіл усередині' => ['behance.net/o lena'],
            'без крапки в хості' => ['olena'],
            'тег' => ['https://olena.com/"><script>'],
            'логін у адресі' => ['https://user:pass@olena.com'],
            'задовга' => ['https://olena.com/' . '' . 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'],
        ];
    }

    public function test_text_kept_verbatim(): void
    {
        Designers::start(self::A);
        Designers::saveProfile(self::A, "Олег О'Ніл <b>", "Про \"мене\" & <i>ще</i>\nрядок 2", '');
        $row = Designers::get(self::A);
        $this->assertSame("Олег О'Ніл <b>", $row['name']);
        $this->assertSame("Про \"мене\" & <i>ще</i>\nрядок 2", $row['about']);
    }

    public function test_for_admin_filters_orders_and_counts(): void
    {
        $this->ready(self::A, 4);
        $this->ready(self::B, 3);
        Designers::start(self::C);                      // чернетка в списки не потрапляє
        Designers::submit(self::A);
        Sql::exec('UPDATE design_designers SET submitted_at = ? WHERE customer_id = ?', ['2020-01-01 10:00:00', self::A]);
        Designers::submit(self::B);

        $rows = array_values(array_filter(Designers::forAdmin(DesignerStatus::PENDING),
            fn($r) => in_array($r['customer_id'], [self::A, self::B, self::C], true)));
        $this->assertSame([self::B, self::A], array_column($rows, 'customer_id'));   // нові зверху
        $this->assertSame(3, $rows[0]['works']);
        $this->assertSame(4, $rows[1]['works']);
        $this->assertSame(DesignerStatus::PENDING, $rows[0]['status']);

        $this->assertSame([], Designers::forAdmin(DesignerStatus::DRAFT));
        $this->assertSame([], Designers::forAdmin(99));
        $this->assertSame([], Designers::forAdmin(-1));
        $this->assertNotContains(self::C, array_column(Designers::forAdmin(DesignerStatus::APPROVED), 'customer_id'));

        Designers::approve(self::A, 7);
        $this->assertContains(self::A, array_column(Designers::forAdmin(DesignerStatus::APPROVED), 'customer_id'));
        Designers::block(self::A, 7);
        $this->assertContains(self::A, array_column(Designers::forAdmin(DesignerStatus::BLOCKED), 'customer_id'));
        Designers::reject(self::B, 7, 'ні');
        $this->assertContains(self::B, array_column(Designers::forAdmin(DesignerStatus::REJECTED), 'customer_id'));
    }

    public function test_catalog_only_approved_search_escapes_wildcards(): void
    {
        $this->approved(self::A, 'Зюзя%Один');
        $this->approved(self::B, 'ЗюзяXДва');
        $this->ready(self::C, 3, 'Зюзя Чернетка');      // не схвалений — у каталозі немає

        $all = Designers::catalog('Зюзя', 1);
        $ids = array_column($all['items'], 'customer_id');
        $this->assertContains(self::A, $ids);
        $this->assertContains(self::B, $ids);
        $this->assertNotContains(self::C, $ids);

        $pct = array_column(Designers::catalog('%', 1)['items'], 'customer_id');
        $this->assertContains(self::A, $pct);
        $this->assertNotContains(self::B, $pct);

        $und = Designers::catalog('Зюзя_Один', 1);
        $this->assertSame([], $und['items']);
        $this->assertSame(0, $und['total']);

        $this->assertSame([self::A], array_column(Designers::catalog('один', 1)['items'], 'customer_id'));   // без урахування регістру
        $this->assertSame([self::A], array_column(Designers::catalog('Зюзя%', 1)['items'], 'customer_id'));
    }

    public function test_catalog_items_shape_and_works(): void
    {
        $this->approved(self::A, 'Зюзя Форма');
        Sql::exec('UPDATE design_designers SET photo = ? WHERE customer_id = ?', ['5.jpg', self::A]);
        $this->works(self::A, 2, 0);                                           // приховані — не показуємо
        Sql::exec('UPDATE design_portfolio SET pos = 50 WHERE designer_id = ? AND shw = 0', [self::A]);
        $this->works(self::A, 2);                                              // усього видимих 5

        $res = Designers::catalog('Зюзя Форма', 1);
        $this->assertSame(1, $res['total']);
        $this->assertSame(1, $res['pages']);
        $item = $res['items'][0];
        $this->assertSame(['customer_id', 'name', 'about', 'photo', 'works'], array_keys($item));
        $this->assertSame(self::A, $item['customer_id']);
        $this->assertSame('5.jpg', $item['photo']);
        $this->assertCount(3, $item['works']);
        $expect = array_map('intval', array_column(
            Sql::all('SELECT id FROM design_portfolio WHERE designer_id = ? AND shw = 1 ORDER BY pos, id LIMIT 3', [self::A]), 'id'));
        $this->assertSame($expect, $item['works']);
        $this->assertContainsOnly('int', $item['works']);
    }

    public function test_catalog_paging(): void
    {
        $this->assertSame(24, Designers::PER_PAGE);
        $res = Designers::catalog('Зюзя', 0);      // сторінка менша за 1 — як перша
        $this->assertSame(1, $res['pages']);
        $this->assertSame([], $res['items']);
    }

    public function test_public_profile_null_unless_approved(): void
    {
        $this->ready(self::A, 3, 'Публічний');
        $this->assertNull(Designers::publicProfile(self::A));                // DRAFT
        Designers::submit(self::A);
        $this->assertNull(Designers::publicProfile(self::A));                // PENDING
        Designers::reject(self::A, 7, 'ні');
        $this->assertNull(Designers::publicProfile(self::A));                // REJECTED
        Designers::saveProfile(self::A, 'Публічний', 'Про', 'behance.net/x');
        Designers::submit(self::A);
        Designers::approve(self::A, 7);
        Sql::exec('UPDATE design_portfolio SET shw = 0 WHERE designer_id = ? AND file = ?', [self::A, '1.jpg']);

        $p = Designers::publicProfile(self::A);
        $this->assertSame('Публічний', $p['name']);
        $this->assertSame('https://behance.net/x', $p['link']);
        $this->assertCount(2, $p['works']);                                  // видима лише частина
        $this->assertSame(['2.jpg', '3.jpg'], array_column($p['works'], 'file'));
        $this->assertArrayNotHasKey('rating', $p);
        $this->assertArrayNotHasKey('reject_comment', $p);

        Designers::block(self::A, 7);
        $this->assertNull(Designers::publicProfile(self::A));                // BLOCKED
        $this->assertNull(Designers::publicProfile(self::C));                // немає
    }

    /** Робота, яку зараз обробляє Images::store: file = '' */
    private function inFlight(int $cid, int $n = 1): void
    {
        for ($i = 0; $i < $n; $i++) Sql::insert('design_portfolio', ['designer_id' => $cid, 'file' => '', 'pos' => 0]);
    }

    public function test_in_flight_works_not_counted_or_shown(): void
    {
        // submit: 2 готові + 5 у обробці — це все ще 2 роботи
        Designers::start(self::B);
        Designers::saveProfile(self::B, 'Тест', 'Про себе', '');
        $this->works(self::B, 2);
        $this->inFlight(self::B, 5);
        $this->assertFails('Додайте щонайменше 3 роботи', fn() => Designers::submit(self::B));
        $this->works(self::B, 1);
        Designers::submit(self::B);
        $rows = array_values(array_filter(Designers::forAdmin(DesignerStatus::PENDING), fn($r) => $r['customer_id'] === self::B));
        $this->assertSame(3, $rows[0]['works']);                              // forAdmin теж без обробки

        // catalog і publicProfile
        $this->approved(self::A, 'Зюзя Форма');                               // 3 готові роботи
        $this->inFlight(self::A, 2);
        $item = Designers::catalog('Зюзя Форма', 1)['items'][0];
        $this->assertCount(3, $item['works']);
        $ready = array_map('intval', array_column(
            Sql::all("SELECT id FROM design_portfolio WHERE designer_id = ? AND file <> '' ORDER BY pos, id", [self::A]), 'id'));
        $this->assertSame($ready, $item['works']);
        $p = Designers::publicProfile(self::A);
        $this->assertSame(['1.jpg', '2.jpg', '3.jpg'], array_column($p['works'], 'file'));
    }

    /** Тимчасове сховище з JPEG-картинкою для тестів аватара: $test(шлях до картинки, корінь сховища) */
    private function avatarStorage(callable $test): void
    {
        $old = getenv('DESIGN_STORAGE_ROOT');
        $root = sys_get_temp_dir() . '/design-avatar-' . bin2hex(random_bytes(4));
        mkdir($root, 0777, true);
        putenv('DESIGN_STORAGE_ROOT=' . $root);
        try {
            $im = new Imagick();
            $im->newPseudoImage(500, 400, 'gradient:red-blue');
            $im->setImageFormat('jpeg');
            $im->writeImage("jpeg:$root/src.jpg");
            $im->clear();
            $test("$root/src.jpg", $root);
        } finally {
            putenv($old === false ? 'DESIGN_STORAGE_ROOT' : 'DESIGN_STORAGE_ROOT=' . $old);
            exec('rm -rf ' . escapeshellarg($root));
        }
    }

    public function test_set_photo_saves_avatar_and_removes_previous(): void
    {
        $this->avatarStorage(function (string $src, string $root): void {
            $dir = "$root/portfolio/" . self::A;
            Designers::start(self::A);
            $first = Designers::setPhoto(self::A, $src);
            $this->assertMatchesRegularExpression('/^avatar-[0-9a-f]{8}$/', $first);
            $this->assertSame("$first.webp", Designers::get(self::A)['photo']);
            $this->assertFileExists("$dir/$first.webp");
            $this->assertFileExists($src);                                       // тимчасовий файл не чіпаємо

            Sql::exec('UPDATE design_designers SET status = ? WHERE customer_id = ?', [DesignerStatus::APPROVED, self::A]);
            $second = Designers::setPhoto(self::A, $src);
            $this->assertNotSame($first, $second);
            $this->assertSame("$second.webp", Designers::get(self::A)['photo']);
            $this->assertFileDoesNotExist("$dir/$first.webp");
            $this->assertFileExists("$dir/$second.webp");
        });
    }

    public function test_set_photo_only_when_editable(): void
    {
        $this->avatarStorage(function (string $src, string $root): void {
            $this->assertFails('Профіль зараз не можна змінити', fn() => Designers::setPhoto(self::A, $src));   // немає анкети
            Designers::start(self::A);
            foreach ([DesignerStatus::PENDING, DesignerStatus::BLOCKED] as $st) {
                Sql::exec('UPDATE design_designers SET status = ? WHERE customer_id = ?', [$st, self::A]);
                $this->assertFails('Профіль зараз не можна змінити', fn() => Designers::setPhoto(self::A, $src));
            }
            Sql::exec('UPDATE design_designers SET status = ? WHERE customer_id = ?', [DesignerStatus::REJECTED, self::A]);
            Designers::setPhoto(self::A, $src);
            $this->assertCount(1, glob("$root/portfolio/" . self::A . '/avatar-*.webp'));
        });
    }

    public function test_set_photo_bad_file_keeps_old(): void
    {
        $this->avatarStorage(function (string $src, string $root): void {
            Designers::start(self::A);
            $old = Designers::setPhoto(self::A, $src);
            file_put_contents("$root/bad.jpg", 'не картинка');
            $this->assertFails('Файл пошкоджений або це не картинка', fn() => Designers::setPhoto(self::A, "$root/bad.jpg"));
            $this->assertSame("$old.webp", Designers::get(self::A)['photo']);
            $this->assertSame(["$root/portfolio/" . self::A . "/$old.webp"], glob("$root/portfolio/" . self::A . '/avatar-*.webp'));
        });
    }
}
