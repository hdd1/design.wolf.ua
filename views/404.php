<?php
use Design\View;

View::render('layout', ['title' => 'Сторінку не знайдено', 'body' => '
<div class="container page"><div class="empty-state narrow">
  <div class="font-head" style="font-size:6rem;line-height:1;color:var(--border-strong)">404</div>
  <h1 class="h2 mt-3">Сторінку не знайдено</h1>
  <p class="text-2">Можливо, посилання застаріло або замовлення належить іншому акаунту wolf.ua. Перевірте, під яким акаунтом ви увійшли.</p>
  <div class="d-flex gap-2 justify-content-center flex-wrap"><a class="btn btn-primary" href="/orders/">Мої замовлення</a><a class="btn btn-outline-blue" href="/">На головну</a></div>
</div></div>']);
