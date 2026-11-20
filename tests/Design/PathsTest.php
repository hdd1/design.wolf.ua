<?php

use Design\Paths;
use PHPUnit\Framework\TestCase;

/** Справжню /mnt/work/source.wolf.ua/design тести не чіпають: DESIGN_WORK_ROOT — тимчасова папка */
final class PathsTest extends TestCase
{
    private string $tmp = '';
    /** @var string|false */
    private $oldRoot;
    private int $oldUmask;

    protected function setUp(): void
    {
        $this->oldRoot = getenv('DESIGN_WORK_ROOT');
        $this->oldUmask = umask(022);
        $this->tmp = sys_get_temp_dir() . '/design-paths-test-' . getmypid() . '-' . bin2hex(random_bytes(4));
        putenv('DESIGN_WORK_ROOT=' . $this->tmp);
    }

    protected function tearDown(): void
    {
        umask($this->oldUmask);
        if ($this->oldRoot === false) {
            putenv('DESIGN_WORK_ROOT');
        } else {
            putenv('DESIGN_WORK_ROOT=' . $this->oldRoot);
        }
        $this->rmTree($this->tmp);
    }

    private function rmTree(string $d): void
    {
        if (!is_dir($d)) {
            return;
        }
        @chmod($d, 0700);
        foreach (scandir($d) as $f) {
            if ($f !== '.' && $f !== '..') {
                is_dir("$d/$f") && !is_link("$d/$f") ? $this->rmTree("$d/$f") : @unlink("$d/$f");
            }
        }
        @rmdir($d);
    }

    private function mode(string $path): string
    {
        clearstatcache(true, $path);
        return substr(sprintf('%o', fileperms($path)), -4);
    }

    public function test_root_from_env_and_default(): void
    {
        $this->assertSame($this->tmp, Paths::root());
        putenv('DESIGN_WORK_ROOT=' . $this->tmp . '/');
        $this->assertSame($this->tmp, Paths::root(), 'слеш наприкінці прибирається');
        putenv('DESIGN_WORK_ROOT');
        $this->assertSame('/mnt/work/source.wolf.ua/design', Paths::root());
    }

    public function test_previews_created_0755_with_parents(): void
    {
        $dir = Paths::previews(123);
        $this->assertSame($this->tmp . '/previews/123', $dir);
        $this->assertDirectoryExists($dir);
        $this->assertSame('0755', $this->mode($dir));
        $this->assertSame('0755', $this->mode($this->tmp . '/previews'));
        $this->assertSame('0755', $this->mode($this->tmp));
    }

    public function test_layouts_created_0755_with_parents(): void
    {
        $dir = Paths::layouts(123);
        $this->assertSame($this->tmp . '/layouts/123', $dir);
        $this->assertDirectoryExists($dir);
        $this->assertSame('0755', $this->mode($dir));
        $this->assertSame('0755', $this->mode($this->tmp . '/layouts'));
        $this->assertSame('0755', $this->mode($this->tmp), 'корінь — 0755');
    }

    public function test_modes_do_not_depend_on_umask(): void
    {
        umask(077);
        $this->assertSame('0755', $this->mode(Paths::previews(5)));
        umask(0);
        $this->assertSame('0755', $this->mode(Paths::layouts(5)));
    }

    public function test_repeat_call_keeps_existing_folder_untouched(): void
    {
        $dir = Paths::previews(7);
        chmod($dir, 0750);
        $this->assertSame($dir, Paths::previews(7));
        $this->assertSame('0750', $this->mode($dir), 'наявну папку не чіпаємо');

        $ldir = Paths::layouts(7);
        file_put_contents($ldir . '/1.pdf', 'x');
        $this->assertSame($ldir, Paths::layouts(7));
        $this->assertFileExists($ldir . '/1.pdf');
    }
}
