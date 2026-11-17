<?php
/**
 * «Мій профіль» схваленого дизайнера: фото, текст профілю і портфоліо
 * @var array $d  рядок дизайнера (name/about/link — можуть бути щойно введені)
 * @var list<array> $works  Portfolio::all
 * @var string $csrf
 * @var ?string $error
 */
use Design\Designers;
use Design\Media;
use Design\View;

$words = preg_split('/\s+/u', trim($d['name']), -1, PREG_SPLIT_NO_EMPTY);
$initials = implode('', array_map(static fn(string $w): string => mb_strtoupper(mb_substr($w, 0, 1)), array_slice($words, 0, 2)));
$photo = $d['photo'] !== '' ? Media::url($d['customer_id'], basename($d['photo'], '.webp')) : '';
?>
<div class="container page" data-designer data-csrf="<?= View::e($csrf) ?>">
  <div class="profile-head">
    <h1>Мій профіль</h1>
    <a class="link-eye" href="/designers/<?= (int)$d['customer_id'] ?>/"><i class="fal fa-eye"></i> <span class="d-none d-sm-inline">Як бачать клієнти</span><span class="d-sm-none">Перегляд</span></a>
  </div>
  <div class="row g-4">
    <div class="col-lg-4">
      <form method="post" action="/designer" class="card-d form-card" data-lock>
        <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
        <div class="photo-box">
          <span class="avatar-xl" data-avatar>
            <?php if ($photo !== ''): ?><img src="<?= View::e($photo) ?>" alt=""><?php elseif ($initials !== ''): ?><?= View::e($initials) ?><?php else: ?><i class="fal fa-user"></i><?php endif ?>
          </span>
          <button type="button" class="btn btn-outline-blue" data-photo-pick>Змінити фото</button>
          <input type="file" accept="image/jpeg,image/png" hidden data-photo>
        </div>
        <div class="f-error" data-photo-error hidden></div>
        <?php if (!empty($error)): ?><div class="alert-d error mb-3" role="alert"><i class="fal fa-exclamation-circle mt-1"></i><div class="fw-semibold"><?= View::e($error) ?></div></div><?php endif ?>
        <div class="mb-3 mt-4">
          <label class="f-label" for="d-name">Імʼя та прізвище</label>
          <input class="form-control" id="d-name" name="name" maxlength="<?= Designers::NAME_MAX ?>" value="<?= View::e($d['name']) ?>" autocomplete="name">
        </div>
        <div class="mb-3">
          <label class="f-label" for="d-about">Про себе</label>
          <textarea class="form-control" id="d-about" name="about" rows="5" maxlength="<?= Designers::ABOUT_MAX ?>"><?= View::e($d['about']) ?></textarea>
        </div>
        <div class="mb-4">
          <label class="f-label" for="d-link">Behance, Instagram чи сайт</label>
          <input class="form-control" id="d-link" name="link" maxlength="255" value="<?= View::e($d['link']) ?>" placeholder="behance.net/ваше-імʼя" inputmode="url">
        </div>
        <button class="btn btn-primary btn-lg w-100" type="submit" name="action" value="save">Зберегти зміни</button>
      </form>
    </div>
    <div class="col-lg-8">
      <div class="card-d form-card">
        <?php View::render('partials/works-editor', ['mode' => 'profile', 'works' => $works, 'designerId' => $d['customer_id']]) ?>
      </div>
    </div>
  </div>
</div>
