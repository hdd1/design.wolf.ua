<?php
/**
 * Пропозиції на сторінці замовлення клієнта (макет 6.4): картки і підтвердження вибору (модальне вікно, на телефоні — нижній лист).
 * Без рейтингу й кількості замовлень. Після вибору — обраний дизайнер окремо, решта сірі «Вже обрано іншого дизайнера»
 * @var int $orderId @var list<array> $cards Offers::forOrder (без відмов) @var ?array $chosen
 * @var \Closure $choosable fn(card): bool — чи можна обрати @var int $sel відкрите листування @var string $csrf
 */
use Design\OfferStatus;
use Design\View;

$card = static function (array $c) use ($orderId, $chosen, $choosable, $sel): string {
    $can = $choosable($c);
    $off = match (true) {
        $c['status'] === OfferStatus::CHOSEN => null,
        $chosen !== null => ['fa-user-check', 'Вже обрано іншого дизайнера'],
        $c['status'] === OfferStatus::WITHDRAWN => ['fa-undo', 'Дизайнер відкликав пропозицію'],
        !$c['available'] => ['fa-user-slash', 'Дизайнер недоступний'],
        default => null,
    };
    $name = $c['designer_name'] !== '' ? $c['designer_name'] : 'Дизайнер';
    $cls = 'offer-card card-d' . ($off !== null ? ' is-off' : '') . ($c['status'] === OfferStatus::CHOSEN ? ' is-chosen' : '') . ($c['id'] === $sel ? ' is-open' : '');
    $chip = match (true) {
        $c['status'] === OfferStatus::CHOSEN => '<span class="badge-d green"><i class="fal fa-check"></i> Обрано</span>',
        $c['price_changed'] && $c['status'] === OfferStatus::SENT => '<span class="badge-d warn">Ціну змінено</span>',
        default => '',
    };
    // Листування: є повідомлення — «Листування · N» (у недоступного дизайнера — лише читання), немає і можна писати — «Написати»
    $writable = in_array($c['status'], [OfferStatus::SENT, OfferStatus::CHOSEN], true) && ($chosen === null || $c['status'] === OfferStatus::CHOSEN)
        && $c['available'];
    $links = '';
    if ($c['messages'] > 0 || $writable) {
        $links .= '<a class="oc-link" href="/orders/' . $orderId . '/?chat=' . $c['id'] . '#chat"><i class="fal fa-comment"></i> '
            . ($c['messages'] > 0 ? 'Листування · ' . $c['messages'] : 'Написати') . '</a>';
    }
    if ($c['available']) {
        $links .= '<a class="oc-link" href="/designers/' . $c['designer_id'] . '/"><i class="fal fa-images"></i> Портфоліо</a>';
    }

    return '<article class="' . $cls . '" id="offer-' . $c['id'] . '">'
        . '<div class="oc-head">'
        . View::fetch('partials/designer-avatar', ['designerId' => $c['designer_id'], 'fullName' => $name, 'photo' => $c['designer_photo'], 'class' => 'avatar-md'])
        . '<div class="oc-name">' . View::e($name) . '</div>' . $chip . '</div>'
        . '<div class="oc-terms"><div><div class="lbl">Ціна</div><div class="price">' . View::e(View::price($c['price_client'])) . '</div></div>'
        . ($c['deadline'] ? '<div class="text-end"><div class="lbl">Готово до</div><div class="date">' . View::e(View::date($c['deadline'])) . '</div></div>' : '')
        . '</div>'
        . ($c['text'] !== '' ? '<p class="oc-text">' . nl2br(View::e($c['text'])) . '</p>' : '')
        . '<div class="oc-foot">'
        . ($off !== null ? '<span class="oc-off"><i class="fal ' . $off[0] . '"></i> ' . $off[1] . '</span>' : '')
        . ($links !== '' ? '<div class="oc-links">' . $links . '</div>' : '')
        . ($can ? '<button type="button" class="btn btn-primary btn-lg oc-choose" data-bs-toggle="modal" data-bs-target="#choose-' . $c['id'] . '">Обрати</button>' : '')
        . '</div></article>';
};

$others = $chosen === null ? $cards : array_values(array_filter($cards, static fn(array $c): bool => $c['status'] !== OfferStatus::CHOSEN));
$hint = count(array_filter($cards, $choosable)) > 0;
?>
<?php if ($chosen !== null): ?>
  <h2 class="offers-title">Обраний дизайнер</h2>
  <?= $card($chosen) ?>
<?php endif ?>
<?php if ($others): ?>
  <div class="offers-bar">
    <h2 class="offers-title"><?= $chosen !== null ? 'Інші пропозиції' : 'Пропозиції' ?> · <?= count($others) ?></h2>
    <?php if ($hint): ?><span class="text-2 small d-none d-md-inline">Натисніть «Листування», щоб написати дизайнеру</span><?php endif ?>
  </div>
  <?php foreach ($others as $c) echo $card($c) ?>
<?php endif ?>

<?php foreach ($cards as $c): if (!$choosable($c)) continue; $name = $c['designer_name'] !== '' ? $c['designer_name'] : 'Дизайнер' ?>
  <div class="modal fade choose-modal" id="choose-<?= $c['id'] ?>" tabindex="-1" aria-labelledby="choose-<?= $c['id'] ?>-t" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <form class="modal-content" method="post" action="/orders/<?= $orderId ?>" data-lock>
        <input type="hidden" name="csrf" value="<?= View::e($csrf) ?>">
        <input type="hidden" name="action" value="choose">
        <input type="hidden" name="offer_id" value="<?= $c['id'] ?>">
        <input type="hidden" name="expected_price" value="<?= $c['price_client'] ?>">
        <span class="cm-grip" aria-hidden="true"></span>
        <div class="cm-head">
          <h2 id="choose-<?= $c['id'] ?>-t">Обрати дизайнера за <?= View::e(View::price($c['price_client'])) ?><?= $c['deadline'] ? ', до ' . View::e(View::date($c['deadline'])) : '' ?>?</h2>
          <button type="button" class="icon-btn" data-bs-dismiss="modal" aria-label="Закрити"><i class="fal fa-times"></i></button>
        </div>
        <div class="cm-who">
          <?php View::render('partials/designer-avatar', ['designerId' => $c['designer_id'], 'fullName' => $name, 'photo' => $c['designer_photo'], 'class' => 'avatar-md']) ?>
          <span class="oc-name"><?= View::e($name) ?></span>
        </div>
        <div class="cm-terms">
          <div><div class="lbl">Ціна</div><div class="price"><?= View::e(View::price($c['price_client'])) ?></div></div>
          <?php if ($c['deadline']): ?><div><div class="lbl">Термін</div><div class="date">до <?= View::e(View::date($c['deadline'])) ?></div></div><?php endif ?>
        </div>
        <p class="text-2 mb-0">Інші дизайнери побачать, що обрано іншого. Платити зараз не потрібно — оплата лише коли натиснете «Купую» на готових макетах.</p>
        <div class="cm-actions">
          <button type="button" class="btn btn-outline-blue btn-lg" data-bs-dismiss="modal">Ще подумаю</button>
          <button type="submit" class="btn btn-primary btn-lg">Так, обрати</button>
        </div>
      </form>
    </div>
  </div>
<?php endforeach ?>
