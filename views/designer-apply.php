<?php
/**
 * Форма заявки: чернетка або відхилена (тоді зверху коментар менеджера)
 * @var array $d       рядок дизайнера (name/about/link — можуть бути щойно введені)
 * @var list<array> $works  Portfolio::all
 * @var string $csrf
 * @var ?string $error
 */
use Design\Designers;
use Design\DesignerStatus;
use Design\View;

$rejected = $d['status'] === DesignerStatus::REJECTED;
$ready = trim($d['about']) !== '' && count($works) >= Designers::APPLY_MIN;
?>
<div class="container page"><div class="apply">
  <div class="eyebrow">Для дизайнерів</div>
  <h1 class="mt-3">Заявка в дизайнери Майстерні</h1>
  <p class="lead-d">Розкажіть про себе і покажіть <?= Designers::APPLY_MIN ?>–<?= Designers::APPLY_MAX ?> робіт для друку. Менеджер друкарні перегляне заявку протягом 2 робочих днів.</p>

  <?php if ($rejected): ?>
    <div class="reject-note mb-4">
      <span class="badge-d red"><i class="fal fa-times-circle"></i> Заявка: відхилено</span>
      <div class="mt-2"><span class="fw-bold">Коментар менеджера:</span> «<?= View::e($d['reject_comment']) ?>»</div>
      <div class="mt-1">Виправте заявку і подайте знову.</div>
    </div>
  <?php endif ?>
  <?php if (!empty($error)): ?><div class="alert-d error mb-3" role="alert"><i class="fal fa-exclamation-circle mt-1"></i><div class="fw-semibold"><?= View::e($error) ?></div></div><?php endif ?>

  <form method="post" action="/designer" data-lock data-designer data-csrf="<?= View::e($csrf) ?>">
    <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
    <div class="card-d form-card">
      <div class="mb-4">
        <label class="f-label" for="d-name">Імʼя та прізвище</label>
        <input class="form-control" id="d-name" name="name" maxlength="<?= Designers::NAME_MAX ?>" value="<?= View::e($d['name']) ?>" autocomplete="name">
        <?php if (!$rejected): ?><div class="f-hint">З вашого акаунта wolf.ua</div><?php endif ?>
      </div>
      <div class="mb-4">
        <label class="q-label d-block" for="d-about">Про себе</label>
        <div class="q-note">Що робите найкраще, досвід у поліграфії. Це побачать клієнти у профілі</div>
        <textarea class="form-control" id="d-about" name="about" rows="4" maxlength="<?= Designers::ABOUT_MAX ?>" data-about><?= View::e($d['about']) ?></textarea>
      </div>
      <div class="mb-4">
        <label class="f-label" for="d-link">Behance, Instagram чи сайт <span class="fw-normal">(необовʼязково)</span></label>
        <input class="form-control" id="d-link" name="link" maxlength="255" value="<?= View::e($d['link']) ?>" placeholder="behance.net/ваше-імʼя" inputmode="url">
      </div>
      <?php View::render('partials/works-editor', ['mode' => 'apply', 'works' => $works, 'designerId' => $d['customer_id']]) ?>
    </div>
    <div class="actions">
      <span class="submit-hint" data-submit-hint<?= $ready ? ' hidden' : '' ?>>Щоб надіслати, розкажіть про себе і додайте щонайменше <?= Designers::APPLY_MIN ?> роботи</span>
      <span class="spacer"></span>
      <button class="btn btn-outline-blue btn-lg" type="submit" name="action" value="save">Зберегти чернетку</button>
      <button class="btn btn-primary btn-lg" type="submit" name="action" value="submit" data-submit<?= $ready ? '' : ' disabled' ?>><?= $rejected ? 'Оновити і подати знову' : 'Надіслати заявку' ?></button>
    </div>
  </form>
</div></div>
