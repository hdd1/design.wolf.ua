<?php
/**
 * Роботи портфоліо: у заявці — плитки (до 10), у профілі — список з перетягуванням, назвою і прихованням.
 * Завантаження, видалення й решту дій робить assets/js/designer.js
 * @var string $mode  apply | profile
 * @var list<array{id:int,title:string,shw:bool}> $works
 * @var int $designerId
 */
use Design\Designers;
use Design\Images;
use Design\Media;
use Design\Portfolio;
use Design\View;

$apply = $mode === 'apply';
$max = $apply ? Designers::APPLY_MAX : Portfolio::MAX;
$hidden = count(array_filter($works, static fn(array $w): bool => !$w['shw']));
$plural = static function (int $n, string $one, string $few, string $many): string {
    $d = $n % 10;
    $h = $n % 100;
    return $n . ' ' . ($d === 1 && $h !== 11 ? $one : ($d >= 2 && $d <= 4 && ($h < 12 || $h > 14) ? $few : $many));
};
$picker = '<input type="file" accept="image/jpeg,image/png" multiple hidden data-file>';
?>
<div class="works <?= $apply ? 'works-apply' : 'works-profile' ?>" data-works data-mode="<?= $mode ?>" data-max="<?= $max ?>"
     data-min="<?= Designers::APPLY_MIN ?>" data-max-bytes="<?= Images::MAX_BYTES ?>" data-title-max="<?= Portfolio::TITLE_MAX ?>">
  <?php if ($apply): ?>
    <div class="q-label">Роботи для портфоліо · <span data-count><?= count($works) ?></span> з <?= $max ?></div>
    <div class="q-note">JPG або PNG до <?= Images::MAX_BYTES / 1048576 ?> МБ. Найкраще — фото надрукованих робіт</div>
    <div class="works-grid" data-list>
      <?php foreach ($works as $w): ?>
        <div class="work-tile" data-work data-id="<?= $w['id'] ?>">
          <img src="<?= View::e(Media::url($designerId, $w['id'] . '-card')) ?>" alt="" loading="lazy">
          <button type="button" class="tile-x" data-delete aria-label="Видалити роботу"><i class="fal fa-times"></i></button>
        </div>
      <?php endforeach ?>
      <button type="button" class="work-add" data-pick<?= count($works) >= $max ? ' hidden' : '' ?>><i class="fal fa-file-image"></i><span>Додати</span></button>
    </div>
    <div class="alert-d error mt-3" role="alert" data-works-error hidden></div>
    <?= $picker ?>
  <?php else: ?>
    <div class="works-head">
      <div>
        <h2 class="works-title">Портфоліо · <span data-summary><?= $plural(count($works), 'робота', 'роботи', 'робіт') ?><?= $hidden ? ', ' . $plural($hidden, 'прихована', 'приховані', 'прихованих') : '' ?></span></h2>
        <div class="q-note mb-0">Перетягніть за <i class="fas fa-grip-vertical"></i>, щоб змінити порядок. Перші 3 роботи видно в каталозі</div>
      </div>
      <button type="button" class="btn btn-outline-blue d-none d-md-inline-flex" data-pick><i class="fal fa-file-image"></i> Додати роботу</button>
    </div>
    <div class="alert-d error mt-3" role="alert" data-works-error hidden></div>
    <div class="work-list" data-list>
      <?php foreach ($works as $i => $w): ?>
        <div class="work-row<?= $w['shw'] ? '' : ' is-hidden' ?>" data-work data-id="<?= $w['id'] ?>">
          <span class="drag" data-handle title="Перетягніть, щоб змінити порядок"><i class="fas fa-grip-vertical"></i></span>
          <span class="n" data-n><?= $i + 1 ?></span>
          <a class="ph" href="<?= View::e(Media::url($designerId, $w['id'] . '-view')) ?>" target="_blank" rel="noopener"><img src="<?= View::e(Media::url($designerId, $w['id'] . '-card')) ?>" alt="" loading="lazy"></a>
          <div class="info">
            <textarea class="title-input" data-title rows="1" maxlength="<?= Portfolio::TITLE_MAX ?>" placeholder="Додайте назву роботи" aria-label="Назва роботи"><?= View::e($w['title']) ?></textarea>
            <div class="sub" data-sub><?= $w['shw'] ? '' : 'Приховано — клієнти не бачать' ?></div>
          </div>
          <button type="button" class="icon-btn" data-shw aria-pressed="<?= $w['shw'] ? 'false' : 'true' ?>" title="<?= $w['shw'] ? 'Приховати від клієнтів' : 'Показати клієнтам' ?>"><i class="fal <?= $w['shw'] ? 'fa-eye' : 'fa-eye-slash' ?>"></i></button>
          <button type="button" class="icon-btn del" data-delete title="Видалити роботу"><i class="fal fa-trash-alt"></i></button>
        </div>
      <?php endforeach ?>
    </div>
    <div class="works-empty" data-empty<?= $works ? ' hidden' : '' ?>>Поки жодної роботи. Додайте найкращі — перші 3 видно в каталозі</div>
    <button type="button" class="btn btn-outline-blue btn-lg w-100 mt-3 d-md-none" data-pick><i class="fal fa-file-image"></i> Додати роботу</button>
    <?= $picker ?>
  <?php endif ?>
  <div class="work-errors" data-errors></div>
</div>
