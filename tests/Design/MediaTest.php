<?php

use Design\Config;
use Design\DesignerStatus;
use Design\Media;
use Design\Sql;
use PHPUnit\Framework\TestCase;

/** @group db */
final class MediaTest extends TestCase
{
    private const OWNER = 999999301;
    private const OTHER = 999999302;
    private const AVATAR = 'avatar-0a1b2c3d';

    /** @var string|false */
    private $oldRoot;
    private string $root;

    protected function setUp(): void
    {
        $this->oldRoot = getenv('DESIGN_STORAGE_ROOT');
        $this->root = sys_get_temp_dir() . '/design-media-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);
        putenv('DESIGN_STORAGE_ROOT=' . $this->root);
        $this->wipe();
    }

    protected function tearDown(): void
    {
        $this->wipe();
        foreach (glob($this->root . '/portfolio/*/*') ?: [] as $f) unlink($f);
        foreach (glob($this->root . '/portfolio/*') ?: [] as $d) rmdir($d);
        @rmdir($this->root . '/portfolio');
        @rmdir($this->root);
        putenv($this->oldRoot === false ? 'DESIGN_STORAGE_ROOT' : 'DESIGN_STORAGE_ROOT=' . $this->oldRoot);
    }

    private function wipe(): void
    {
        Sql::exec('DELETE FROM design_portfolio WHERE designer_id = ?', [self::OWNER]);
        Sql::exec('DELETE FROM design_designers WHERE customer_id = ?', [self::OWNER]);
    }

    private function designer(int $status): void
    {
        Sql::exec('DELETE FROM design_designers WHERE customer_id = ?', [self::OWNER]);
        Sql::insert('design_designers', ['customer_id' => self::OWNER, 'status' => $status, 'name' => 'Тест', 'photo' => self::AVATAR . '.webp']);
    }

    private function work(bool $shown, string $file = '1.jpg'): int
    {
        return Sql::insert('design_portfolio', ['designer_id' => self::OWNER, 'file' => $file, 'shw' => $shown ? 1 : 0, 'pos' => 1]);
    }

    /** @return array<string, array{int}> */
    public static function statuses(): array
    {
        return ['pending' => [DesignerStatus::PENDING], 'approved' => [DesignerStatus::APPROVED], 'blocked' => [DesignerStatus::BLOCKED]];
    }

    /** @dataProvider statuses */
    public function test_media_access_matrix(int $status): void
    {
        $this->designer($status);
        $shown = $this->work(true);
        $hidden = $this->work(false);
        $approved = $status === DesignerStatus::APPROVED;

        foreach (['card', 'view'] as $kind) {
            // Показана: власник завжди, вошедший чужий — лише якщо схвалено, гість — ніколи
            $n = "$shown-$kind";
            $this->assertTrue(Media::canView(self::OWNER, self::OWNER, $n, []), "owner shown $kind");
            $this->assertSame($approved, Media::canView(self::OTHER, self::OWNER, $n, []), "other shown $kind");
            $this->assertFalse(Media::canView(0, self::OWNER, $n, []), "guest shown $kind");
            // Прихована: бачить лише власник
            $n = "$hidden-$kind";
            $this->assertTrue(Media::canView(self::OWNER, self::OWNER, $n, []), "owner hidden $kind");
            $this->assertFalse(Media::canView(self::OTHER, self::OWNER, $n, []), "other hidden $kind");
            $this->assertFalse(Media::canView(0, self::OWNER, $n, []), "guest hidden $kind");
        }

        // Аватар: власник завжди, чужий — лише якщо схвалено, гість — ні
        $this->assertTrue(Media::canView(self::OWNER, self::OWNER, self::AVATAR, []));
        $this->assertSame($approved, Media::canView(self::OTHER, self::OWNER, self::AVATAR, []));
        $this->assertFalse(Media::canView(0, self::OWNER, self::AVATAR, []));
    }

