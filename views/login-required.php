<?php
use Design\Config;
use Design\View;
?>
<div class="container page"><div class="card-d narrow p-4 p-md-5 text-center" style="max-width:640px">
  <div class="empty-state p-0"><span class="ic" style="background:var(--orange-soft);color:#9A5B00"><i class="fal fa-user-lock"></i></span></div>
  <h1 class="h2">Щоб створити бриф, увійдіть на wolf.ua</h1>
  <p class="text-2">Майстерня працює з вашим акаунтом wolf.ua: той самий баланс, а готові макети потрапляють у вашу хмару. Немає акаунта — зареєструєтесь за хвилину.</p>
  <a class="btn btn-primary btn-lg" href="<?= View::e(Config::loginUrl($_SERVER['REQUEST_URI'] ?? '/')) ?>"><i class="fal fa-sign-in"></i> Увійти на wolf.ua</a>
</div></div>
