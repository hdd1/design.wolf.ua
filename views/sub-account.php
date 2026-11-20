<?php
use Design\Config;
use Design\View;
?>
<div class="container page"><div class="card-d narrow p-4 p-md-5 text-center" style="max-width:640px">
  <div class="empty-state p-0"><span class="ic" style="background:var(--orange-soft);color:#9A5B00"><i class="fal fa-user-lock"></i></span></div>
  <h1 class="h2">Вибачте, з субакаунта замовити дизайн не можна — потрібен основний акаунт wolf.ua</h1>
  <p class="text-2">Увійдіть на wolf.ua під основним акаунтом вашої компанії, і тоді тут стануть доступні бриф, замовлення й оплата.</p>
  <a class="btn btn-primary btn-lg" href="<?= View::e(Config::loginUrl('/')) ?>"><i class="fal fa-external-link"></i> Перейти на wolf.ua</a>
</div></div>
