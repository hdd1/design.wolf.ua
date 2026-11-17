<?php
/**
 * Каталог дизайнерів (макет 6.8)
 * @var string $q @var int $page @var int $pages @var int $total
 * @var list<array{customer_id:int,name:string,about:string,photo:string,works:list<int>}> $items
 */
use Design\Media;
use Design\View;

$href = static fn(int $p): string => View::e('/designers/' . ($q !== '' || $p > 1 ? '?' . http_build_query(array_filter(['q' => $q, 'page' => $p > 1 ? $p : null], static fn($v): bool => $v !== null && $v !== '')) : ''));
?>
<div class="container page">
  <div class="catalog-head">
    <div>
      <h1>Дизайнери Майстерні</h1>
      <p class="lead-d catalog-lead">Перевірені дизайнери, які готують макети під друк у wolf.ua</p>
    </div>
    <form class="search-box" method="get" action="/designers/" role="search">
      <i class="fal fa-search"></i>
      <input class="form-control" type="search" name="q" value="<?= View::e($q) ?>" maxlength="80" placeholder="Пошук за імʼям" aria-label="Пошук за імʼям" autocomplete="off">
    </form>
  </div>

  <?php if (!$items): ?>
    <div class="card-d empty-state">
      <span class="ic"><i class="fal fa-search"></i></span>
      <h2 class="h4 fw-bold" style="font-family:var(--font-ui)">Нікого не знайшли. Спробуйте інше імʼя</h2>
      <?php if ($q !== ''): ?><a class="btn btn-outline-blue mt-2" href="/designers/">Показати всіх дизайнерів</a><?php endif ?>
    </div>
  <?php else: ?>
    <div class="row g-4">
      <?php foreach ($items as $d): $url = '/designers/' . $d['customer_id'] . '/' ?>
        <div class="col-md-6 col-xl-4">
          <div class="designer-card card-d">
            <div class="who">
              <?php View::render('partials/designer-avatar', ['designerId' => $d['customer_id'], 'fullName' => $d['name'], 'photo' => $d['photo'], 'class' => 'avatar-md']) ?>
              <a class="name" href="<?= $url ?>"><?= View::e($d['name']) ?></a>
            </div>
            <?php if ($d['about'] !== ''): ?><p class="about"><?= View::e($d['about']) ?></p><?php else: ?><div class="about"></div><?php endif ?>
            <div class="thumbs">
              <?php for ($i = 0; $i < 3; $i++): ?>
                <?php if (isset($d['works'][$i])): ?>
                  <a class="thumb" href="<?= $url ?>" tabindex="-1" aria-hidden="true"><img src="<?= View::e(Media::url($d['customer_id'], $d['works'][$i] . '-card')) ?>" alt="" loading="lazy"></a>
                <?php else: ?><span class="thumb empty"></span><?php endif ?>
              <?php endfor ?>
            </div>
            <a class="btn btn-outline-blue w-100" href="<?= $url ?>">Переглянути</a>
          </div>
        </div>
      <?php endforeach ?>
    </div>
    <?php if ($pages > 1): ?>
      <nav class="pager" aria-label="Сторінки каталогу">
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
