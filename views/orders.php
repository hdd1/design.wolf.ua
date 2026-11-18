<?php
/** @var list<array> $mine @var list<array> $other  — рядки Orders::forCustomer + ключ turn */
use Design\View;

$row = static function (array $o, bool $isMine): string {
    return '<a class="order-row' . ($isMine ? ' mine' : '') . '" href="/orders/' . $o['id'] . '/">'
        . '<div><div class="num">№' . $o['id'] . ' · ' . View::date($o['created']) . '</div><div class="title">' . View::e($o['title']) . '</div></div>'
        . '<div>' . View::fetch('partials/status', ['status' => $o['status']]) . '<div class="turn"><i class="fal ' . ($isMine ? 'fa-hand-paper' : 'fa-hourglass-half') . ' me-1"></i>' . View::e($o['turn']['text']) . '</div></div>'
        . ($o['designer'] !== ''
            ? '<div class="who chosen"><i class="fal fa-user-check me-1"></i>' . View::e($o['designer']) . '</div><div class="sum">' . View::price($o['price']) . '</div>'
            : '<div class="who">Дизайнера ще не обрано</div><div class="sum text-3">—</div>')
        . '<i class="fal fa-chevron-right chev"></i></a>';
};
?>
<div class="container page">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
    <h1 class="mb-0">Мої замовлення</h1>
    <?php if ($mine || $other): ?><a class="btn btn-primary btn-lg" href="/brief/"><i class="fal fa-plus"></i> Створити бриф</a><?php endif ?>
  </div>
  <?php if (!$mine && !$other): ?>
    <div class="card-d empty-state mt-4">
      <span class="ic"><i class="fal fa-inbox"></i></span>
      <h2 class="h4 fw-bold" style="font-family:var(--font-ui)">Ще немає замовлень</h2>
      <p class="text-2">Створіть бриф — і за день отримаєте пропозиції від дизайнерів з ціною і терміном.</p>
      <a class="btn btn-primary btn-lg" href="/brief/"><i class="fal fa-plus"></i> Створити бриф</a>
    </div>
  <?php else: ?>
    <?php if ($mine): ?>
      <div class="group-title">Ваш хід <span class="count mine"><?= count($mine) ?></span></div>
      <?php foreach ($mine as $o) echo $row($o, true) ?>
    <?php endif ?>
    <?php if ($other): ?>
      <div class="group-title">Інші замовлення <span class="count"><?= count($other) ?></span></div>
      <?php foreach ($other as $o) echo $row($o, false) ?>
    <?php endif ?>
  <?php endif ?>
</div>
