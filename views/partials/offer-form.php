<?php
/**
 * Форма пропозиції дизайнера (макет 6.7): «Що зробите», «Сума вам на руки» з живим «Клієнт побачить N грн», «Готово до».
 * Націнку k і крок округлення бачить assets/js/offer-form.js у data-*; без JS підказка порахована на сервері
 * @var int $orderId
 * @var string $action   propose | update | accept
 * @var string $heading  «Ваша пропозиція» @var string $context «Флаєр · №1045 · …» @var string $submit
 * @var array{text:string,price:string,deadline:string} $values  введене (рядки)
 * @var string $csrf @var float $k @var int $round
 * @var ?array{designer:int, client:int} $was  поточна пропозиція (зміна): та сама сума — та сама ціна для клієнта
 */
use Design\Offers;
use Design\Price;
use Design\View;

$digits = preg_replace('/[\s\x{00A0}\x{202F}]/u', '', $values['price']);
$d = preg_match('/^\d{1,7}$/', $digits) && (int)$digits >= 1 ? (int)$digits : 0;
if ($d === 0) {
    $hint = 'Вкажіть суму — покажемо, скільки побачить клієнт';
} else {
    $p = $was !== null && $d === $was['designer'] ? $was['client'] : Price::client($d, $k, $round);
    $hint = 'Клієнт побачить ' . View::price($p) . ($was !== null && $p !== $was['client'] ? ' (було ' . View::price($was['client']) . ')' : '');
}
$back = '/feed/' . $orderId . '/';
?>
<form class="card-d sheet offer-form" method="post" action="/feed/<?= $orderId ?>" data-lock data-offer-form
      data-k="<?= View::e((string)$k) ?>" data-round="<?= $round ?>"<?php if ($was !== null): ?> data-was-designer="<?= $was['designer'] ?>" data-was-client="<?= $was['client'] ?>"<?php endif ?>>
  <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
  <input type="hidden" name="action" value="<?= View::e($action) ?>">
  <div class="sheet-head"><h2><?= View::e($heading) ?></h2><a class="icon-btn" href="<?= $back ?>" aria-label="Закрити"><i class="fal fa-times"></i></a></div>
  <div class="of-ctx"><i class="fal fa-file-alt"></i><span><?= View::e($context) ?></span></div>

  <label class="f-label" for="of-text">Що зробите</label>
  <textarea id="of-text" class="form-control" name="text" rows="4" maxlength="<?= Offers::TEXT_MAX ?>" required
            placeholder="Що саме зробите, скільки варіантів і правок, що потрібно від клієнта"><?= View::e($values['text']) ?></textarea>

  <label class="f-label mt-3" for="of-price">Сума вам на руки</label>
  <div class="money-input">
    <input id="of-price" class="form-control" name="price" type="text" inputmode="numeric" autocomplete="off" required
           value="<?= View::e($values['price']) ?>" placeholder="1000" data-price>
    <span class="unit">грн</span>
  </div>
  <div class="of-client" aria-live="polite"><i class="fal fa-eye"></i> <span data-client-text><?= View::e($hint) ?></span></div>

  <label class="f-label mt-3" for="of-deadline">Готово до</label>
  <input id="of-deadline" class="form-control of-date" name="deadline" type="date" required min="<?= date('Y-m-d') ?>" value="<?= View::e($values['deadline']) ?>">

  <div class="sheet-actions">
    <a class="btn btn-outline-blue" href="<?= $back ?>">Скасувати</a>
    <button type="submit" class="btn btn-primary"><?= View::e($submit) ?></button>
  </div>
</form>
