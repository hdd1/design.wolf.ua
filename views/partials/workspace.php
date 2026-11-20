<?php
/**
 * Робочий простір замовлення «В роботі», «Куплено», «Доопрацювання» (макети 6.5): шапка замовлення (ціна й термін окремим блоком,
 * головна кнопка), «чий хід», бриф, широка стрічка листування і панель «Макети для друку» праворуч; на телефоні — вкладки
 * «Чат / Макети». Однаковий для обох сторін: views/order.php (клієнт) і views/feed-order.php (дизайнер).
 * Клієнт: «Ціна · Термін», «Купую» (поки замовлення можна купити; вимкнена, поки немає нових макетів) з вікном
 * «Оплата: звідки?» (partials/pay-modal, assets/js/workspace-buy.js). Дизайнер: «Ви отримаєте D грн · клієнт побачить P грн»,
 * «Термін до …» (без «Змінити»: ціна й термін після вибору не змінюються), «Завантажити макети» (веде до панелі)
 * @var string $role         client | designer
 * @var array $order         Orders::get / Orders::find (id, title, product_name, status, answers)
 * @var array $offer         обрана пропозиція (Offers::get): price_designer, price_client, deadline
 * @var array{href:string, label:string} $back  куди веде перша ланка «хлібних крихт»
 * @var string $who          «Клієнт Олена М.» / «Дизайнер Петро Коваль»
 * @var string $whoName      ім'я для ініціалів
 * @var array{mine:bool, text:string, sub:string, icon:string} $turn  Workspace::turn
 * @var array $chat          параметри partials/chat (з workspace)
 * @var array $panel         Workspace::layouts
 * @var bool $edit           дизайнер може завантажувати й змінювати макети
 * @var string $csrf
 * @var int $chunk
 * @var ?string $error       помилка дії з форми сторінки (POST, що повернув сторінку з 422), null — немає
 * @var ?array $quote        клієнт: Purchases::quote — замовлення можна купити («Купую» і вікно оплати); null — ні (і в дизайнера)
 * @var ?array $rework       Workspace::rework (+ form — відкрита форма «Ще не закінчили», values — введене {price, deadline});
 *                           null — без доопрацювання. Дизайнер: «Ще не закінчили» → форма (нова повна сума на руки й дата, жива
 *                           підказка доплати — assets/js/workspace.js). Клієнт: «Прийняти» / «Відмовитися» на пропозицію,
 *                           «Скасувати доопрацювання» під «Купую». Усе — звичайні форми POST /orders/{id}/rework
 */
use Design\Chat;
use Design\OrderStatus;
use Design\Price;
use Design\View;

