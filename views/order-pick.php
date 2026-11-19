<?php
/**
 * Вибір дизайнера для режиму «Одразу обрати» (макет 6.2 «Бриф · крок 3 Режим · одразу обрати»): пошук, список з перемикачами,
 * «Опублікувати і запросити» (з чернетки) або «Запросити дизайнера» (після відмови або ціни дизайнера). Без рейтингу й кількості замовлень
 * @var int $orderId @var bool $draft @var string $q @var int $page @var int $pages
 * @var list<array{customer_id:int,name:string,about:string,photo:string,works:list<int>}> $items
 * @var int $picked обраний до помилки @var ?string $error @var string $csrf
 * @var ?string $closing «Пропозицію {імʼя} буде закрито» — запрошення після ціни дизайнера (попередження і підтвердження)
 */
use Design\Media;
use Design\View;

$base = "/orders/$orderId/pick/";
$href = static fn(int $p): string => View::e($base . ($q !== '' || $p > 1 ? '?' . http_build_query(array_filter(['q' => $q, 'page' => $p > 1 ? $p : null], static fn($v): bool => $v !== null && $v !== '')) : ''));
?>
<div class="container page"><div class="narrow">
  <?php if ($draft): ?>
    <?php View::render('partials/steps', ['step' => 3]) ?>
  <?php else: ?>
    <a class="link-back" href="/orders/<?= $orderId ?>/"><i class="fal fa-arrow-left"></i> До замовлення</a>
  <?php endif ?>
  <h1 class="<?= $draft ? '' : 'mt-3' ?>"><?= $draft ? 'Оберіть дизайнера' : 'Оберіть іншого дизайнера' ?></h1>
  <p class="text-2 mt-2">Дизайнер отримає запрошення і назве ціну й термін. Зазвичай відповідають за кілька годин.</p>
  <?php if ($closing !== null): ?><div class="alert-d info mt-3"><i class="fal fa-info-circle mt-1"></i><div class="fw-semibold"><?= View::e($closing) ?>, а дизайнер побачить, що обрано іншого.</div></div><?php endif ?>
  <?php if ($error !== null): ?><div class="alert-d error mt-3" role="alert"><i class="fal fa-exclamation-circle mt-1"></i><div class="fw-semibold"><?= View::e($error) ?></div></div><?php endif ?>

  <div class="card-d pick-card mt-4">
    <form class="search-box" method="get" action="<?= $base ?>" role="search">
      <i class="fal fa-search"></i>
      <input class="form-control" type="search" name="q" value="<?= View::e($q) ?>" maxlength="80" placeholder="Пошук за імʼям" aria-label="Пошук за імʼям" autocomplete="off">
    </form>

    <?php if (!$items): ?>
      <div class="pick-empty">
        <?php if ($q !== ''): ?>
          <p class="mb-2">Нікого не знайшли. Спробуйте інше імʼя</p>
          <a class="btn btn-outline-blue" href="<?= $base ?>">Показати всіх</a>
        <?php else: ?>
          <p class="mb-2">У Майстерні поки немає дизайнерів, яких можна обрати.</p>
          <a class="btn btn-outline-blue" href="/orders/<?= $orderId ?>/<?= $draft ? 'mode/' : '' ?>"><?= $draft ? 'Зібрати пропозиції' : 'До замовлення' ?></a>
        <?php endif ?>
      </div>
    <?php endif ?>

    <form id="pick-form" method="post" action="/orders/<?= $orderId ?>" data-lock<?= $closing !== null ? ' data-confirm="' . View::e($closing . '. Запросити іншого дизайнера?') . '"' : '' ?>>
      <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
      <input type="hidden" name="action" value="invite">
      <?php if ($items): ?>
        <div class="pick-list" role="radiogroup" aria-label="Дизайнери">
          <?php foreach ($items as $d): $thumb = isset($d['works'][0]) ? Media::url($d['customer_id'], $d['works'][0] . '-card') : '' ?>
            <label class="pick-row">
              <?php View::render('partials/designer-avatar', ['designerId' => $d['customer_id'], 'fullName' => $d['name'], 'photo' => $d['photo'], 'class' => 'avatar-md']) ?>
              <span class="min-w-0 flex-grow-1">
                <span class="oc-name d-block"><?= View::e($d['name']) ?></span>
                <a class="pick-link" href="/designers/<?= $d['customer_id'] ?>/" target="_blank" rel="noopener">Портфоліо</a>
              </span>
              <?php if ($thumb !== ''): ?><span class="pick-thumb"><img src="<?= View::e($thumb) ?>" alt="" loading="lazy"></span><?php endif ?>
              <input type="radio" name="designer_id" value="<?= $d['customer_id'] ?>" required<?= $d['customer_id'] === $picked ? ' checked' : '' ?>>
              <span class="radio" aria-hidden="true"></span>
            </label>
          <?php endforeach ?>
        </div>
        <?php if ($pages > 1): ?>
          <nav class="pager" aria-label="Сторінки дизайнерів">
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
    </form>
  </div>

  <div class="actions">
    <a class="link-back" href="/orders/<?= $orderId ?>/<?= $draft ? 'mode/' : '' ?>"><i class="fal fa-arrow-left"></i> Назад</a>
    <span class="spacer"></span>
    <?php if ($draft): ?><a class="btn btn-outline-blue btn-lg" href="/orders/"><i class="fal fa-save"></i> Зберегти чернетку</a><?php endif ?>
    <?php if ($items): ?>
      <button class="btn btn-primary btn-lg" type="submit" form="pick-form"><?= $draft ? 'Опублікувати і запросити' : 'Запросити дизайнера' ?></button>
    <?php endif ?>
  </div>
</div></div>
