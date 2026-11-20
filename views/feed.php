<?php
/**
 * Стрічка брифів (макет 6.9 «Стрічка брифів», «… · порожньо»)
 * @var int $page @var int $pages @var int $total @var string $csrf @var array<int,string> $facts customer_id → Customers::factsLine
 * @var list<array{order_id:int, customer_id:int, product_name:string, title:string, published_at:?string, preview:list<array{q:string,a:string}>, offers:int, mine:?array, chosen_other:bool, is_new:bool}> $items  Offers::feed
 */
use Design\OfferStatus;
use Design\View;

$href = static fn(int $p): string => '/feed/' . ($p > 1 ? '?page=' . $p : '');
?>
<div class="container page">
  <h1>Стрічка брифів</h1>
  <p class="lead-d catalog-lead mb-5">
    <?= $total > 0 ? View::e(View::plural($total, 'бриф', 'брифи', 'брифів')) . ' у стрічці · нові зверху' : 'Нових брифів поки немає' ?>
  </p>

  <?php if (!$items): ?>
    <div class="card-d empty-state feed-empty">
      <span class="ic"><i class="fal fa-inbox"></i></span>
      <h2 class="h3">Нових брифів немає</h2>
      <p class="text-2 mx-auto" style="max-width:460px">Щойно клієнт опублікує бриф, він зʼявиться тут, а біля «Стрічка брифів» у меню — кружечок з числом нових. А поки — допишіть профіль: клієнти частіше обирають дизайнерів з 6+ роботами.</p>
      <a class="btn btn-outline-blue" href="/designer/">Оновити профіль</a>
    </div>
  <?php else: ?>
    <div class="row g-4">
      <?php foreach ($items as $b): $url = '/feed/' . $b['order_id'] . '/'; $mine = $b['mine']; $st = $mine['status'] ?? null ?>
        <div class="col-md-6 col-xl-4">
          <article class="brief-card card-d<?= $b['chosen_other'] ? ' is-closed' : '' ?>">
            <div class="bc-head">
              <span class="bc-ic"><i class="fal fa-file-alt"></i></span>
              <div class="min-w-0">
                <a class="bc-title" href="<?= $url ?>"><?= View::e($b['product_name']) ?> · №<?= $b['order_id'] ?></a>
                <?php if ($b['is_new'] && !$b['chosen_other']): ?><span class="new-chip">нове</span><?php endif ?>
                <div class="bc-when">Опубліковано <?= View::e(View::when($b['published_at'])) ?></div>
              </div>
            </div>
            <?php if ($b['title'] !== $b['product_name']): ?><div class="bc-name"><?= View::e($b['title']) ?></div><?php endif ?>
            <div class="bc-facts"><i class="fal fa-user"></i> <?= View::e($facts[$b['customer_id']] ?? '') ?></div>
            <?php if ($b['preview']): ?>
              <dl class="bc-qa">
                <?php foreach ($b['preview'] as $p): ?><dt><?= View::e($p['q']) ?></dt><dd><?= View::e($p['a']) ?></dd><?php endforeach ?>
              </dl>
            <?php endif ?>
            <?php if ($st === OfferStatus::WITHDRAWN): ?>
              <div class="bc-note">Ви відкликали пропозицію — можна надіслати нову</div>
            <?php elseif ($st === OfferStatus::DECLINED): ?>
              <div class="bc-note">Ви відмовлялися від запрошення — тепер бриф відкритий для всіх</div>
            <?php endif ?>
            <div class="bc-foot">
              <span class="bc-count"><i class="fal fa-user-friends"></i> Пропозицій: <?= $b['offers'] ?></span>
              <?php if ($b['chosen_other'] || $st === OfferStatus::SENT): ?>
              <?php else: ?>
                <a class="btn btn-primary" href="<?= $url ?>?form=propose#offer"><?= $st === OfferStatus::WITHDRAWN ? 'Запропонувати знову' : 'Запропонувати себе' ?></a>
              <?php endif ?>
            </div>
            <?php if ($b['chosen_other']): ?>
              <div class="bc-closed"><i class="fal fa-user-check"></i> Вже обрано іншого дизайнера</div>
            <?php elseif ($st === OfferStatus::SENT): ?>
              <div class="bc-mine">
                <div class="t">Ви запропонували <?= View::e(View::price($mine['price_designer'])) ?><?= $mine['deadline'] ? ' · до ' . View::e(View::date($mine['deadline'])) : '' ?></div>
                <div class="links">
                  <a href="<?= $url ?>?form=edit#offer">Змінити</a>
                  <form method="post" action="/feed/<?= $b['order_id'] ?>" data-lock data-confirm="Відкликати пропозицію? Клієнт побачить, що ви її відкликали.">
                    <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>"><input type="hidden" name="action" value="withdraw"><input type="hidden" name="back" value="feed">
                    <button type="submit" class="link-red">Відкликати</button>
                  </form>
                </div>
              </div>
            <?php endif ?>
          </article>
        </div>
      <?php endforeach ?>
    </div>
    <?php if ($pages > 1): ?>
      <nav class="pager" aria-label="Сторінки стрічки">
        <?php if ($page > 1): ?><a class="pg" href="<?= $href($page - 1) ?>" aria-label="Попередня сторінка"><i class="fal fa-chevron-left"></i></a><?php endif ?>
        <?php $last = 0; for ($p = 1; $p <= $pages; $p++): if ($p !== 1 && $p !== $pages && abs($p - $page) > 2) continue ?>
          <?php if ($last && $p - $last > 1): ?><span class="gap">…</span><?php endif ?>
          <a class="pg<?= $p === $page ? ' cur' : '' ?>" href="<?= $href($p) ?>"<?= $p === $page ? ' aria-current="page"' : '' ?>><?= $p ?></a>
          <?php $last = $p ?>
        <?php endfor ?>
        <?php if ($page < $pages): ?><a class="pg" href="<?= $href($page + 1) ?>" aria-label="Наступна сторінка"><i class="fal fa-chevron-right"></i></a><?php endif ?>
      </nav>
    <?php endif ?>
  <?php endif ?>
</div>
