<?php
/**
 * @var string $title @var string $body
 * @var string $active  'brief' | 'orders' | 'designers' | 'designer' | 'feed' | 'my-offers' | ''
 * @var list<string> $scripts  додаткові скрипти сторінки (шляхи від кореня сайту)
 */
use Design\Auth;
use Design\Config;
use Design\Customers;
use Design\Designers;
use Design\DesignerStatus;
use Design\Offers;
use Design\View;

$me = Auth::current();
$h = $me > 0 ? Customers::header($me) : null;
$active = $active ?? '';
$approved = $h !== null && Designers::status($me) === DesignerStatus::APPROVED;
// Схвалений дизайнер: меню дизайнера (кружечок — нові брифи в стрічці); «Мої замовлення» — його замовлення як клієнта
$nav = $approved
    ? [['feed', '/feed/', 'Стрічка брифів'], ['my-offers', '/my-offers/', 'Мої пропозиції'], ['orders', '/orders/', 'Мої замовлення'], ['designer', '/designer/', 'Мій профіль']]
    : [['brief', '/brief/', 'Новий бриф'], ['orders', '/orders/', 'Мої замовлення'], ['designers', '/designers/', 'Дизайнери']];
if ($h !== null && !$approved) $nav[] = ['designer', '/designer/', 'Стати дизайнером'];
$feedNew = $approved ? Offers::feedNewCount($me) : 0;
$badge = static fn(string $key): string => $key === 'feed' && $feedNew > 0
    ? ' <span class="nav-badge" title="Нові брифи">' . ($feedNew > 99 ? '99+' : $feedNew) . '</span>' : '';
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
            <a href="<?= $href ?>" class="<?= $active === $key ? 'active' : '' ?>"><?= $label ?><?= $badge($key) ?></a>
          <?php endforeach ?>
        </nav>
        <div class="ms-auto d-flex align-items-center gap-3">
          <span class="balance"><i class="fal fa-wallet"></i><span class="label">Баланс</span> <span data-balance><?= View::price($h['balance']) ?></span></span>
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
          <a href="<?= $href ?>" class="<?= $active === $key ? 'active' : '' ?>"><?= $label ?><?= $badge($key) ?></a>
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
<?php foreach ($scripts ?? [] as $src): ?>
<script src="<?= View::e(View::asset($src)) ?>" defer></script>
<?php endforeach ?>
</body>
</html>
