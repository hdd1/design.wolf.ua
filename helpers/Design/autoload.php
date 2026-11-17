<?php
// Автозагрузка Design\X → helpers/Design/X.php для веба, CLI и тестов
spl_autoload_register(function (string $class): void {
    if (strncmp($class, 'Design\\', 7) !== 0) return;
    $file = dirname(__DIR__) . '/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($file)) require $file;
});