    public function test_not_current_avatar_unknown_work_and_in_flight_are_denied(): void
    {
        $this->designer(DesignerStatus::APPROVED);
        $inFlight = $this->work(true, '');
        $ok = $this->work(true);

        $this->assertFalse(Media::canView(self::OWNER, self::OWNER, 'avatar-ffffffff', []), 'старий аватар');
        $this->assertFalse(Media::canView(self::OWNER, self::OWNER, ($ok + 100) . '-card', []), 'немає роботи');
        $this->assertFalse(Media::canView(self::OWNER, self::OWNER, "$inFlight-card", []), 'власник: в обробці');
        $this->assertFalse(Media::canView(self::OTHER, self::OWNER, "$inFlight-view", []), 'чужий: в обробці');
        $this->assertFalse(Media::canView(self::OWNER, self::OWNER, "$ok-orig", []), 'оригінал не віддаємо');
        $this->assertFalse(Media::canView(self::OWNER, self::OWNER, "$ok.jpg", []));
        $this->assertFalse(Media::canView(self::OWNER, self::OWNER, '../csrf.secret', []));
        $this->assertFalse(Media::canView(self::OWNER, 999999399, "$ok-card", []), 'дизайнера немає');
        // Робота іншого дизайнера під чужим id
        $this->assertFalse(Media::canView(self::OWNER, self::OTHER, "$ok-card", []));
        // Підпис не відкриває роботу в обробці
        $sig = Media::signedUrl(self::OWNER, "$inFlight-card");
        parse_str((string)parse_url($sig, PHP_URL_QUERY), $q);
        $this->assertFalse(Media::canView(0, self::OWNER, "$inFlight-card", $q));
    }

    public function test_signed_url_valid_expired_tampered(): void
    {
        $this->designer(DesignerStatus::PENDING);
        $w = $this->work(false);
        $name = "$w-view";
        $now = 1800000000;

        $url = Media::signedUrl(self::OWNER, $name, 3600, $now);
        $this->assertStringStartsWith(Config::siteUrl() . '/media/' . self::OWNER . "/$name?exp=", $url);
        $this->assertSame(Media::url(self::OWNER, $name), (string)parse_url($url, PHP_URL_PATH));
        parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame($now + 3600, (int)$q['exp']);
        $this->assertSame(hash_hmac('sha256', self::OWNER . "/$name/" . ($now + 3600), 'media|' . Config::csrfSecret()), $q['sig']);

        // Дійсний підпис пускає гостя, навіть до прихованої роботи неподтвердженого дизайнера
        $this->assertTrue(Media::canView(0, self::OWNER, $name, $q, $now));
        $this->assertTrue(Media::canView(0, self::OWNER, $name, $q, $now + 3600), 'exp включно');
        // Прострочений
        $this->assertFalse(Media::canView(0, self::OWNER, $name, $q, $now + 3601));
        // Підроблені: exp, sig, чужа картинка, чужий дизайнер
        $this->assertFalse(Media::canView(0, self::OWNER, $name, ['exp' => $q['exp'] + 1000, 'sig' => $q['sig']], $now));
        $this->assertFalse(Media::canView(0, self::OWNER, $name, ['exp' => $q['exp'], 'sig' => strrev($q['sig'])], $now));
        $this->assertFalse(Media::canView(0, self::OWNER, $name, ['exp' => $q['exp']], $now));
        $this->assertFalse(Media::canView(0, self::OWNER, $name, ['exp' => $q['exp'], 'sig' => ['x']], $now));
        $this->assertFalse(Media::canView(0, self::OWNER, "$w-card", $q, $now));
        $this->assertFalse(Media::canView(0, self::OTHER, $name, $q, $now));
    }

    public function test_url_and_path(): void
    {
        $this->assertSame('/media/12/345-card', Media::url(12, '345-card'));
        $this->assertSame($this->root . '/portfolio/12/345-card.webp', Media::path(12, '345-card'));
        $this->assertDirectoryDoesNotExist($this->root . '/portfolio');
    }
}
