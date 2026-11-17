<?php
/**
 * Стан заявки без форми: на розгляді, заблоковано, щойно схвалено
 * @var string $state  pending | blocked | approved
 * @var array $d
 */
use Design\View;

$s = [
    'pending' => ['blue', 'fa-hourglass-half', 'Заявка: на розгляді', 'Заявку на розгляді',
        'Надіслано ' . View::date($d['submitted_at']) . '. Менеджер друкарні перегляне ваші роботи протягом 2 робочих днів і надішле вам повідомлення з рішенням.'],
    'blocked' => ['red', 'fa-ban', 'Заявка: заблоковано', 'Профіль заблоковано',
        'Подати заявку чи змінити профіль зараз не можна. Якщо це помилка — напишіть нам: office@wolf.ua'],
    'approved' => ['green', 'fa-check-circle', 'Заявка: схвалено', 'Вітаємо, ви дизайнер Майстерні!',
        'Ваш профіль уже бачать клієнти. Додайте фото, розкажіть про себе і впорядкуйте роботи — перші 3 видно в каталозі.'],
][$state];
[$tone, $icon, $badge, $title, $text] = $s;
?>
<div class="container page"><div class="state-card card-d">
  <div class="state-ic <?= $tone ?>"><i class="fal <?= $icon ?>"></i></div>
  <span class="badge-d <?= $tone ?>"><i class="fal <?= $icon ?>"></i> <?= $badge ?></span>
  <h1 class="h2 mt-3"><?= View::e($title) ?></h1>
  <p class="text-2 mb-0"><?= View::e($text) ?></p>
  <?php if ($state === 'approved'): ?>
    <a class="btn btn-primary btn-lg w-100 mt-4" href="/designer/">Заповнити профіль</a>
  <?php endif ?>
</div></div>