$id = $order['id'];
$designer = $role === 'designer';
$filled = count(array_filter($order['answers'], static fn($p) => $p['a'] !== ''));
$error ??= null;
$quote = $designer ? null : ($quote ?? null);
$rework ??= null;
$propose = $designer && !$edit && ($rework['propose'] ?? false);
$answer = !$designer && ($rework['answer'] ?? false);
$cancel = !$designer && $quote !== null && ($rework['cancel'] ?? false);
$cta = $designer ? $edit || $propose : $quote !== null || $answer;
$newLayouts = $quote['new_layouts'] ?? 0;
$deadline = $offer['deadline'] ? 'до ' . View::date($offer['deadline']) : '—';
$reworkUrl = '/orders/' . $id . '/rework';
$hidden = '<input type="hidden" name="csrf" value="' . View::e($csrf) . '">';
// Форма «Ще не закінчили»: підказка доплати для клієнта (без JS — порахована тут; жива — workspace.js, та сама формула)
$reworkForm = $propose && ($rework['form'] ?? false);
if ($reworkForm) {
    $rv = $rework['values'] ?? ['price' => (string)$rework['paid_designer'], 'deadline' => ''];
    $digits = preg_replace('/[\s\x{00A0}\x{202F}]/u', '', $rv['price']);
    $rd = preg_match('/^\d{1,7}$/', $digits) ? (int)$digits : 0;
    if ($rd < 1) {
        $reworkHint = 'Вкажіть суму — покажемо доплату клієнта';
    } elseif ($rd < $rework['paid_designer']) {
        $reworkHint = 'Не менше вже нарахованих ' . View::price($rework['paid_designer']);
    } else {
        $extra = max(Price::client($rd, $rework['k'], $rework['round']), $rework['paid_client']) - $rework['paid_client'];
        $reworkHint = $extra > 0 ? 'Клієнт доплатить +' . View::price($extra) : 'Для клієнта — без доплати';
    }
}
?>
<div class="ws-top">
  <div class="container">
    <nav class="ws-crumbs" aria-label="Шлях">
      <a class="ws-back" href="<?= View::e($back['href']) ?>" aria-label="<?= View::e($back['label']) ?>"><i class="fal fa-arrow-left"></i></a>
      <a class="ws-crumb" href="<?= View::e($back['href']) ?>"><?= View::e($back['label']) ?></a>
      <i class="fal fa-chevron-right ws-crumb-sep" aria-hidden="true"></i>
      <span class="ws-crumb-cur">№<?= $id ?> · <?= View::e($order['product_name']) ?></span>
    </nav>
    <div class="ws-head">
      <div class="ws-head-main">
        <div class="ws-title-row">
          <h1 class="ws-title"><?= View::e($order['title']) ?></h1>
          <span class="ws-status" data-ws-status><?php View::render('partials/status', ['status' => $order['status']]) ?></span>
        </div>
        <div class="ws-who"><span class="avatar avatar-xs"><?= View::e(Chat::initials($whoName)) ?></span><span><span class="ws-num">№<?= $id ?> · </span><?= View::e($who) ?></span></div>
      </div>
      <div class="ws-terms">
        <?php if ($designer): ?>
        <div class="ws-term"><span class="lbl">Ви отримаєте</span> <b class="val"><?= View::e(View::price($offer['price_designer'])) ?></b> <span class="hint"><span class="sep">· </span>клієнт побачить <?= View::e(View::price($offer['price_client'])) ?></span></div>
        <?php else: ?>
        <div class="ws-term"><span class="lbl">Ціна</span> <b class="val"><?= View::e(View::price($offer['price_client'])) ?></b></div>
        <?php endif ?>
        <div class="ws-term ws-deadline"><span class="lbl">Термін</span> <b class="val-sm"><?= View::e($deadline) ?></b></div>
      </div>
      <?php if ($cta): ?>
      <div class="ws-cta">
        <?php if ($designer && $edit): ?>
          <button type="button" class="btn btn-primary btn-lg" data-ws-upload><i class="fal fa-upload"></i> Завантажити макети</button>
        <?php elseif ($designer): ?>
          <a class="btn btn-outline-blue btn-lg" href="/feed/<?= $id ?>/?form=rework#ws-rework"><i class="fal fa-redo"></i> Ще не закінчили</a>
          <div class="ws-soon">Клієнт просить правки — запропонуйте доопрацювання</div>
        <?php elseif ($quote !== null): ?>
          <button type="button" class="btn btn-primary btn-lg ws-buy" data-ws-buy aria-haspopup="dialog" aria-controls="pay-<?= $id ?>"
            aria-describedby="ws-buy-hint-<?= $id ?>"<?= $newLayouts > 0 ? '' : ' disabled' ?>><i class="fal fa-shopping-bag"></i> Купую</button>
          <div class="ws-soon" id="ws-buy-hint-<?= $id ?>" data-ws-buy-hint<?= $newLayouts > 0 ? ' hidden' : '' ?>>Коли дизайнер завантажить макети</div>
          <?php if ($cancel): ?>
          <form class="ws-rework-cancel" method="post" action="<?= $reworkUrl ?>" data-lock
            data-ws-confirm="Скасувати доопрацювання? Ціна повернеться до сплаченої, нові макети, які ви ще не купили, лишаться закритими.">
            <?= $hidden ?><input type="hidden" name="action" value="cancel">
            <button type="submit" class="btn btn-link link-red">Скасувати доопрацювання</button>
          </form>
          <?php endif ?>
        <?php else: ?>
          <form method="post" action="<?= $reworkUrl ?>" data-lock>
            <?= $hidden ?><input type="hidden" name="action" value="accept"><input type="hidden" name="proposal" value="<?= View::e($rework['token'] ?? '') ?>">
            <button type="submit" class="btn btn-primary btn-lg w-100"><i class="fal fa-check"></i> Прийняти</button>
          </form>
          <form method="post" action="<?= $reworkUrl ?>" data-lock
            data-ws-confirm="Відмовитися від доопрацювання? Куплені макети лишаються у вас, дизайнер може запропонувати знову.">
            <?= $hidden ?><input type="hidden" name="action" value="decline"><input type="hidden" name="proposal" value="<?= View::e($rework['token'] ?? '') ?>">
            <button type="submit" class="btn btn-outline-blue btn-lg w-100">Відмовитися</button>
          </form>
        <?php endif ?>
      </div>
      <?php endif ?>
    </div>
    <?php if ($error !== null): ?>
    <div class="alert-d error ws-turn" role="alert"><i class="fal fa-exclamation-circle mt-1"></i><div class="fw-semibold"><?= View::e($error) ?></div></div>
    <?php endif ?>
    <div class="alert-d <?= $turn['mine'] ? 'turn' : 'info' ?> ws-turn" data-ws-turn<?= $rework !== null ? ' data-ws-stage="' . View::e($rework['stage']) . '"' : '' ?>>
      <span class="ic"><i class="fal <?= View::e($turn['icon']) ?>" data-ws-turn-icon></i></span>
      <div><div class="t" data-ws-turn-text><?= View::e($turn['text']) ?></div><div class="text-2 small" data-ws-turn-sub<?= $turn['sub'] === '' ? ' hidden' : '' ?>><?= View::e($turn['sub']) ?></div></div>
    </div>
    <?php if ($reworkForm): ?>
    <form id="ws-rework" class="card-d sheet ws-rework" method="post" action="<?= $reworkUrl ?>" data-lock data-ws-rework
      data-k="<?= View::e((string)$rework['k']) ?>" data-round="<?= (int)$rework['round'] ?>"
      data-paid-client="<?= (int)$rework['paid_client'] ?>" data-paid-designer="<?= (int)$rework['paid_designer'] ?>">
      <?= $hidden ?><input type="hidden" name="action" value="propose">
      <div class="sheet-head"><h2>Ще не закінчили?</h2><a class="icon-btn" href="/feed/<?= $id ?>/" aria-label="Закрити"><i class="fal fa-times"></i></a></div>
      <p class="text-2 small mb-0">Клієнт попросив правки? Назвіть нову повну суму вам на руки і дату. Клієнт прийме або відмовиться — куплені макети лишаються в нього.</p>
      <div class="ws-rework-fields">
        <div>
          <label class="f-label" for="ws-rework-price">Нова повна сума вам на руки</label>
          <div class="money-input">
            <input id="ws-rework-price" class="form-control" name="price" type="text" inputmode="numeric" autocomplete="off" required
              value="<?= View::e($rv['price']) ?>" data-rework-price>
            <span class="unit">грн</span>
          </div>
          <div class="ws-soon text-start mt-1">Уже нараховано <?= View::e(View::price($rework['paid_designer'])) ?>. Та сама сума — правка без доплати</div>
        </div>
        <div>
          <label class="f-label" for="ws-rework-deadline">Готово до</label>
          <input id="ws-rework-deadline" class="form-control" name="deadline" type="date" required min="<?= date('Y-m-d') ?>" value="<?= View::e($rv['deadline']) ?>">
        </div>
      </div>
      <div class="of-client" aria-live="polite"><i class="fal fa-eye"></i> <span data-rework-client><?= View::e($reworkHint) ?></span></div>
      <div class="sheet-actions">
        <a class="btn btn-outline-blue" href="/feed/<?= $id ?>/">Скасувати</a>
        <button type="submit" class="btn btn-primary">Запропонувати</button>
      </div>
    </form>
    <?php endif ?>
    <details class="brief-box card-d ws-brief">
      <summary><i class="fal fa-file-alt"></i> Бриф · <?= View::e($order['product_name']) ?> · <?= $filled ?> <?= OrderStatus::plural($filled, 'відповідь', 'відповіді', 'відповідей') ?><span class="more"></span></summary>
      <dl class="qa-grid">
        <?php foreach ($order['answers'] as $p): ?>
          <div><dt><?= View::e($p['q']) ?></dt><dd><?= $p['a'] !== '' ? nl2br(View::e($p['a'])) : '<span class="text-3">—</span>' ?></dd></div>
        <?php endforeach ?>
      </dl>
    </details>
  </div>
