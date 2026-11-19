<?php
// Тести ніколи не шлють справжніх SMS — навіть якщо PHPUnit запущено без phpunit.xml (NotifyTest вмикає «on» сам, з підміною transport)
putenv('DESIGN_NOTIFY=off');
$_ENV['DESIGN_NOTIFY'] = 'off';

require dirname(__DIR__) . '/helpers/Design/bootstrap.php';
require __DIR__ . '/CdnLinksGuard.php';

// Тести в CLI вважають себе продом (адреси без «dev.»), навіть коли запущені на dev-сервері; ConfigTest/NotifyTest ставлять маркер самі
Design\Config::$devMarker = '/немає-маркера-dev-сервера';
