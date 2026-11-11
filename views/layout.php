<?php
/** @var string $title @var string $body */
use Design\Auth;
use Design\Sql;
use Design\View;

$me = Auth::current();
$myName = $me > 0 ? (string)Sql::value("SELECT TRIM(CONCAT_WS(' ', first_name, last_name)) FROM customers WHERE id = ?", [$me]) : '';
?><!doctype html>
<html lang="uk">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= View::e($title) ?> — Майстерня дизайну</title>
<link rel="stylesheet" href="/assets/vendor/bootstrap-5.3.3/css/bootstrap.min.css">
<link rel="stylesheet" href="/assets/vendor/fontawesome-pro-5/css/fontawesome-all.min.css">
<link rel="stylesheet" href="<?= View::asset('/assets/css/app.css') ?>">
</head>
<body>
<header class="site-header">
  <nav class="navbar container">
    <a class="brand-title" href="/">Майстерня дизайну</a>
    <div class="d-flex align-items-center gap-3">
      <?php if ($me > 0): ?>
        <a href="/orders/">Мої замовлення</a>
        <span class="text-2"><i class="fal fa-user-circle"></i> <?= View::e($myName !== '' ? $myName : 'Клієнт') ?></span>
      <?php endif ?>
    </div>
  </nav>
</header>
<main><?= $body ?></main>
<script src="/assets/vendor/bootstrap-5.3.3/js/bootstrap.bundle.min.js" defer></script>
<?= $scripts ?? '' ?>
</body>
</html>
