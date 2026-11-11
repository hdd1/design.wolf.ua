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
}
