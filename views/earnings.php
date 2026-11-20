<?php
/**
 * «Заробіток» дизайнера: Purchases::forDesigner (нові зверху) і сума за весь час
 * @var list<array{paid_at:string, order_id:int, title:string, amount_designer:int}> $rows @var int $total
 */
use Design\View;
?>
<div class="container page">
  <h1>Заробіток</h1>
  <div class="earn-total card-d">
    <span class="ic"><i class="fal fa-wallet"></i></span>
    <div>
      <div class="text-2 small">За весь час, після купівлі макетів клієнтами</div>
      <div class="earn-sum">Разом: <strong><?= View::e(View::price($total)) ?></strong></div>
    </div>
  </div>
  <p class="text-2 earn-note"><i class="fal fa-info-circle"></i> Для виведення коштів зверніться до менеджера</p>

  <?php if (!$rows): ?>
    <div class="card-d empty-state">
      <span class="ic"><i class="fal fa-coins"></i></span>
      <h2 class="h3">Поки що немає купівель</h2>
      <p class="text-2 mb-0">Щойно клієнт купить ваші макети, гонорар зʼявиться тут і на вашому балансі wolf.ua.</p>
    </div>
  <?php else: ?>
    <div class="card-d earn-list">
      <?php foreach ($rows as $r): ?>
        <a class="earn-row" href="/feed/<?= $r['order_id'] ?>/">
          <span class="when"><?= View::e(date("d.m.Y", strtotime($r["paid_at"]))) ?></span>
          <span class="num">№<?= $r['order_id'] ?></span>
          <span class="title"><?= View::e($r['title']) ?></span>
          <span class="sum">+<?= View::e(View::price($r['amount_designer'])) ?></span>
        </a>
      <?php endforeach ?>
    </div>
  <?php endif ?>
</div>
