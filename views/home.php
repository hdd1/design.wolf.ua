<?php
/** @var list<array{id:int,name:string,description:string,image:string}> $products */
use Design\View;
?>
<section class="hero">
  <div class="container"><div class="row align-items-center g-4 g-lg-5">
    <div class="col-lg-6">
      <div class="eyebrow mb-3">Майстерня дизайну друкарні wolf.ua</div>
      <h1>Дизайн для друку — від брифу до готового макета</h1>
      <p class="lead mt-3">Опишіть, що потрібно: візитку, флаєр, логотип. Дизайнери назвуть ціну й термін, ви оберете одного і доведете макет разом у чаті. Готові файли — одразу у вашій хмарі wolf.ua.</p>
      <div class="d-flex flex-wrap align-items-center gap-3 mt-4">
        <a class="btn btn-primary btn-lg" href="/brief/">Створити бриф</a>
        <a class="fw-semibold" href="#how"><i class="fal fa-arrow-down"></i> Як це працює</a>
      </div>
      <ul class="perks list-unstyled mt-4 mb-0">
        <li><i class="fal fa-check-circle"></i> Ціна відома до старту</li>
        <li><i class="fal fa-wallet"></i> Оплата з балансу wolf.ua</li>
        <li><i class="fal fa-print"></i> Друк у тій самій друкарні</li>
      </ul>
    </div>
    <div class="col-lg-6"><div class="hero-img">
      <img src="/assets/img/hero.webp" alt="Візитки і зразки кольорів на столі дизайнера" width="1200" height="655">
      <div class="offer-ex">
        <div class="text-3 small">Приклад пропозиції на візитку</div>
        <div class="d-flex justify-content-between align-items-baseline gap-3 mt-1"><span class="fs-4 fw-bold">1 200 грн</span><span class="text-2 small">до 20 листопада</span></div>
      </div>
    </div></div>
  </div></div>
</section>

<section class="section how" id="how">
  <div class="container">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-4"><h2 class="mb-0">Як це працює</h2><span class="text-2">Від брифу до макета — зазвичай 2–5 днів</span></div>
    <div class="row g-4">
      <?php foreach ([
          ['fa-edit', 'Опишіть задачу', 'Оберіть продукт і дайте відповіді на кілька питань. Без термінів — пишіть своїми словами.'],
          ['fa-inbox', 'Отримайте пропозиції', 'Дизайнери бачать бриф і пропонують ціну, термін і підхід. Порівняйте і оберіть одного.'],
          ['fa-comment-alt-lines', 'Доведіть дизайн разом', "Дизайнер показує прев'ю версіями — v1, v2, v3. Коментуйте прямо під картинкою."],
          ['fa-print', 'Купіть макет і друкуйте', 'Натисніть «Купую» — файли одразу у вашій хмарі wolf.ua, звідти можна замовити друк.'],
      ] as $i => [$icon, $h, $t]): ?>
        <div class="col-sm-6 col-lg-3 col-4th">
          <div class="d-flex justify-content-between"><span class="step-n">0<?= $i + 1 ?></span><i class="fal <?= $icon ?> text-2 mt-2"></i></div>
          <div class="fw-bold mt-2"><?= $h ?></div><p class="text-2 small mt-1"><?= $t ?></p>
        </div>
      <?php endforeach ?>
    </div>
  </div>
</section>

<section class="section" style="background:#EFEFEF">
  <div class="container">
    <h2>Що можна замовити</h2>
    <p class="text-2">Макети, готові до друку у wolf.ua: правильний розмір, вильоти і кольори — дизайнер подбає сам.</p>
    <div class="row g-3 mt-2">
      <?php foreach ($products as $p): ?>
        <div class="col-6 col-md-4 col-lg-2">
          <a class="product-card <?= $p['image'] === '' ? 'other' : '' ?>" href="/brief/<?= $p['id'] ?>/">
            <?php if ($p['image'] !== ''): ?><div class="ph" style="background-image:url('/assets/img/products/<?= View::e($p['image']) ?>')"></div><?php else: ?><span class="plus"><i class="fal fa-plus"></i></span><?php endif ?>
            <div class="body p-3"><div class="name fs-6"><?= View::e($p['name']) ?></div><div class="desc small"><?= View::e($p['description']) ?></div></div>
          </a>
        </div>
      <?php endforeach ?>
    </div>
  </div>
</section>

<section class="why">
  <div class="container"><div class="row g-4 g-lg-5">
    <div class="col-lg-5"><h2 class="text-white">Чому замовляти дизайн тут</h2><p style="color:#D5D9DB">Майстерня — частина wolf.ua. Той самий вхід, той самий баланс, та сама друкарня.</p></div>
    <div class="col-lg-7">
      <?php foreach ([
          ['fa-wallet', 'Оплата з балансу wolf.ua', 'Або карткою, або рахунком для ФОП і ТОВ — як ви звикли.'],
          ['fa-cloud', 'Макет одразу у вашій хмарі', "Після покупки файли з'являються у хмарі wolf.ua — не загубляться в пошті."],
          ['fa-print', 'Друк у тій самій друкарні', 'Дизайнер готує макет під наше обладнання, тож друк піде без переробок.'],
          ['fa-tag', 'Ціна відома заздалегідь', 'Ціну й термін видно окремим блоком до старту. Кожна зміна — тільки за вашою згодою.'],
      ] as [$icon, $h, $t]): ?>
        <div class="item"><i class="fal <?= $icon ?>"></i><div><div class="fw-bold"><?= $h ?></div><p><?= $t ?></p></div></div>
      <?php endforeach ?>
    </div>
  </div></div>
</section>

<section class="section">
  <div class="container"><div class="join d-flex flex-wrap align-items-center justify-content-between gap-3">
    <div><div class="eyebrow">Для дизайнерів</div><h2 class="mt-1">Станьте дизайнером Майстерні</h2><p class="text-2 mb-0">Брифи від малого бізнесу щодня. Ви самі називаєте ціну «на руки», а гроші за купівлю надходять на ваш рахунок wolf.ua.</p></div>
    <span class="btn btn-outline-blue disabled">Подати заявку <span class="soon-chip">Незабаром</span></span>
  </div></div>
</section>
