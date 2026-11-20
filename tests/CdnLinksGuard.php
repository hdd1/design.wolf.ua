<?php

/**
 * Прибирання за тестами, які в процесі PHPUnit викликають Media::url → ImageGlobal::encode (дерево cloud-links/ci) або
 * Workspace::layouts → FileGlobal::encode («Завантажити» купленого макета, дерево cloud-links/cf).
 * encode створює в спільному дереві CDN шарди xx/yy/zz і симлінк від користувача процесу
 * (тести йдуть від root — каталоги root:root 755, у них php-fpm уже нічого не створить).
 * snapshot() — до виклику, cleanup() — після: прибирає лише нові симлінки й нові порожні каталоги цього користувача,
 * найглибші спершу. Наявні до тесту записи (і чужі) не чіпає.
 */
final class CdnLinksGuard
{
    public const ROOTS = ['/home/connect/cloud-links/ci', '/home/connect/cloud-links/cf'];

    /** @return array{dirs: array<string, true>, links: array<string, true>} */
    public static function snapshot(): array
    {
        return ['dirs' => array_fill_keys(self::find('d'), true), 'links' => array_fill_keys(self::find('l'), true)];
    }

    /** @param array{dirs: array<string, true>, links: array<string, true>} $before */
    public static function cleanup(array $before): void
    {
        $uid = posix_geteuid();
        clearstatcache();
        foreach (self::find('l') as $l) {
            if (!isset($before['links'][$l]) && is_link($l) && (lstat($l)['uid'] ?? -1) === $uid) unlink($l);
        }
        $dirs = array_filter(self::find('d'), fn($d) => !isset($before['dirs'][$d]) && (stat($d)['uid'] ?? -1) === $uid);
        usort($dirs, fn($a, $b) => substr_count($b, '/') <=> substr_count($a, '/'));
        foreach ($dirs as $d) {
            if (is_dir($d) && count(scandir($d)) === 2) rmdir($d);
        }
    }

    /** @return list<string> каталоги й симлінки дерев шардів, власник яких — користувач цього процесу (одним find) */
    public static function owned(): array
    {
        $out = [];
        $roots = array_filter(self::ROOTS, 'is_dir');
        if ($roots) {
            exec('find ' . implode(' ', array_map('escapeshellarg', $roots)) . ' -mindepth 1 -maxdepth 4 -uid ' . posix_geteuid()
                . ' \( -type d -o -type l \)', $out);
        }
        return $out;
    }

    /** @return list<string> каталоги (d) або симлінки (l) у деревах шардів */
    private static function find(string $type): array
    {
        $out = [];
        foreach (self::ROOTS as $root) {
            if (is_dir($root)) exec('find ' . escapeshellarg($root) . ' -mindepth 1 -maxdepth 4 -type ' . $type, $out);
        }
        return $out;
    }
}

