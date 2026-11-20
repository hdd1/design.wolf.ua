<?php
/**
 * «Мої пропозиції і замовлення» (макет 6.9, вкладки «Пропозиції» і «В роботі»; «Завершені» і «Заробіток» — етап 5)
 * @var string $tab offers | work @var string $csrf
 * @var list<array{id:int, order_id:int, status:int, order_status:int, mode:int, product_name:string, title:string, client:string, text:string, price_designer:int, price_client:int, deadline:?string, messages:int, updated:string}> $offers
 * @var list<array> $work  Offers::mine + turn — «чий хід» дизайнера (Workspace::turn) для замовлень «В роботі», інакше null
 */
use Design\OfferStatus;
use Design\OrderStatus;
use Design\View;

$terms = static fn(array $o): string => View::price($o['price_designer']) . ' на руки' . ($o['deadline'] ? ' · до ' . View::date($o['deadline']) : '');
$chat = static fn(array $o): string => $o['messages'] > 0
    ? '<i class="fal fa-comment"></i> Листування · ' . $o['messages']
    : '<i class="fal fa-hourglass-half"></i> Клієнт обирає';
?>
<div class="container page">
  <h1>Мої пропозиції і замовлення</h1>
  <nav class="tabs-d" aria-label="Розділи">
    <a href="/my-offers/" class="<?= $tab === 'offers' ? 'active' : '' ?>"<?= $tab === 'offers' ? ' aria-current="page"' : '' ?>>Пропозиції <span class="n"><?= count($offers) ?></span></a>
    <a href="/my-offers/?tab=work" class="<?= $tab === 'work' ? 'active' : '' ?>"<?= $tab === 'work' ? ' aria-current="page"' : '' ?>>В роботі <span class="n"><?= count($work) ?></span></a>
  </nav>

  <?php if ($tab === 'offers'): ?>
    <?php if (!$offers): ?>
      <div class="card-d empty-state">
        <span class="ic"><i class="fal fa-paper-plane"></i></span>
        <h2 class="h3">Ви ще нічого не пропонували</h2>
        <p class="text-2">Оберіть бриф у стрічці і запропонуйте себе — клієнт побачить вашу ціну і термін.</p>
        <a class="btn btn-primary" href="/feed/">Стрічка брифів</a>
      </div>
    <?php endif ?>
    <?php foreach ($offers as $o): $url = '/feed/' . $o['order_id'] . '/'; $st = $o['status'];
        $reopen = in_array($st, [OfferStatus::DECLINED, OfferStatus::WITHDRAWN], true) && $o['order_status'] === OrderStatus::COLLECTING && $o['mode'] === 1;
        $grey = !in_array($st, [OfferStatus::INVITED, OfferStatus::SENT], true) ?>
      <div class="offer-row<?= $grey ? ' is-closed' : '' ?><?= $st === OfferStatus::INVITED ? ' mine' : '' ?>">
        <div class="min-w-0">
          <div class="num">№<?= $o['order_id'] ?> · <?= View::e(View::date($o['updated'])) ?></div>
          <a class="title" href="<?= $url ?>"><?= View::e($o['title']) ?></a>
        </div>
        <div class="min-w-0">
          <?php if ($st === OfferStatus::INVITED): ?>
            <div class="head">Вас обрали для замовлення — береш?</div>
            <div class="sub"><i class="fal fa-user"></i> Клієнт <?= View::e($o['client']) ?> чекає відповіді</div>
          <?php elseif ($st === OfferStatus::SENT): ?>
            <div class="head">Ви запропонували <?= View::e($terms($o)) ?></div>
            <div class="sub<?= $o['messages'] > 0 ? ' blue' : '' ?>"><?= $chat($o) ?></div>
          <?php elseif ($st === OfferStatus::OTHER_CHOSEN): ?>
            <div class="head">Ви запропонували <?= View::e(View::price($o['price_designer'])) ?> на руки</div>
            <div class="sub"><i class="fal fa-user-times"></i> Клієнт обрав іншого дизайнера</div>
          <?php elseif ($st === OfferStatus::WITHDRAWN): ?>
            <div class="head">Ви відкликали пропозицію</div>
            <div class="sub"><?= $reopen ? '<i class="fal fa-inbox"></i> Бриф ще збирає пропозиції' : '<i class="fal fa-lock-alt"></i> Замовлення вже не приймає пропозицій' ?></div>
          <?php else: ?>
            <div class="head">Ви відмовилися від запрошення</div>
            <div class="sub"><?= $reopen ? '<i class="fal fa-inbox"></i> Тепер бриф відкритий для всіх' : '<i class="fal fa-lock-alt"></i> Замовлення вже не приймає пропозицій' ?></div>
          <?php endif ?>
        </div>
        <div class="acts">
          <?php if ($st === OfferStatus::INVITED): ?>
            <a class="btn btn-primary" href="<?= $url ?>">Відповісти</a>
          <?php elseif ($st === OfferStatus::SENT): ?>
            <a class="lnk" href="<?= $url ?>?form=edit#offer">Змінити</a>
            <form method="post" action="/feed/<?= $o['order_id'] ?>" data-lock data-confirm="Відкликати пропозицію? Клієнт побачить, що ви її відкликали.">
              <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>"><input type="hidden" name="action" value="withdraw"><input type="hidden" name="back" value="my">
              <button type="submit" class="link-red">Відкликати</button>
            </form>
            <a class="btn btn-outline-blue" href="<?= $url ?>#chat">Листування</a>
          <?php elseif ($reopen): ?>
            <a class="btn btn-outline-blue" href="<?= $url ?>?form=propose#offer"><?= $st === OfferStatus::WITHDRAWN ? 'Запропонувати знову' : 'Запропонувати себе' ?></a>
          <?php elseif ($o['messages'] > 0): ?>
            <a class="lnk" href="<?= $url ?>#chat">Листування</a>
          <?php endif ?>
        </div>
      </div>
    <?php endforeach ?>

  <?php else: ?>
    <?php if (!$work): ?>
      <div class="card-d empty-state">
        <span class="ic"><i class="fal fa-pencil-ruler"></i></span>
        <h2 class="h3">Поки немає замовлень у роботі</h2>
        <p class="text-2 mb-0">Коли клієнт обере вашу пропозицію, замовлення зʼявиться тут.</p>
      </div>
    <?php endif ?>
    <?php foreach ($work as $o): ?>
      <a class="order-row<?= ($o['turn']['mine'] ?? true) ? ' mine' : '' ?>" href="/feed/<?= $o['order_id'] ?>/">
        <div><div class="num">№<?= $o['order_id'] ?> · клієнт <?= View::e($o['client']) ?><?= $o['deadline'] ? ' · до ' . View::e(View::date($o['deadline'])) : '' ?></div><div class="title"><?= View::e($o['title']) ?></div></div>
        <div><?php View::render('partials/status', ['status' => $o['order_status']]) ?><?php if ($o['turn'] !== null): ?><div class="turn"><i class="fal <?= $o['turn']['mine'] ? 'fa-hand-paper' : 'fa-hourglass-half' ?> me-1"></i><?= View::e($o['turn']['text']) ?></div><?php else: ?><div class="turn"><i class="fal fa-hand-paper me-1"></i>Клієнт обрав вас<?= $o['deadline'] ? ' · готово до ' . View::e(View::date($o['deadline'])) : '' ?></div><?php endif ?></div>
        <div class="who"><?= $o['messages'] > 0 ? 'Листування · ' . $o['messages'] : '' ?></div>
        <div class="sum"><?= View::e(View::price($o['price_designer'])) ?></div>
        <i class="fal fa-chevron-right chev"></i>
      </a>
    <?php endforeach ?>
  <?php endif ?>
</div>
