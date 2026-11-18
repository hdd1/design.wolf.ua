<?php
/**
 * Профіль дизайнера для клієнта (макет 6.8): про себе, посилання, видимі роботи, збільшення роботи
 * @var array{customer_id:int,name:string,about:string,link:string,photo:string,works:list<array{id:int,title:string}>} $d
 */
use Design\Media;
use Design\View;

$works = $d['works'];
$n = count($works);
$linkOk = (bool)preg_match('#^https?://#i', $d['link']);
$linkText = preg_replace('#^https?://(www\.)?#i', '', rtrim($d['link'], '/'));
?>
<div class="container page" data-gallery data-owner="<?= View::e($d['name']) ?>">
  <div class="row g-4">
    <div class="col-lg-4">
      <div class="pub-card">
        <a class="back-link" href="/designers/"><i class="fal fa-arrow-left"></i> Усі дизайнери</a>
        <div class="pub-head">
          <?php View::render('partials/designer-avatar', ['designerId' => $d['customer_id'], 'fullName' => $d['name'], 'photo' => $d['photo'], 'class' => 'avatar-xl avatar-pub']) ?>
          <h1><?= View::e($d['name']) ?></h1>
        </div>
        <?php if ($d['about'] !== ''): ?><p class="pub-about"><?= View::e($d['about']) ?></p><?php endif ?>
        <?php if ($linkOk): ?>
          <div class="pub-link"><i class="fal fa-link"></i><a href="<?= View::e($d['link']) ?>" target="_blank" rel="noopener nofollow ugc"><?= View::e($linkText) ?></a></div>
        <?php endif ?>
      </div>
    </div>
    <div class="col-lg-8">
      <div class="works-bar">
        <h2 class="pub-title">Портфоліо<?php if ($n): ?> · <?= View::e(View::plural($n, 'робота', 'роботи', 'робіт')) ?><?php endif ?></h2>
        <?php if ($n): ?><span class="text-3 d-none d-sm-inline">Натисніть на роботу, щоб збільшити</span><?php endif ?>
      </div>
      <?php if (!$n): ?>
        <div class="works-empty">Дизайнер ще не додав робіт</div>
      <?php else: ?>
        <div class="pub-grid">
          <?php foreach ($works as $i => $w): ?>
            <figure class="pub-work">
              <button type="button" class="pub-tile" data-bs-toggle="modal" data-bs-target="#work-modal" data-index="<?= $i ?>"
                      data-view="<?= View::e(Media::url($d['customer_id'], $w['id'] . '-view')) ?>" data-title="<?= View::e($w['title']) ?>"
                      aria-label="Збільшити: <?= View::e($w['title'] !== '' ? $w['title'] : 'робота ' . ($i + 1)) ?>">
                <img src="<?= View::e(Media::url($d['customer_id'], $w['id'] . '-card')) ?>" alt="<?= View::e($w['title']) ?>" loading="lazy">
                <span class="zoom"><i class="fal fa-expand-alt"></i></span>
              </button>
              <?php if ($w['title'] !== ''): ?><figcaption><?= View::e($w['title']) ?></figcaption><?php endif ?>
            </figure>
          <?php endforeach ?>
        </div>
      <?php endif ?>
    </div>
  </div>
</div>
<?php if ($n): ?>
<div class="modal fade photo-modal" id="work-modal" tabindex="-1" aria-labelledby="work-modal-title" aria-hidden="true">
  <div class="modal-dialog modal-fullscreen">
    <div class="modal-content">
      <div class="pm-top">
        <div class="pm-text"><div class="pm-title" id="work-modal-title" data-pm-title></div><div class="pm-sub" data-pm-sub></div></div>
        <button type="button" class="pm-btn" data-bs-dismiss="modal" aria-label="Закрити"><i class="fal fa-times"></i></button>
      </div>
      <div class="pm-stage" data-pm-stage>
        <button type="button" class="pm-btn pm-prev" data-pm-prev aria-label="Попередня робота"<?= $n < 2 ? ' hidden' : '' ?>><i class="fal fa-chevron-left"></i></button>
        <img class="pm-img" alt="" data-pm-img>
        <button type="button" class="pm-btn pm-next" data-pm-next aria-label="Наступна робота"<?= $n < 2 ? ' hidden' : '' ?>><i class="fal fa-chevron-right"></i></button>
      </div>
    </div>
  </div>
</div>
<?php endif ?>
