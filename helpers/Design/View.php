<?php

namespace Design;

/** PHP-шаблоны из views/ */
final class View
{
    public static function render(string $name, array $vars = []): void
    {
        echo self::fetch($name, $vars);
    }

    public static function fetch(string $name, array $vars = []): string
    {
        $file = dirname(__DIR__, 2) . '/views/' . $name . '.php';
        extract($vars, EXTR_SKIP);
        ob_start();
        require $file;
        return ob_get_clean();
    }

    public static function e(?string $s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Свой css/js с версией по времени файла */
    public static function asset(string $path): string
    {
        $t = @filemtime(dirname(__DIR__, 2) . $path);
        return $t ? "$path?v=$t" : $path;
    }

    private const MONTHS = ['січня', 'лютого', 'березня', 'квітня', 'травня', 'червня', 'липня', 'серпня', 'вересня', 'жовтня', 'листопада', 'грудня'];

    /** '2026-11-11 14:20:00' → «11 листопада» */
    public static function date(?string $dt): string
    {
        $t = $dt ? strtotime($dt) : false;
        return $t ? (int)date('j', $t) . ' ' . self::MONTHS[(int)date('n', $t) - 1] : '';
    }

    /** «сьогодні о 09:40» / «11 листопада о 09:40» */
    public static function when(?string $dt): string
    {
        $t = $dt ? strtotime($dt) : false;
        if (!$t) return '';
        $day = date('Y-m-d', $t) === date('Y-m-d') ? 'сьогодні' : self::date($dt);
        return $day . ' о ' . date('H:i', $t);
    }

    /** 2450 → «2 450 грн» */
    public static function price(int $v): string
    {
        return number_format($v, 0, ',', "\u{00A0}") . "\u{00A0}грн";
    }
}
