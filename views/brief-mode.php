<?php
/** @var int $orderId @var string $csrf @var bool $canPick чи є кого обрати одразу */
use Design\View;
?>
<div class="container page"><div class="narrow">
  <?php View::render('partials/steps', ['step' => 3]) ?>
  <h1>Як обрати дизайнера?</h1>
  <?php if (!empty($error)): ?><div class="alert-d error mt-3" role="alert"><i class="fal fa-exclamation-circle mt-1"></i><div class="fw-semibold"><?= View::e($error) ?></div></div><?php endif ?>
  <form method="post" action="/orders/<?= $orderId ?>" data-lock class="mt-4">
    <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
    <div class="row g-4">
      <div class="col-md-6">
        <div class="mode-card selected" aria-checked="true" role="radio">
          <span class="radio"></span><span class="ic"><i class="fal fa-inbox"></i></span>
          <h3>Зібрати пропозиції</h3>
          <p class="text-2">Бриф побачать дизайнери Майстерні і запропонують ціну й термін. Ви порівняєте і оберете.</p>
          <div class="meta">Зазвичай 3–6 пропозицій протягом дня</div>
        </div>
      </div>
      <div class="col-md-6">
        <?php if ($canPick): ?>
          <a class="mode-card mode-link" href="/orders/<?= $orderId ?>/pick/">
            <span class="radio"></span><span class="ic"><i class="fal fa-user-check"></i></span>
            <h3>Одразу обрати дизайнера</h3>
            <p class="text-2">Знаєте, з ким хочете працювати? Оберіть зі списку — дизайнер отримає запрошення і назве ціну.</p>
            <div class="meta">Дизайнер відповідає зазвичай за кілька годин</div>
            <span class="go">Обрати зі списку <i class="fal fa-arrow-right"></i></span>
          </a>
        <?php else: ?>
          <div class="mode-card disabled" aria-disabled="true" role="radio">
            <span class="radio"></span><span class="ic"><i class="fal fa-user-check"></i></span>
            <h3>Одразу обрати дизайнера</h3>
            <p class="text-2">Знаєте, з ким хочете працювати? Оберіть зі списку — дизайнер отримає запрошення і назве ціну.</p>
            <div class="text-3 small fw-semibold">Зʼявиться, щойно в Майстерні будуть дизайнери</div>
          </div>
        <?php endif ?>
      </div>
    </div>
    <div class="actions">
      <a class="link-back" href="/orders/<?= $orderId ?>/"><i class="fal fa-arrow-left"></i> Назад</a>
      <span class="spacer"></span>
      <a class="btn btn-outline-blue btn-lg" href="/orders/"><i class="fal fa-save"></i> Зберегти чернетку</a>
      <button class="btn btn-primary btn-lg" type="submit" name="action" value="publish">Опублікувати бриф</button>
    </div>
  </form>
</div></div>
