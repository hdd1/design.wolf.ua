<?php
/** @var array $params */
use Design\Designers;
use Design\Guard;
use Design\Http;
use Design\View;

Guard::customer();
$d = Designers::publicProfile((int)$params['id']);
if ($d === null) Http::notFound();   // не схвалений або заблокований — для клієнта його немає
View::render('layout', ['title' => $d['name'], 'active' => 'designers', 'scripts' => ['/assets/js/designer-public.js'],
    'body' => View::fetch('designer-public', ['d' => $d])]);
