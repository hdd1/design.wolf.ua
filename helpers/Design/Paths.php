<?php

namespace Design;

/**
 * Папки файлів робочого простору (етап 4).
 * root/previews/{orderId}/ — превʼю (0755), root/layouts/{orderId}/ — макети (0755; з етапу 5 звичайні файли, без закритих прав).
 * Права — явним chmod: umask php-fpm 022, а /mnt/work відкритий для «інших».
 */
final class Paths
{
    private const DEFAULT_ROOT = '/mnt/work/source.wolf.ua/design';

    public static function root(): string
    {
        $env = getenv('DESIGN_WORK_ROOT');
        return rtrim(is_string($env) && $env !== '' ? $env : self::DEFAULT_ROOT, '/');
    }

    /** Папка превʼю замовлення (nginx читає: 0755); створюється разом із батьківськими */
    public static function previews(int $orderId): string
    {
        return self::ensure(self::root() . '/previews', $orderId, 0755);
    }

    /** Папка макетів замовлення (0755, як і превʼю); створюється разом із батьківськими */
    public static function layouts(int $orderId): string
    {
        return self::ensure(self::root() . '/layouts', $orderId, 0755);
    }

    /** Створює відсутні root, parent і папку замовлення; права ставить лише новим, наявні не чіпає */
    private static function ensure(string $parent, int $orderId, int $mode): string
    {
        $dir = $parent . '/' . $orderId;
        foreach ([self::root() => 0755, $parent => $mode, $dir => $mode] as $d => $m) {
            if (is_dir($d)) {
                continue;
            }
            if (!@mkdir($d, $m, true) && !is_dir($d)) {
                throw new \RuntimeException('Не вдалося створити папку ' . $d);
            }
            chmod($d, $m);
        }
        return $dir;
    }
}
