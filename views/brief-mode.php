<?php
/** @var int $orderId @var string $csrf */
use Design\View;
?>
<div class="container page"><div class="narrow">
  <?php View::render('partials/steps', ['step' => 3]) ?>
  <h1>Як обрати дизайнера?</h1>
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
        <div class="mode-card disabled" aria-disabled="true" role="radio">
          <span class="radio"></span><span class="ic"><i class="fal fa-user-check"></i></span>
          <h3>Одразу обрати дизайнера <span class="soon-chip">Незабаром</span></h3>
          <p class="text-2">Знаєте, з ким хочете працювати? Оберіть зі списку — дизайнер отримає запрошення і назве ціну.</p>
          <div class="text-3 small fw-semibold">Зʼявиться, щойно в Майстерні будуть дизайнери</div>
        </div>
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
