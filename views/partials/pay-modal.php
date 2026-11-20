<?php
/**
 * Вікно «Оплата: звідки?» для «Купую» (етап 5, спека «Оплата з балансу»): вкладки «З балансу» (активна, вміст —
 * partials/pay-balance), «Visa/Mastercard» і «Виставити рахунок» — неактивні, «Незабаром». На телефоні — аркуш знизу.
 * Оживляє assets/js/workspace-buy.js: «Купую» відкриває вікно й перечитує вміст (GET /orders/{id}/buy), «Оплатити» —
 * POST /orders/{id}/buy
 * @var int $orderId
 * @var array $quote Purchases::quote
 * @var string $csrf
 */
use Design\View;

$id = (int)$orderId;
?>
<div class="modal fade choose-modal pay-modal" id="pay-<?= $id ?>" tabindex="-1" aria-labelledby="pay-<?= $id ?>-t" aria-hidden="true"
  data-pay data-order="<?= $id ?>" data-csrf="<?= View::e($csrf) ?>">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <span class="cm-grip" aria-hidden="true"></span>
      <div class="cm-head">
        <h2 id="pay-<?= $id ?>-t">Оплата: звідки?</h2>
        <button type="button" class="icon-btn" data-bs-dismiss="modal" aria-label="Закрити"><i class="fal fa-times"></i></button>
      </div>
      <div class="pay-tabs" role="tablist" aria-label="Спосіб оплати">
        <button type="button" role="tab" class="pay-tab active" aria-selected="true" aria-controls="pay-<?= $id ?>-balance"><i class="fal fa-wallet"></i><span>З балансу</span></button>
        <button type="button" role="tab" class="pay-tab" aria-selected="false" disabled><i class="fal fa-credit-card"></i><span>Visa/Mastercard</span> <span class="pay-soon">Незабаром</span></button>
        <button type="button" role="tab" class="pay-tab" aria-selected="false" disabled><i class="fal fa-file-invoice"></i><span>Виставити рахунок</span> <span class="pay-soon">Незабаром</span></button>
      </div>
      <div class="pay-body" id="pay-<?= $id ?>-balance" role="tabpanel" aria-live="polite" data-pay-body>
        <?php View::render('partials/pay-balance', ['quote' => $quote]) ?>
      </div>
      <p class="chat-error pay-error" data-pay-error role="alert" hidden></p>
    </div>
  </div>
</div>
