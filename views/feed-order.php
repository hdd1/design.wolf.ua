<?php
/**
 * Бриф для дизайнера (макети 6.7 «Пропозиція дизайнера», «Запрошення», «Вікна · десктоп»): стан своєї пропозиції або
 * запрошення, форма, повний бриф і листування
 * @var array $order Orders::find @var ?array $offer Offers::forDesigner @var string $client «Тарас Г.» @var string $facts Customers::factsLine — «Замовлень: 5 · куплено: 3 · скасовано: 1» / «Новий клієнт»
 * @var array<string,bool> $forms доступні форми @var string $form відкрита форма ('' — жодної)
 * @var array{text:string,price:string,deadline:string,reason:string} $values @var ?string $error
 * @var string $csrf @var ?array $chat параметри partials/chat @var float $k @var int $round
 * @var ?array $workspace параметри partials/workspace — обраний дизайнер замовлення в роботі (макет 6.5); тоді решта не показується
 */
use Design\Chat;
use Design\Messages;
use Design\OfferStatus;
use Design\OrderStatus;
use Design\View;
use Design\Workspace;

if ($workspace !== null) {
    View::render('partials/workspace', $workspace);
    return;
}
$id = $order['id'];
$url = "/feed/$id/";
$status = $offer['status'] ?? null;
$filled = count(array_filter($order['answers'], static fn($p) => $p['a'] !== ''));
$context = $order['product_name'] . ' · №' . $id . ($order['title'] !== $order['product_name'] ? ' · ' . $order['title'] : '');
$hidden = '<input type="hidden" name="csrf" value="' . View::e($csrf) . '">';
$terms = $offer !== null && $offer['price_designer'] > 0
    ? View::price($offer['price_designer']) . ' на руки' . ($offer['deadline'] ? ' · до ' . View::date($offer['deadline']) : '') : '';
