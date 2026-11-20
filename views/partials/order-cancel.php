<?php
/**
 * Форма «Скасувати замовлення» (спека «Скасувати замовлення»): POST /orders/{id}/cancel з обов'язковою причиною.
 * Відкривається посиланням ?form=cancel#order-cancel — на сторінці замовлення (views/order.php) і в робочому просторі
 * (views/partials/workspace.php, «В роботі»); без JS
 * @var int $orderId @var string $csrf @var string $reason введене (після помилки)
 */
use Design\Orders;
use Design\View;

$back = '/orders/' . $orderId . '/';
?>
<form id="order-cancel" class="card-d sheet order-cancel" method="post" action="/orders/<?= $orderId ?>/cancel" data-lock>
  <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
  <div class="sheet-head"><h2>Скасувати замовлення №<?= $orderId ?>?</h2><a class="icon-btn" href="<?= $back ?>" aria-label="Закрити"><i class="fal fa-times"></i></a></div>
  <p class="text-2 small mb-0">Дизайнери отримають сповіщення. Прев'ю і макети буде видалено, бриф і листування лишаться — лише для читання. Відновити скасоване замовлення не можна.</p>
  <label class="f-label mt-3" for="order-cancel-reason">Причина <span class="text-3 fw-normal">— її побачить менеджер Майстерні</span></label>
  <textarea id="order-cancel-reason" class="form-control" name="reason" rows="3" maxlength="<?= Orders::REASON_MAX ?>" required
    placeholder="Наприклад: уже знайшли макет, дизайнер не відповідає, змінились плани"><?= View::e($reason) ?></textarea>
  <div class="sheet-actions">
    <a class="btn btn-outline-blue" href="<?= $back ?>">Не скасовувати</a>
    <button type="submit" class="btn btn-red"><i class="fal fa-ban"></i> Скасувати замовлення</button>
  </div>
</form>
