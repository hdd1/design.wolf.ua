<?php
/**
 * Вкладка «З балансу» вікна оплати (як «Оплата с баланса» wolf.ua, pages/fns/ordersPaymentBlock.php, українською, без чайових):
 * «На балансі», «Ваш кредит» (якщо > 0), «Сума», «Залишок після оплати» (червоним, якщо < 0), «Оплатити». Не можна (can = false) —
 * причина і вимкнена кнопка. Сума 0 (доопрацювання без доплати) — «Відкрити макети» без грошей.
 * Той самий вміст віддає GET /orders/{id}/buy і відповідь-відмова POST /orders/{id}/buy (html) — assets/js/workspace-buy.js
 * підставляє його у вікно
 * @var array{amount_client:int, balance:int, credit:int, after:int, can:bool, reason:string, new_layouts:int} $quote Purchases::quote
 */
use Design\View;

$p = $quote['amount_client'];
?>
<?php if ($p === 0): ?>
<div class="pay-free">
  <span class="ic"><i class="fal fa-lock-open-alt"></i></span>
  <div><div class="t">Доплата не потрібна</div>
  <div class="text-2 small">Нові макети відкриються одразу — з балансу нічого не спишеться.</div></div>
</div>
<?php else: ?>
<dl class="pay-sum">
  <div><dt>На балансі</dt> <dd><?= View::e(View::price($quote['balance'])) ?></dd></div>
  <?php if ($quote['credit'] > 0): ?>
  <div><dt>Ваш кредит</dt> <dd><?= View::e(View::price($quote['credit'])) ?></dd></div>
  <?php endif ?>
  <div class="pay-total"><dt>Сума</dt> <dd><?= View::e(View::price($p)) ?></dd></div>
  <div><dt>Залишок після оплати</dt> <dd<?= $quote['after'] < 0 ? ' class="text-danger"' : '' ?>><?= View::e(View::price($quote['after'])) ?></dd></div>
</dl>
<?php endif ?>
<?php if (!$quote['can']): ?>
<div class="alert-d error pay-reason" role="alert"><i class="fal fa-exclamation-circle"></i><div class="fw-semibold"><?= View::e($quote['reason']) ?></div></div>
<?php elseif ($p > 0): ?>
<p class="pay-note">Сума спишеться з балансу wolf.ua, макети відкриються одразу.</p>
<?php endif ?>
<button type="button" class="btn btn-primary btn-lg w-100" data-pay-submit data-amount="<?= $p ?>"<?= $quote['can'] ? '' : ' disabled' ?>>
  <?= $p === 0 ? '<i class="fal fa-lock-open-alt"></i> Відкрити макети' : '<i class="fal fa-wallet"></i> Оплатити' ?>
</button>
