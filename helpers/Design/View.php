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

    /** Українська множина: plural(2, 'робота', 'роботи', 'робіт') → «2 роботи» */
    public static function plural(int $n, string $one, string $few, string $many): string
    {
        $d = $n % 10;
        $h = $n % 100;
        return $n . ' ' . ($d === 1 && $h !== 11 ? $one : ($d >= 2 && $d <= 4 && ($h < 12 || $h > 14) ? $few : $many));
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

    /**
     * Розмір файла для людей: 512 → «512 Б», 870400 → «850 КБ», 2516582 → «2,4 МБ», 1181116006 → «1,1 ГБ».
     * Кома в десятковій частині, НБСП перед одиницею, «,0» не пишемо («5 ГБ»); КБ — цілі
     */
    public static function bytes(int $n): string
    {
        $nb = "\u{00A0}";
        if ($n < 1024) return max(0, $n) . $nb . 'Б';
        $units = ['КБ', 'МБ', 'ГБ', 'ТБ'];
        $v = $n / 1024;
        $i = 0;
        while (true) {
            $r = round($v, $i === 0 ? 0 : 1);
            // 1023,96 МБ округлюється до 1024 — це вже наступна одиниця
            if ($r >= 1024 && $i < count($units) - 1) {
                $v /= 1024;
                $i++;
                continue;
            }
            break;
        }
        $s = number_format($r, $i === 0 ? 0 : 1, ',', '');
        return preg_replace('/,0$/', '', $s) . $nb . $units[$i];
    }
}
