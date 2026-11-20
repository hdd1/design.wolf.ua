<?php
/**
 * Форма «Скарга» (спека «Скарга»): POST /orders/{id}/complaint з обов'язковим текстом. Робочий простір клієнта після
 * першої купівлі («Куплено», «Доопрацювання»), посилання ?form=complaint#ws-complaint; без JS
 * @var int $orderId @var string $csrf @var string $text введене (після помилки)
 */
use Design\Complaints;
use Design\View;

$back = '/orders/' . $orderId . '/';
?>
<form id="ws-complaint" class="card-d sheet ws-complaint" method="post" action="/orders/<?= $orderId ?>/complaint" data-lock>
  <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
  <div class="sheet-head"><h2>Скарга на замовлення №<?= $orderId ?></h2><a class="icon-btn" href="<?= $back ?>" aria-label="Закрити"><i class="fal fa-times"></i></a></div>
  <p class="text-2 small mb-0">Опишіть, що пішло не так. Скаргу отримає менеджер Майстерні — він розбереться і зв'яжеться з вами. Дизайнер її не бачить.</p>
  <label class="f-label mt-3" for="ws-complaint-text">Що сталося</label>
  <textarea id="ws-complaint-text" class="form-control" name="text" rows="4" maxlength="<?= Complaints::TEXT_MAX ?>" required
    placeholder="Наприклад: у макеті помилка в номері телефону, а дизайнер не відповідає"><?= View::e($text) ?></textarea>
  <div class="sheet-actions">
    <a class="btn btn-outline-blue" href="<?= $back ?>">Закрити</a>
    <button type="submit" class="btn btn-primary"><i class="fal fa-paper-plane"></i> Надіслати скаргу</button>
  </div>
</form>
