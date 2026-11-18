<?php
/**
 * Опубліковане замовлення клієнта (макети 6.4): шапка, «чий хід», бриф, пропозиції або стан режиму 2, листування збоку
 * (на телефоні — окремим екраном, коли відкрите посиланням ?chat=)
 * @var array $order Orders::get @var ?string $state Offers::mode2State()['state'] @var array{mine:bool,icon:string,title:string,sub:string} $alert
 * @var ?string $error @var bool $list чи показувати пропозиції @var list<array> $cards Offers::forOrder без відмов
 * @var ?array $chosen обрана пропозиція @var \Closure $choosable @var ?array $waiting @var ?array $refused картки режиму 2
 * @var ?array $reason повідомлення дизайнера з причиною відмови @var int $sel відкрите листування
 * @var ?array $chat параметри partials/chat @var bool $chatOpen @var string $csrf
 */
use Design\Chat;
use Design\OrderStatus;
use Design\View;

$id = $order['id'];
$filled = count(array_filter($order['answers'], static fn($p) => $p['a'] !== ''));
?>
<div class="container page<?= $chatOpen ? ' chat-open' : '' ?>">
  <div class="<?= $chat !== null ? 'row g-4 order-grid' : 'narrow' ?>">
    <div class="<?= $chat !== null ? 'col-lg-7 op-main' : '' ?>">
      <a class="link-back" href="/orders/"><i class="fal fa-arrow-left"></i> Мої замовлення</a>
      <div class="eyebrow mt-3">№<?= $id ?> · <?= View::e($order['product_name']) ?></div>
      <div class="d-flex flex-wrap align-items-center gap-3 mt-1">
        <h1 class="order-title mb-0"><?= View::e($order['title']) ?></h1>
        <?php if ($state === 'declined'): ?>
          <span class="status s8"><i class="fal fa-user-times"></i>Дизайнер відмовився</span>
        <?php else: ?>
          <?php View::render('partials/status', ['status' => $order['status']]) ?>
        <?php endif ?>
      </div>

      <?php if ($error !== null): ?>
        <div class="alert-d error mt-4" role="alert"><i class="fal fa-exclamation-circle mt-1"></i><div class="fw-semibold"><?= View::e($error) ?></div></div>
      <?php endif ?>

      <div class="alert-d <?= $alert['mine'] ? 'turn' : 'info' ?> mt-4">
        <span class="ic"><i class="fal <?= $alert['icon'] ?>"></i></span>
        <div><div class="t"><?= View::e($alert['title']) ?></div><?php if ($alert['sub'] !== ''): ?><div class="text-2 small"><?= View::e($alert['sub']) ?></div><?php endif ?></div>
      </div>

      <details class="brief-box card-d mt-3">
        <summary><i class="fal fa-file-alt"></i> Бриф · <?= View::e($order['product_name']) ?> · <?= $filled ?> <?= OrderStatus::plural($filled, 'відповідь', 'відповіді', 'відповідей') ?><span class="more"></span></summary>
        <dl class="qa-grid">
          <?php foreach ($order['answers'] as $p): ?>
            <div><dt><?= View::e($p['q']) ?></dt><dd><?= $p['a'] !== '' ? nl2br(View::e($p['a'])) : '<span class="text-3">—</span>' ?></dd></div>
          <?php endforeach ?>
        </dl>
      </details>

      <?php if ($state === 'waiting' && $waiting !== null): ?>
        <div class="card-d wait-card mt-3">
          <?php View::render('partials/designer-avatar', ['designerId' => $waiting['designer_id'], 'fullName' => $waiting['designer_name'], 'photo' => $waiting['designer_photo'], 'class' => 'avatar-md']) ?>
          <div class="min-w-0"><div class="oc-name"><?= View::e($waiting['designer_name']) ?></div><div class="text-3 small">Запрошення надіслано</div></div>
          <?php if ($waiting['available']): ?><a class="oc-link ms-auto" href="/designers/<?= $waiting['designer_id'] ?>/"><i class="fal fa-images"></i> Портфоліо</a><?php endif ?>
        </div>
        <p class="text-2 mt-3">Коли дизайнер погодиться, тут зʼявляться ціна і термін — ви підтвердите вибір.</p>

      <?php elseif ($state === 'declined'): ?>
        <?php if ($reason !== null): $who = $refused['designer_name'] !== '' ? $refused['designer_name'] : 'Дизайнер' ?>
          <div class="chat-msg decline-msg mt-3">
            <span class="avatar avatar-sm"><?= View::e(Chat::initials($who)) ?></span>
            <div class="bubble">
              <div class="meta"><b><?= View::e($who) ?> · відмова</b> <?= View::e(substr($reason['created'], 11, 5)) ?></div>
              <div class="text"><?= Chat::html($reason['text']) ?></div>
            </div>
          </div>
        <?php else: ?>
          <p class="text-2 mt-3 mb-0">Дизайнер не вказав причину. Ваш бриф збережено — його можна віддати іншому дизайнеру або всім одразу.</p>
        <?php endif ?>
        <div class="row g-4 mt-0">
          <div class="col-md-6">
            <div class="card-d next-card">
              <i class="fal fa-users ic"></i>
              <h2>Обрати іншого дизайнера</h2>
              <p class="text-2">Ваш бриф збережено — оберіть зі списку, і запрошення піде новому дизайнеру.</p>
              <a class="btn btn-outline-blue btn-lg w-100 mt-auto" href="/orders/<?= $id ?>/pick/">Обрати іншого</a>
            </div>
          </div>
          <div class="col-md-6">
            <form class="card-d next-card accent" method="post" action="/orders/<?= $id ?>" data-lock>
              <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
              <i class="fal fa-inbox ic"></i>
              <h2>Зібрати пропозиції</h2>
              <p class="text-2">Бриф побачать усі дизайнери Майстерні. Пропозиції — зазвичай протягом дня.</p>
              <button class="btn btn-primary btn-lg w-100 mt-auto" type="submit" name="action" value="collect">Зібрати пропозиції</button>
            </form>
          </div>
        </div>

      <?php elseif ($cards): ?>
        <?php View::render('order-offers', ['orderId' => $id, 'cards' => $cards, 'chosen' => $chosen, 'choosable' => $choosable, 'sel' => $sel, 'csrf' => $csrf]) ?>

      <?php elseif ($order['status'] === OrderStatus::COLLECTING): ?>
        <div class="card-d empty-state mt-3">
          <span class="ic"><i class="fal fa-inbox"></i></span>
          <h2 class="h4 fw-bold" style="font-family:var(--font-ui)">Пропозицій поки немає</h2>
          <p class="text-2 mb-0">Дизайнери вже бачать ваш бриф. Зазвичай перші пропозиції приходять протягом дня — ми надішлемо сповіщення.</p>
        </div>
      <?php endif ?>
    </div>

    <?php if ($chat !== null): ?>
      <div class="col-lg-5 op-chat" id="chat">
        <div class="fo-chat">
          <a class="link-back op-chat-back" href="/orders/<?= $id ?>/#offer-<?= $sel ?>"><i class="fal fa-arrow-left"></i> Усі пропозиції</a>
          <a class="icon-btn op-chat-close" href="/orders/<?= $id ?>/?chat=0" aria-label="Закрити листування"><i class="fal fa-times"></i></a>
          <?php View::render('partials/chat', $chat) ?>
        </div>
      </div>
    <?php endif ?>
  </div>
</div>
