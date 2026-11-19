<?php
/**
 * Перегляд прев'ю на весь екран (макети 6.5 «Перегляд прев'ю …»): одна версія з гортанням сторінок і стрічкою версій,
 * «Порівняння версій» — дві поруч (на телефоні — одна під одною). Порожня оболонка: наповнює assets/js/workspace.js
 * з data-previews листування
 * @var bool $hide     дизайнер може приховати прев'ю
 * @var bool $comment  можна коментувати (листування відкрите)
 */
?>
<div class="pv-viewer<?= $hide ? ' is-designer' : '' ?>" data-pv-viewer hidden role="dialog" aria-modal="true" aria-label="Перегляд прев'ю" tabindex="-1">
  <div class="pvv-single" data-pvv-single>
    <header class="pvv-head">
      <div class="pvv-title">
        <h2 data-pvv-title></h2>
        <p class="pvv-sub" data-pvv-sub></p>
      </div>
      <div class="pvv-tools">
        <button type="button" class="pvv-btn" data-pvv-to-compare title="Порівняти версії"><i class="fal fa-columns"></i><span>Порівняти</span></button>
        <?php if ($hide): ?>
        <button type="button" class="pvv-btn" data-pv-hide title="Приховати прев'ю"><i class="fal fa-eye-slash"></i><span>Приховати</span></button>
        <?php endif ?>
        <?php if ($comment): ?>
        <button type="button" class="pvv-btn pvv-comment<?= $hide ? '' : ' accent' ?>" data-pvv-comment><i class="fal fa-comment-alt"></i><span>Коментувати</span></button>
        <?php endif ?>
        <button type="button" class="pvv-btn round" data-pvv-close aria-label="Закрити"><i class="fal fa-times"></i></button>
      </div>
    </header>
    <div class="pvv-stage" data-pvv-stage>
      <button type="button" class="pvv-nav prev" data-pvv-prev aria-label="Назад"><i class="fal fa-chevron-left"></i></button>
      <img class="pvv-img" data-pvv-img alt="">
      <div class="pvv-gone" data-pvv-gone hidden><i class="fal fa-eye-slash"></i><span>Прев'ю приховано дизайнером</span></div>
      <button type="button" class="pvv-nav next" data-pvv-next aria-label="Далі"><i class="fal fa-chevron-right"></i></button>
      <span class="pvv-page" data-pvv-page hidden></span>
    </div>
    <p class="pvv-hint">← гортайте між версіями →</p>
    <p class="pvv-caption" data-pvv-caption></p>
    <div class="pvv-strip" data-pvv-strip role="listbox" aria-label="Версії"></div>
    <?php if ($comment): ?>
    <button type="button" class="btn btn-primary pvv-comment-m" data-pvv-comment><i class="fal fa-comment-alt"></i> Коментувати</button>
    <?php endif ?>
  </div>
  <div class="pvv-compare" data-pvv-compare hidden>
    <header class="pvv-head">
      <div class="pvv-title"><h2><span class="d-none d-md-inline">Порівняння версій</span><span class="d-md-none" data-pvv-pair></span></h2></div>
      <div class="pvv-tools">
        <button type="button" class="pvv-btn" data-pvv-to-single title="Одна версія"><i class="fal fa-square"></i><span>Одна версія</span></button>
        <button type="button" class="pvv-btn round" data-pvv-close aria-label="Закрити"><i class="fal fa-times"></i></button>
      </div>
    </header>
    <div class="pvv-panes">
      <?php foreach ([0, 1] as $side): ?>
      <section class="pvv-pane" data-pvv-pane="<?= $side ?>">
        <div class="pvv-pane-head">
          <label class="visually-hidden" for="pvv-sel-<?= $side ?>">Версія</label>
          <select class="pvv-select" id="pvv-sel-<?= $side ?>" data-pvv-select></select>
          <span class="pvv-pane-cap" data-pvv-pane-cap></span>
        </div>
        <div class="pvv-pane-img">
          <img alt="" data-pvv-pane-img>
          <span class="pvv-page" data-pvv-pane-page hidden></span>
        </div>
        <div class="pvv-pane-foot">
          <span class="pvv-pane-pages" data-pvv-pane-pages hidden>
            <button type="button" class="pvv-btn round sm" data-pvv-pane-prev aria-label="Попередня сторінка"><i class="fal fa-chevron-left"></i></button>
            <button type="button" class="pvv-btn round sm" data-pvv-pane-next aria-label="Наступна сторінка"><i class="fal fa-chevron-right"></i></button>
          </span>
          <?php if ($comment): ?>
          <button type="button" class="pvv-btn" data-pvv-pane-comment><i class="fal fa-comment-alt"></i><span data-pvv-pane-comment-text>Коментувати</span></button>
          <?php endif ?>
        </div>
      </section>
      <?php endforeach ?>
    </div>
  </div>
</div>
