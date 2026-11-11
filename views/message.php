<?php
/** @var string $title @var string $text */
use Design\View;

View::render('layout', ['title' => $title, 'body' => '
<div class="container page"><div class="empty-state card-d narrow">
  <h1 class="h2">' . View::e($title) . '</h1>
  <p class="text-2">' . View::e($text) . '</p>
  <a class="btn btn-primary" href="/">На головну</a>
</div></div>']);
