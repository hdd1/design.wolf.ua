<?php
use Design\View;

View::render('layout', ['title' => $title, 'body' => '
<div class="container section text-center">
  <h1>Сторінку не знайдено</h1>
  <p class="text-2">Можливо, посилання застаріло.</p>
  <a class="btn btn-primary" href="/">На головну</a>
</div>']);