?>
<div class="container page">
  <a class="link-back" href="/feed/"><i class="fal fa-arrow-left"></i> Стрічка брифів</a>
  <div class="row g-4 mt-1">
    <div class="<?= $chat !== null ? 'col-lg-7' : 'col-lg-8 mx-auto' ?>">
      <div class="fo-head">
        <div class="eyebrow"><?= View::e($order['product_name']) ?> · №<?= $id ?></div>
        <h1 class="fo-title"><?= View::e($order['title']) ?></h1>
        <div class="fo-meta">
          <span class="who"><span class="avatar avatar-xs"><?= View::e(Chat::initials($client)) ?></span><?= View::e($client) ?></span>
          <span class="client-facts"><?= View::e($facts) ?></span>
          <?php if ($order['published_at'] !== null): ?><span>Опубліковано <?= View::e(View::when($order['published_at'])) ?></span><?php endif ?>
        </div>
      </div>

      <?php if ($error !== null): ?>
        <div class="alert-d error mb-3" role="alert"><i class="fal fa-exclamation-circle mt-1"></i><div><?= View::e($error) ?></div></div>
      <?php endif ?>

      <div id="offer" class="fo-state">
        <?php if ($form === 'propose' || $form === 'edit' || $form === 'take'): ?>
          <?php View::render('partials/offer-form', [
              'orderId' => $id,
              'action' => ['propose' => 'propose', 'edit' => 'update', 'take' => 'accept'][$form],
              'heading' => $form === 'edit' ? 'Змінити пропозицію' : 'Ваша пропозиція',
              'context' => $form === 'edit' ? $context . ' · зараз ' . $terms : $context,
              'submit' => $form === 'edit' ? 'Зберегти зміни' : 'Надіслати пропозицію',
              'values' => $values,
              'csrf' => $csrf,
              'k' => $k,
              'round' => $round,
              'was' => $form === 'edit' ? ['designer' => $offer['price_designer'], 'client' => $offer['price_client']] : null,
          ]) ?>

        <?php elseif ($form === 'decline'): ?>
          <form class="card-d sheet" method="post" action="/feed/<?= $id ?>" data-lock>
            <?= $hidden ?><input type="hidden" name="action" value="decline">
            <div class="sheet-head"><h2>Відмовитися від №<?= $id ?>?</h2><a class="icon-btn" href="<?= $url ?>" aria-label="Закрити"><i class="fal fa-times"></i></a></div>
            <p class="text-2">Клієнт <?= View::e($client) ?> отримає сповіщення і зможе обрати іншого дизайнера.</p>
            <label class="f-label" for="of-reason">Причина <span class="text-3 fw-normal">— необовʼязково, її побачить клієнт</span></label>
            <textarea id="of-reason" class="form-control" name="reason" rows="3" maxlength="<?= Messages::TEXT_MAX ?>" placeholder="Наприклад: зараз багато замовлень, не встигну до потрібної дати"><?= View::e($values['reason']) ?></textarea>
            <div class="sheet-actions"><a class="btn btn-outline-blue" href="<?= $url ?>">Скасувати</a><button type="submit" class="btn btn-primary">Відмовитися</button></div>
          </form>

        <?php elseif (isset($forms['take'])): ?>
          <div class="card-d sheet invite">
            <h2>Вас обрали для замовлення №<?= $id ?></h2>
            <div class="fo-meta mb-3"><span class="who"><span class="avatar avatar-xs"><?= View::e(Chat::initials($client)) ?></span><?= View::e($client) ?></span><span><?= View::e($order['product_name']) ?> · запрошення надіслано <?= View::e(View::when($offer['updated'])) ?></span></div>
            <p class="text-2 small mb-0">«Беру» відкриває форму пропозиції — назвете суму на руки і термін. Клієнт побачить ціну і підтвердить вибір.</p>
            <div class="sheet-actions">
              <a class="btn btn-outline-blue" href="<?= $url ?>?form=decline#offer">Відмовитися</a>
              <a class="btn btn-primary" href="<?= $url ?>?form=take#offer">Беру</a>
            </div>
          </div>

        <?php elseif ($order['status'] === OrderStatus::CANCELLED): ?>
          <div class="card-d sheet closed">
            <i class="fal fa-ban"></i>
            <div>
              <div class="fw-bold"><?= View::e(Workspace::CANCELLED_DESIGNER) ?></div>
              <?php if ($terms !== '' && $status !== OfferStatus::INVITED): ?><div class="text-2 small"><?= $status === OfferStatus::CHOSEN ? 'Клієнт обрав вас: ' : 'Ви пропонували ' ?><?= View::e($terms) ?></div><?php endif ?>
            </div>
          </div>

        <?php elseif ($status === OfferStatus::SENT): ?>
          <div class="card-d sheet mine">
            <div class="sheet-head"><h2>Ваша пропозиція</h2><span class="badge-d blue"><i class="fal fa-hourglass-half"></i> Клієнт обирає</span></div>
            <div class="mine-terms">Ви запропонували <?= View::e($terms) ?></div>
            <div class="of-client"><i class="fal fa-eye"></i> Клієнт бачить <?= View::e(View::price($offer['price_client'])) ?></div>
            <p class="mine-text"><?= nl2br(View::e($offer['text'])) ?></p>
            <div class="sheet-actions justify-content-start">
              <a class="btn btn-outline-blue" href="<?= $url ?>?form=edit#offer">Змінити</a>
              <form method="post" action="/feed/<?= $id ?>" data-lock data-confirm="Відкликати пропозицію? Клієнт побачить, що ви її відкликали.">
                <?= $hidden ?><input type="hidden" name="action" value="withdraw">
                <button type="submit" class="btn btn-link link-red">Відкликати</button>
              </form>
            </div>
          </div>

        <?php elseif ($status === OfferStatus::CHOSEN): ?>
          <div class="card-d sheet chosen">
            <div class="sheet-head"><h2>Клієнт обрав вас</h2><?php View::render('partials/status', ['status' => $order['status']]) ?></div>
            <div class="mine-terms"><?= View::e($terms) ?></div>
            <p class="mine-text"><?= nl2br(View::e($offer['text'])) ?></p>
          </div>

        <?php elseif (isset($forms['propose'])): ?>
          <div class="card-d sheet cta">
            <div>
              <h2>Візьметеся?</h2>
              <p class="text-2 mb-0"><?= match ($status) {
                  OfferStatus::WITHDRAWN => 'Ви відкликали пропозицію. Можна надіслати нову — з іншою сумою чи терміном.',
                  OfferStatus::DECLINED => 'Ви відмовлялися від запрошення, тепер бриф відкритий для всіх — можна запропонувати себе.',
                  default => 'Опишіть, що зробите, назвіть суму на руки і термін. Клієнт побачить ціну з націнкою Майстерні.',
              } ?></p>
            </div>
            <a class="btn btn-primary btn-lg" href="<?= $url ?>?form=propose#offer"><?= $status === OfferStatus::WITHDRAWN ? 'Запропонувати знову' : 'Запропонувати себе' ?></a>
          </div>

        <?php else: ?>
          <div class="card-d sheet closed">
            <i class="fal <?= $status === OfferStatus::OTHER_CHOSEN ? 'fa-user-check' : 'fa-lock-alt' ?>"></i>
            <div>
              <div class="fw-bold"><?= match ($status) {
                  OfferStatus::OTHER_CHOSEN => 'Клієнт обрав іншого дизайнера',
                  OfferStatus::WITHDRAWN => 'Ви відкликали пропозицію',
                  OfferStatus::DECLINED => 'Ви відмовилися від цього замовлення',
                  default => 'Запрошення вже неактуальне',
              } ?></div>
              <?php if ($terms !== '' && $status !== OfferStatus::INVITED): ?><div class="text-2 small">Ви пропонували <?= View::e($terms) ?></div><?php endif ?>
            </div>
          </div>
        <?php endif ?>
      </div>

      <details class="brief-box card-d mt-3" open>
        <summary><i class="fal fa-file-alt"></i> Бриф · <?= View::e($order['product_name']) ?> · <?= $filled ?> <?= OrderStatus::plural($filled, 'відповідь', 'відповіді', 'відповідей') ?><span class="more"></span></summary>
        <dl>
          <?php foreach ($order['answers'] as $p): ?>
            <dt><?= View::e($p['q']) ?></dt><dd><?= $p['a'] !== '' ? nl2br(View::e($p['a'])) : '<span class="text-3">—</span>' ?></dd>
          <?php endforeach ?>
        </dl>
      </details>
    </div>

    <?php if ($chat !== null): ?>
      <div class="col-lg-5" id="chat">
        <div class="fo-chat"><?php View::render('partials/chat', $chat) ?></div>
      </div>
    <?php endif ?>
  </div>
</div>
