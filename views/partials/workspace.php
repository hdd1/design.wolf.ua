<?php
/**
 * Робочий простір замовлення «В роботі» (макети 6.5): шапка замовлення (ціна й термін окремим блоком, головна кнопка),
 * «чий хід», бриф, широка стрічка листування і панель «Макети для друку» праворуч; на телефоні — вкладки «Чат / Макети».
 * Однаковий для обох сторін: views/order.php (клієнт) і views/feed-order.php (дизайнер).
 * Клієнт: «Ціна · Термін», «Купую» неактивна з «Незабаром». Дизайнер: «Ви отримаєте D грн · клієнт побачить P грн», «Термін до …»
 * (без «Змінити»: ціна й термін після вибору не змінюються), «Завантажити макети» (веде до панелі)
 * @var string $role         client | designer
 * @var array $order         Orders::get / Orders::find (id, title, product_name, status, answers)
 * @var array $offer         обрана пропозиція (Offers::get): price_designer, price_client, deadline
 * @var array{href:string, label:string} $back  куди веде перша ланка «хлібних крихт»
 * @var string $who          «Клієнт Олена М.» / «Дизайнер Петро Коваль»
 * @var string $whoName      ім'я для ініціалів
 * @var array{mine:bool, text:string, sub:string, icon:string} $turn  Workspace::turn
 * @var array $chat          параметри partials/chat (з workspace)
 * @var array $panel         Workspace::layouts
 * @var bool $edit           дизайнер може завантажувати й змінювати макети
 * @var string $csrf
 * @var int $chunk
 * @var ?string $error       помилка дії з форми сторінки (POST, що повернув сторінку з 422), null — немає
 */
use Design\Chat;
use Design\OrderStatus;
use Design\View;

$id = $order['id'];
$designer = $role === 'designer';
$filled = count(array_filter($order['answers'], static fn($p) => $p['a'] !== ''));
$error ??= null;
$deadline = $offer['deadline'] ? 'до ' . View::date($offer['deadline']) : '—';
?>
<div class="ws-top">
  <div class="container">
    <nav class="ws-crumbs" aria-label="Шлях">
      <a class="ws-back" href="<?= View::e($back['href']) ?>" aria-label="<?= View::e($back['label']) ?>"><i class="fal fa-arrow-left"></i></a>
      <a class="ws-crumb" href="<?= View::e($back['href']) ?>"><?= View::e($back['label']) ?></a>
      <i class="fal fa-chevron-right ws-crumb-sep" aria-hidden="true"></i>
      <span class="ws-crumb-cur">№<?= $id ?> · <?= View::e($order['product_name']) ?></span>
    </nav>
    <div class="ws-head">
      <div class="ws-head-main">
        <div class="ws-title-row">
          <h1 class="ws-title"><?= View::e($order['title']) ?></h1>
          <?php View::render('partials/status', ['status' => $order['status']]) ?>
        </div>
        <div class="ws-who"><span class="avatar avatar-xs"><?= View::e(Chat::initials($whoName)) ?></span><span><span class="ws-num">№<?= $id ?> · </span><?= View::e($who) ?></span></div>
      </div>
      <div class="ws-terms">
        <?php if ($designer): ?>
        <div class="ws-term"><span class="lbl">Ви отримаєте</span> <b class="val"><?= View::e(View::price($offer['price_designer'])) ?></b> <span class="hint"><span class="sep">· </span>клієнт побачить <?= View::e(View::price($offer['price_client'])) ?></span></div>
        <?php else: ?>
        <div class="ws-term"><span class="lbl">Ціна</span> <b class="val"><?= View::e(View::price($offer['price_client'])) ?></b></div>
        <?php endif ?>
        <div class="ws-term ws-deadline"><span class="lbl">Термін</span> <b class="val-sm"><?= View::e($deadline) ?></b></div>
      </div>
      <div class="ws-cta">
        <?php if ($designer): ?>
          <?php if ($edit): ?><button type="button" class="btn btn-primary btn-lg" data-ws-upload><i class="fal fa-upload"></i> Завантажити макети</button><?php endif ?>
        <?php else: ?>
          <button type="button" class="btn btn-primary btn-lg ws-buy" disabled aria-describedby="ws-soon-<?= $id ?>"><i class="fal fa-shopping-bag"></i> Купую</button>
          <div class="ws-soon" id="ws-soon-<?= $id ?>">Незабаром</div>
        <?php endif ?>
      </div>
    </div>
    <?php if ($error !== null): ?>
    <div class="alert-d error ws-turn" role="alert"><i class="fal fa-exclamation-circle mt-1"></i><div class="fw-semibold"><?= View::e($error) ?></div></div>
    <?php endif ?>
    <div class="alert-d <?= $turn['mine'] ? 'turn' : 'info' ?> ws-turn" data-ws-turn>
      <span class="ic"><i class="fal <?= View::e($turn['icon']) ?>" data-ws-turn-icon></i></span>
      <div><div class="t" data-ws-turn-text><?= View::e($turn['text']) ?></div><div class="text-2 small" data-ws-turn-sub<?= $turn['sub'] === '' ? ' hidden' : '' ?>><?= View::e($turn['sub']) ?></div></div>
    </div>
    <details class="brief-box card-d ws-brief">
      <summary><i class="fal fa-file-alt"></i> Бриф · <?= View::e($order['product_name']) ?> · <?= $filled ?> <?= OrderStatus::plural($filled, 'відповідь', 'відповіді', 'відповідей') ?><span class="more"></span></summary>
      <dl class="qa-grid">
        <?php foreach ($order['answers'] as $p): ?>
          <div><dt><?= View::e($p['q']) ?></dt><dd><?= $p['a'] !== '' ? nl2br(View::e($p['a'])) : '<span class="text-3">—</span>' ?></dd></div>
        <?php endforeach ?>
      </dl>
    </details>
  </div>
</div>
<div class="ws-tabs" role="tablist" aria-label="Робочий простір" data-ws-tabs>
  <button type="button" role="tab" class="active" aria-selected="true" aria-controls="ws-feed-<?= $id ?>" data-ws-tab="chat">Чат</button>
  <button type="button" role="tab" aria-selected="false" aria-controls="ws-side-<?= $id ?>" data-ws-tab="layouts"><i class="fal <?= $designer ? 'fa-file-upload' : 'fa-lock-alt' ?>"></i><span>Макети<span data-lp-count-wrap<?= $panel['count'] ? '' : ' hidden' ?>> · <span data-lp-count><?= $panel['count'] ?></span></span></span></button>
</div>
<div class="container ws-body" data-ws-body data-tab="chat">
  <div class="ws-grid">
    <div class="ws-feed" id="ws-feed-<?= $id ?>" role="tabpanel"><?php View::render('partials/chat', $chat) ?></div>
    <aside class="ws-side" id="ws-side-<?= $id ?>" role="tabpanel"><?php View::render('partials/layouts-panel', [
        'orderId' => $id, 'panel' => $panel, 'edit' => $edit, 'csrf' => $csrf, 'chunk' => $chunk]) ?></aside>
  </div>
</div>
