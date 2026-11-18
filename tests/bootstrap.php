<?php
// Тести ніколи не шлють справжніх SMS — навіть якщо PHPUnit запущено без phpunit.xml (NotifyTest вмикає «on» сам, з підміною transport)
putenv('DESIGN_NOTIFY=off');
$_ENV['DESIGN_NOTIFY'] = 'off';

require dirname(__DIR__) . '/helpers/Design/bootstrap.php';
