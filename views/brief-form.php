<?php
/**
 * @var string $productName
 * @var ?int $productId     новий бриф — id продукту; чернетка — null
 * @var string $postTo      /brief або /orders/{id}
 * @var string $title
 * @var list<array{q:string,note:string,hint:string,a:string}> $fields
 * @var string $csrf
 * @var ?string $error
 * @var ?string $savedAt    «14:20», якщо щойно збережено
 */
use Design\View;

$about = ['Візитка' => 'візитку', 'Флаєр' => 'флаєр', 'Буклет' => 'буклет', 'Брошура на скобі' => 'брошуру', 'Логотип' => 'логотип', 'Інше' => 'задачу'];
?>
<div class="container page"><div class="narrow">
  <?php View::render('partials/steps', ['step' => 2]) ?>
  <div class="fw-bold" style="color:#9A6B00"><?= View::e($productName) ?><?php if ($productId !== null): ?> <a href="/brief/" class="ms-1 fw-semibold">змінити</a><?php endif ?></div>
  <div class="d-flex flex-wrap justify-content-between align-items-end gap-3">
    <div>
      <h1 class="mt-2">Розкажіть про <?= View::e($about[$productName] ?? mb_strtolower($productName)) ?></h1>
      <p class="text-2 fs-5 mb-0">Пишіть як є, своїми словами. Достатньо однієї відповіді — решту дизайнер уточнить у чаті.</p>
    </div>
    <?php if (!empty($savedAt)): ?><span class="saved-chip"><i class="fal fa-cloud-upload"></i> Чернетку збережено о <?= View::e($savedAt) ?></span><?php endif ?>
  </div>

  <form method="post" action="<?= View::e($postTo) ?>" data-lock class="mt-4">
    <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
    <?php if ($productId !== null): ?><input type="hidden" name="product_id" value="<?= (int)$productId ?>"><?php endif ?>
    <div class="row g-4">
      <div class="col-lg-8">
        <?php if (!empty($error)): ?><div class="alert-d error mb-3" role="alert"><i class="fal fa-exclamation-circle mt-1"></i><div class="fw-semibold"><?= View::e($error) ?></div></div><?php endif ?>
        <div class="card-d p-4">
          <div class="mb-4">
            <label class="q-label d-block" for="f-title">Назва замовлення <span class="text-3 fw-normal small">— необовʼязково</span></label>
            <div class="q-note">Так замовлення буде підписане у «Мої замовлення»</div>
            <input class="form-control" id="f-title" name="title" maxlength="255" value="<?= View::e($title) ?>" placeholder="Наприклад: Візитка для кавʼярні «Зерно»">
          </div>
          <?php foreach ($fields as $i => $f): ?>
            <div class="mb-4">
              <label class="q-label d-block" for="f-<?= $i ?>"><?= View::e($f['q']) ?></label>
              <?php if ($f['note'] !== ''): ?><div class="q-note"><?= View::e($f['note']) ?></div><?php endif ?>
              <textarea class="form-control" id="f-<?= $i ?>" name="answers[]" rows="3" placeholder="<?= View::e($f['hint'] === '' || str_starts_with($f['hint'], 'Наприклад') ? $f['hint'] : 'Наприклад: ' . $f['hint']) ?>"><?= View::e($f['a']) ?></textarea>
            </div>
          <?php endforeach ?>
        </div>
      </div>
      <div class="col-lg-4">
        <div class="tips">
          <div class="fw-bold mb-3">Як написати бриф</div>
          <ul class="list-unstyled mb-0">
            <li><i class="fal fa-comment"></i>Своїми словами. Терміни на кшталт «вильоти» чи «CMYK» — турбота дизайнера</li>
            <li><i class="fal fa-link"></i>Логотип, фото вивіски чи приклади — посиланням у полі «Матеріали»</li>
            <li><i class="fal fa-info-circle"></i>Не знаєте відповіді — пропустіть. Дизайнер спитає в чаті</li>
            <li><i class="fal fa-eye"></i>Бриф побачать лише дизайнери Майстерні</li>
          </ul>
        </div>
      </div>
    </div>
    <div class="actions">
      <a class="link-back" href="<?= $productId !== null ? '/brief/' : '/orders/' ?>"><i class="fal fa-arrow-left"></i> Назад</a>
      <span class="spacer"></span>
      <button class="btn btn-outline-blue btn-lg" type="submit" name="action" value="draft"><i class="fal fa-save"></i> Зберегти чернетку</button>
      <button class="btn btn-primary btn-lg" type="submit" name="action" value="next">Далі: як обрати дизайнера</button>
    </div>
  </form>
</div></div>
