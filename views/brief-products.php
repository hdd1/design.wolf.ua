<?php
/** @var list<array{id:int,name:string,description:string,image:string}> $products */
use Design\View;
?>
<div class="container page"><div class="narrow">
  <?php View::render('partials/steps', ['step' => 1]) ?>
  <h1>Що потрібно зробити?</h1>
  <p class="text-2 fs-5">Оберіть продукт — наступним кроком поставимо кілька простих питань.</p>
  <form data-products>
    <div class="row g-4 mt-1">
      <?php foreach ($products as $p): ?>
        <div class="col-md-6 col-lg-4">
          <label class="product-card <?= $p['image'] === '' ? 'other' : '' ?>">
            <input type="radio" name="product" value="<?= $p['id'] ?>" data-name="<?= View::e($p['name']) ?>">
            <span class="check"><i class="fas fa-check"></i></span>
            <?php if ($p['image'] !== ''): ?><div class="ph" style="background-image:url('/assets/img/products/<?= View::e($p['image']) ?>')"></div><?php else: ?><span class="plus"><i class="fal fa-plus"></i></span><?php endif ?>
            <div class="body"><div class="name"><?= View::e($p['name']) ?></div><div class="desc"><?= View::e($p['description']) ?></div></div>
          </label>
        </div>
      <?php endforeach ?>
    </div>
    <div class="actions"><span class="spacer"></span><span class="text-2 fw-semibold" data-chosen></span><button class="btn btn-primary btn-lg" type="submit" data-next disabled>Далі: питання</button></div>
  </form>
</div></div>
