<?php
/**
 * Режим 2: як продовжити замовлення — «Обрати / Запросити іншого дизайнера» (сторінка вибору) і «Зібрати / Збирати пропозиції»
 * (POST action = collect). Після відмови дизайнера — головні дії (макет «дизайнер відмовився»). Після «Беру» з ціною
 * (рішення власника 2026-11-19) — другорядні, під карткою пропозиції: closing — імʼя дизайнера, чию пропозицію буде закрито
 * @var int $orderId @var string $csrf @var ?string $closing null — після відмови; інакше імʼя («» — без імені)
 */
use Design\View;

$secondary = $closing !== null;
$whose = $closing !== null && $closing !== '' ? $closing : 'дизайнера';
?>
<?php if ($secondary): ?><h2 class="offers-title mt-4">Або</h2><?php endif ?>
<div class="row g-4 mt-0">
  <div class="col-md-6">
    <div class="card-d next-card<?= $secondary ? ' secondary' : '' ?>">
      <i class="fal fa-users ic"></i>
      <?php if ($secondary): ?>
        <h2>Запросити іншого дизайнера</h2>
        <p class="text-2">Пропозицію <?= View::e($whose) ?> буде закрито — запрошення піде новому дизайнеру.</p>
        <a class="btn btn-outline-blue w-100 mt-auto" href="/orders/<?= $orderId ?>/pick/">Запросити іншого</a>
      <?php else: ?>
        <h2>Обрати іншого дизайнера</h2>
        <p class="text-2">Ваш бриф збережено — оберіть зі списку, і запрошення піде новому дизайнеру.</p>
        <a class="btn btn-outline-blue btn-lg w-100 mt-auto" href="/orders/<?= $orderId ?>/pick/">Обрати іншого</a>
      <?php endif ?>
    </div>
  </div>
  <div class="col-md-6">
    <form class="card-d next-card<?= $secondary ? ' secondary' : ' accent' ?>" method="post" action="/orders/<?= $orderId ?>" data-lock>
      <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
      <i class="fal fa-inbox ic"></i>
      <?php if ($secondary): ?>
        <h2>Збирати пропозиції</h2>
        <p class="text-2">Бриф побачать усі дизайнери Майстерні. Пропозиція <?= View::e($whose) ?> лишиться — її можна буде обрати.</p>
        <button class="btn btn-outline-blue w-100 mt-auto" type="submit" name="action" value="collect">Збирати пропозиції</button>
      <?php else: ?>
        <h2>Зібрати пропозиції</h2>
        <p class="text-2">Бриф побачать усі дизайнери Майстерні. Пропозиції — зазвичай протягом дня.</p>
        <button class="btn btn-primary btn-lg w-100 mt-auto" type="submit" name="action" value="collect">Зібрати пропозиції</button>
      <?php endif ?>
    </form>
  </div>
</div>
