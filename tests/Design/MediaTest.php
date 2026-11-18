<?php

use Design\Media;
use PHPUnit\Framework\TestCase;

/** Картинки портфоліо віддає i.fastcdn: Media::url кодує лише наявні WebP-копії (симлінк у cloud-links/ci) */
final class MediaTest extends TestCase
{
    private const LINKS = '/home/connect/cloud-links/';
    private const CDN_DEV = 'https://dev.i.fastcdn.kiev.ua/';

    /** @var string|false */
    private $oldRoot;
    /** @var mixed */
    private $oldServerName;
    private string $root;
    /** @var list<string> симлінки cloud-links, створені тестом */
    private array $links = [];

    protected function setUp(): void
    {
        $this->oldRoot = getenv('DESIGN_STORAGE_ROOT');
        $this->oldServerName = $_SERVER['SERVER_NAME'] ?? null;
        $this->root = sys_get_temp_dir() . '/design-media-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/portfolio/12', 0777, true);
        putenv('DESIGN_STORAGE_ROOT=' . $this->root);
        $_SERVER['SERVER_NAME'] = 'dev.design.wolf.ua';
    }

    protected function tearDown(): void
    {
        foreach ($this->links as $l) {
            if (is_link($l)) unlink($l);
        }
        foreach (glob($this->root . '/portfolio/*/*') ?: [] as $f) unlink($f);
        foreach (glob($this->root . '/portfolio/*') ?: [] as $d) rmdir($d);
        @rmdir($this->root . '/portfolio');
        @rmdir($this->root);
        putenv($this->oldRoot === false ? 'DESIGN_STORAGE_ROOT' : 'DESIGN_STORAGE_ROOT=' . $this->oldRoot);
        if ($this->oldServerName === null) unset($_SERVER['SERVER_NAME']); else $_SERVER['SERVER_NAME'] = $this->oldServerName;
    }

    private function file(string $name): string
    {
        $path = "$this->root/portfolio/12/$name";
        file_put_contents($path, 'x');
        return $path;
    }

    /** Симлінк у cloud-links для адреси CDN (і запис для прибирання) */
    private function link(string $url): string
    {
        $link = self::LINKS . substr($url, strlen(self::CDN_DEV));
        $this->links[] = $link;
        return $link;
    }

    /** @return array<string, array{string}> */
    public static function names(): array
    {
        return ['card' => ['345-card'], 'view' => ['345-view'], 'avatar' => ['avatar-0a1b2c3d']];
    }

    /** @dataProvider names */
    public function test_url_is_fastcdn_ci_webp(string $name): void
    {
        $file = $this->file("$name.webp");
        $url = Media::url(12, $name);
        $this->links[] = self::LINKS . substr($url, strlen(self::CDN_DEV));

        $this->assertMatchesRegularExpression('#^https://dev\.i\.fastcdn\.kiev\.ua/ci/[0-9a-f]{2}/[0-9a-f]{2}/[0-9a-f]{2}/[0-9a-f]+\.webp$#', $url);
        $link = $this->link($url);
        $this->assertTrue(is_link($link), 'симлінк створено');
        $this->assertSame($file, readlink($link));
    }

    public function test_prod_domain_without_dev_server_name(): void
    {
        $this->file('345-card.webp');
        $_SERVER['SERVER_NAME'] = 'design.wolf.ua';
        $url = Media::url(12, '345-card');
        $this->links[] = self::LINKS . substr($url, strlen('https://i.fastcdn.kiev.ua/'));
        $this->assertStringStartsWith('https://i.fastcdn.kiev.ua/ci/', $url);
    }

    /** Оригінал і будь-яке нестандартне імʼя не кодуються: ні URL, ні симлінка */
    public function test_original_never_encoded(): void
    {
        foreach (['345.jpg', '345.png', '345-card.webp', '345-view.webp'] as $f) $this->file($f);
        $before = $this->countLinks();

        foreach (['345', '345.jpg', '345.png', '345-orig', '345-card.webp', '../csrf.secret', '../../345-card', 'avatar-0A1B2C3D', 'avatar-0a1b2c3', 'x-card', ''] as $name) {
            $this->assertSame('', Media::url(12, $name), "імʼя «{$name}»");
        }
        $this->assertSame($before, $this->countLinks(), 'симлінки не з’явилися');
    }

    public function test_missing_file_gives_empty_url_without_fallback_image(): void
    {
        $before = $this->countLinks();
        $this->assertSame('', Media::url(12, '999-card'));
        $this->assertSame('', Media::url(12, 'avatar-ffffffff'));
        $this->assertSame($before, $this->countLinks());
    }

    public function test_url_does_not_create_directories(): void
    {
        rmdir($this->root . '/portfolio/12');
        rmdir($this->root . '/portfolio');
        $this->assertSame('', Media::url(12, '345-card'));
        $this->assertDirectoryDoesNotExist($this->root . '/portfolio');
    }

    public function test_path(): void
    {
        $this->assertSame($this->root . '/portfolio/12/345-card.webp', Media::path(12, '345-card'));
    }

    private function countLinks(): int
    {
        return (int)shell_exec('find ' . escapeshellarg(self::LINKS . 'ci') . ' -type l | wc -l');
    }
}
