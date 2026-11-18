<?php
use Design\Designers;
use Design\Guard;
use Design\Http;
use Design\View;

Guard::customer();
$q = trim(mb_substr(Http::str($_GET, 'q'), 0, Designers::NAME_MAX));
$page = max(1, min(1000, (int)Http::str($_GET, 'page')));
$list = Designers::catalog($q, $page);
if ($page > $list['pages']) {   // застаріле посилання на сторінку, якої вже немає: остання сторінка замість порожньої
    $page = $list['pages'];
    $list = Designers::catalog($q, $page);
}
View::render('layout', ['title' => 'Дизайнери', 'active' => 'designers',
    'body' => View::fetch('designers', ['q' => $q, 'page' => $page] + $list)]);