</div>
<div class="ws-tabs" role="tablist" aria-label="Робочий простір" data-ws-tabs>
  <button type="button" role="tab" class="active" aria-selected="true" aria-controls="ws-feed-<?= $id ?>" data-ws-tab="chat">Чат</button>
  <button type="button" role="tab" aria-selected="false" aria-controls="ws-side-<?= $id ?>" data-ws-tab="layouts"><i class="fal <?= $designer ? ($edit ? 'fa-file-upload' : 'fa-folder') : ($quote !== null ? 'fa-lock-alt' : 'fa-folder') ?>" data-ws-tab-icon></i><span>Макети<span data-lp-count-wrap<?= $panel['count'] ? '' : ' hidden' ?>> · <span data-lp-count><?= $panel['count'] ?></span></span></span></button>
</div>
<div class="container ws-body" data-ws-body data-tab="chat">
  <div class="ws-grid">
    <div class="ws-feed" id="ws-feed-<?= $id ?>" role="tabpanel"><?php View::render('partials/chat', $chat) ?></div>
    <aside class="ws-side" id="ws-side-<?= $id ?>" role="tabpanel"><?php View::render('partials/layouts-panel', [
        'orderId' => $id, 'panel' => $panel, 'edit' => $edit, 'client' => !$designer, 'csrf' => $csrf, 'chunk' => $chunk]) ?></aside>
  </div>
</div>
<?php if ($quote !== null): ?>
<?php View::render('partials/pay-modal', ['orderId' => $id, 'quote' => $quote, 'csrf' => $csrf]) ?>
<?php endif ?>
