<?php
/** @var string $title @var string $body @var string $active  'brief' | 'orders' | '' */
use Design\Auth;
use Design\Config;
use Design\Customers;
use Design\View;

$me = Auth::current();
$h = $me > 0 ? Customers::header($me) : null;
$active = $active ?? '';
$nav = [['brief', '/brief/', 'Новий бриф'], ['orders', '/orders/', 'Мої замовлення']];
?><!doctype html>
<html lang="uk">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= View::e($title) ?> — Майстерня дизайну</title>
<link rel="icon" href="/img/favicon.ico">
<link rel="stylesheet" href="/assets/vendor/bootstrap-5.3.3/css/bootstrap.min.css">
<link rel="stylesheet" href="/assets/vendor/fontawesome-pro-5/css/fontawesome-all.min.css">
<link rel="stylesheet" href="<?= View::asset('/assets/css/app.css') ?>">
</head>
<body>
<header class="site-header">
  <div class="container">
    <div class="bar">
      <a class="brand" href="/">
        <img src="/img/logo_wolf.png" alt="wolf.ua">
        <span class="brand-text"><span class="brand-title d-block">Майстерня дизайну</span><span class="brand-sub">друкарня wolf.ua</span></span>
      </a>
      <?php if ($h !== null): ?>
        <nav class="main-nav ms-3">
          <?php foreach ($nav as [$key, $href, $label]): ?>
            <a href="<?= $href ?>" class="<?= $active === $key ? 'active' : '' ?>"><?= $label ?></a>
          <?php endforeach ?>
          <span class="soon" title="Незабаром">Дизайнери</span>
        </nav>
        <div class="ms-auto d-flex align-items-center gap-3">
          <span class="balance"><i class="fal fa-wallet"></i><span class="label">Баланс</span> <?= View::price($h['balance']) ?></span>
          <span class="user"><span class="avatar"><?= View::e($h['initials']) ?></span><span class="short"><?= View::e($h['short']) ?></span></span>
        </div>
      <?php else: ?>
        <div class="ms-auto d-flex align-items-center gap-2 guest-actions">
          <a class="btn btn-outline-blue btn-sm" href="<?= View::e(Config::loginUrl('/')) ?>"><i class="fal fa-sign-in"></i> Увійти</a>
          <a class="btn btn-primary btn-sm" href="/brief/">Створити бриф</a>
        </div>
      <?php endif ?>
    </div>
    <?php if ($h !== null): ?>
      <nav class="mobile-nav">
        <?php foreach ($nav as [$key, $href, $label]): ?>
          <a href="<?= $href ?>" class="<?= $active === $key ? 'active' : '' ?>"><?= $label ?></a>
        <?php endforeach ?>
      </nav>
    <?php endif ?>
  </div>
</header>
<?php if (!empty($toast)): ?>
  <div class="toast-d" role="status" data-toast><i class="fal fa-check-circle"></i><span><?= View::e($toast) ?></span><button type="button" aria-label="Закрити" data-toast-close><i class="fal fa-times"></i></button></div>
<?php endif ?>
<main><?= $body ?></main>
<?php View::render('partials/footer') ?>
<script src="/assets/vendor/bootstrap-5.3.3/js/bootstrap.bundle.min.js" defer></script>
<script src="<?= View::asset('/assets/js/app.js') ?>" defer></script>
</body>
</html>
