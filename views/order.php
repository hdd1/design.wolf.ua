<?php
/** @var array $order Orders::get + turn */
use Design\OrderStatus;
use Design\View;

$filled = count(array_filter($order['answers'], static fn($p) => $p['a'] !== ''));
?>
<div class="container page"><div class="narrow">
  <a class="link-back" href="/orders/"><i class="fal fa-arrow-left"></i> Мої замовлення</a>
  <div class="d-flex flex-wrap align-items-center gap-3 mt-3">
    <h1 class="mb-0"><?= View::e($order['title']) ?></h1><?php View::render('partials/status', ['status' => $order['status']]) ?>
  </div>
  <div class="alert-d info mt-4">
    <span class="ic"><i class="fal fa-hourglass-half"></i></span>
    <div><div class="t"><?= View::e($order['turn']['text']) ?></div><div class="text-2 small">Опубліковано <?= View::e(View::when($order['published_at'])) ?></div></div>
  </div>
  <details class="brief-box card-d mt-3">
    <summary><i class="fal fa-file-alt"></i> Бриф · <?= View::e($order['product_name']) ?> · <?= $filled ?> <?= OrderStatus::plural($filled, 'відповідь', 'відповіді', 'відповідей') ?><span class="more"></span></summary>
    <dl>
      <?php foreach ($order['answers'] as $p): ?>
        <dt><?= View::e($p['q']) ?></dt><dd><?= $p['a'] !== '' ? nl2br(View::e($p['a'])) : '<span class="text-3">—</span>' ?></dd>
      <?php endforeach ?>
    </dl>
  </details>
  <?php if ($order['status'] === OrderStatus::COLLECTING): ?>
    <div class="card-d empty-state mt-3">
      <span class="ic"><i class="fal fa-inbox"></i></span>
      <h2 class="h4 fw-bold" style="font-family:var(--font-ui)">Пропозицій поки немає</h2>
      <p class="text-2 mb-0">Дизайнери вже бачать ваш бриф. Зазвичай перші пропозиції приходять протягом дня.</p>
    </div>
  <?php endif ?>
</div></div>
