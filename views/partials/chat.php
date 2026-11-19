<?php
/**
 * Листування по пропозиції: шапка, повідомлення (перший рендер на сервері), поле вводу.
 * Оживляє assets/js/chat.js — сторінка додає його в 'scripts' layout; вставлене пізніше — window.DesignChat.init(section).
 * Робочий простір (обрана пропозиція, етап 4): картки прев'ю, цитати, «Коментувати» / «Відповісти», перегляд прев'ю,
 * у дизайнера — «додати прев'ю»; оживляє ще assets/js/workspace.js
 * @var int $offerId
 * @var string $title     з ким листування: «Петро Коваль» / «Тарас Г.»
 * @var string $subtitle  «1 200 грн · до 20 листопада»
 * @var ?string $note     підказка над повідомленнями («До вибору — лише текстові повідомлення…»), null — без неї
 * @var string $csrf
 * @var list<array> $messages  Chat::messages()
 * @var bool $write       чи можна писати
 * @var ?string $footer   готовий HTML під полем вводу (кнопка «Обрати Петра за 1 200 грн»), null — без нього
 * @var ?array{orderId:int, upload:bool, hide:bool, chunk:int} $workspace  робочий простір; null — звичайне листування
 */
use Design\Chat;
use Design\Messages;
use Design\View;

$workspace ??= null;
$ws = $workspace !== null;
$reply = $ws && $write;
$last = $messages ? end($messages)['id'] : 0;
$day = '';

/** Атрибути кнопки «Коментувати» / «Відповісти»: що покаже цитата над полем вводу */
$replyAttrs = static fn(int $id, string $title, string $text, string $thumb = ''): string =>
    ' data-reply="' . $id . '" data-quote-title="' . View::e($title) . '" data-quote-text="' . View::e($text) . '"'
    . ($thumb !== '' ? ' data-quote-thumb="' . View::e($thumb) . '"' : '');

/** Цитата над текстом відповіді */
$quote = static function (?array $r): string {
    if ($r === null) return '';
    $thumb = $r['thumb'] ?? null;
    return '<div class="quote" data-quote-to="' . (int)$r['id'] . '">'
        . ($thumb ? '<img class="quote-thumb" src="' . View::e($thumb) . '" alt="" loading="lazy">' : '')
        . '<div class="quote-body"><b>' . View::e($r['preview_num'] !== null ? 'Коментар до v' . $r['preview_num'] : $r['author']) . '</b>'
        . ($r['text'] !== '' ? '<span>' . View::e($r['text']) . '</span>' : '') . '</div></div>';
};

// Прев'ю листування для перегляду (assets/js/workspace.js): повідомлення з прев'ю у порядку надсилання
$previews = [];
foreach ($messages as $m) {
    if ($m['preview'] !== null) {
        $previews[] = ['msg' => $m['id'], 'author' => $m['author'], 'time' => $m['time'], 'mine' => $m['mine'], 'preview' => $m['preview']];
    }
}
?>
<section class="chat<?= $ws ? ' chat-ws' : '' ?>" data-chat="<?= (int)$offerId ?>" data-csrf="<?= View::e($csrf) ?>" data-after="<?= (int)$last ?>" data-write="<?= $write ? '1' : '0' ?>"<?php if ($ws): ?>
 data-order="<?= (int)$workspace['orderId'] ?>" data-reply-on="<?= $reply ? '1' : '0' ?>" data-chunk="<?= (int)$workspace['chunk'] ?>" data-can-hide="<?= $workspace['hide'] ? '1' : '0' ?>"
 data-previews="<?= View::e(json_encode($previews, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"<?php endif ?>>
  <header class="chat-head">
    <span class="avatar"><?= View::e(Chat::initials($title)) ?></span>
    <div class="chat-who">
      <div class="chat-title"><?= View::e($title) ?></div>
      <?php if ($subtitle !== ''): ?><div class="chat-sub"><?= View::e($subtitle) ?></div><?php endif ?>
    </div>
  </header>
  <div class="chat-body" data-chat-body>
    <?php if ($note !== null): ?>
    <div class="chat-note"><i class="fal fa-info-circle"></i><span><?= View::e($note) ?></span></div>
    <?php endif ?>
    <div class="chat-list" data-chat-list aria-live="polite">
      <?php if (!$messages): ?>
      <p class="chat-empty" data-chat-empty>Повідомлень ще немає</p>
      <?php endif ?>
      <?php foreach ($messages as $m): $hm = View::e(substr($m['time'], 11)) ?>
        <?php if (substr($m['time'], 0, 10) !== $day): $day = substr($m['time'], 0, 10) ?>
      <div class="chat-day" data-day="<?= View::e($day) ?>"><span><?= View::e(Chat::day($m['time'])) ?></span></div>
        <?php endif ?>
        <?php if ($m['system']): ?>
      <div class="chat-sys"><?= Chat::html($m['text']) ?></div>
        <?php elseif ($m['preview'] !== null): $p = $m['preview']; $card = $p['urls'][0]['card'] ?? '' ?>
      <div class="chat-msg is-preview<?= $m['mine'] ? ' mine' : '' ?>" data-id="<?= $m['id'] ?>">
        <?php if (!$m['mine']): ?><span class="avatar avatar-sm"><?= View::e(Chat::initials($m['author'])) ?></span><?php endif ?>
        <div class="pv-card<?= $p['hidden'] ? ' is-hidden' : '' ?>" data-preview="<?= $p['id'] ?>">
          <?php if ($p['hidden']): ?>
          <div class="pv-hidden"><i class="fal fa-eye-slash"></i><span>Прев'ю приховано дизайнером · v<?= $p['num'] ?></span></div>
          <?php else: ?>
          <button type="button" class="pv-img" data-pv-open="<?= $p['id'] ?>" aria-label="Відкрити v<?= $p['num'] ?>">
            <?php if ($card !== ''): ?><img src="<?= View::e($card) ?>" alt="" loading="lazy"><?php else: ?><i class="fal fa-image"></i><?php endif ?>
            <span class="pv-num">v<?= $p['num'] ?></span>
            <?php if ($p['pages'] > 1): ?><span class="pv-pages"><?= View::plural($p['pages'], 'сторінка', 'сторінки', 'сторінок') ?></span><?php endif ?>
          </button>
          <?php endif ?>
          <div class="pv-body">
            <div class="meta"><b><?= $m['mine'] ? 'Ви' : View::e($m['author']) ?></b> <?= $hm ?><?php if ($p['based_on_num'] !== null): ?> <span class="pv-based"><i class="fal fa-code-branch"></i> на основі v<?= $p['based_on_num'] ?></span><?php endif ?></div>
            <?php if ($m['text'] !== ''): ?><div class="text"><?= Chat::html($m['text']) ?></div><?php endif ?>
            <?php if (!$p['hidden']): ?>
            <div class="pv-acts">
              <?php if ($reply): ?><button type="button" class="pv-act"<?= $replyAttrs($m['id'], 'Коментар до v' . $p['num'], $m['text'], $card) ?>><i class="fal fa-comment-alt-lines"></i> Коментувати</button><?php endif ?>
              <button type="button" class="pv-act" data-pv-open="<?= $p['id'] ?>"><i class="fal fa-expand-alt"></i> Відкрити</button>
            </div>
            <?php endif ?>
          </div>
        </div>
      </div>
        <?php elseif ($m['mine']): ?>
      <div class="chat-msg mine" data-id="<?= $m['id'] ?>"><div class="bubble"><?= $quote($m['reply']) ?><div class="text"><?= Chat::html($m['text']) ?></div><div class="meta"><?php if ($reply): ?><button type="button" class="msg-reply" aria-label="Відповісти"<?= $replyAttrs($m['id'], 'Ви', $m['text']) ?>><i class="fal fa-reply"></i></button> <?php endif ?><?= $hm ?></div></div></div>
        <?php else: ?>
      <div class="chat-msg" data-id="<?= $m['id'] ?>"><span class="avatar avatar-sm"><?= View::e(Chat::initials($m['author'])) ?></span><div class="bubble"><div class="meta"><b><?= View::e($m['author']) ?></b> <?= $hm ?><?php if ($reply): ?> <button type="button" class="msg-reply" aria-label="Відповісти"<?= $replyAttrs($m['id'], $m['author'], $m['text']) ?>><i class="fal fa-reply"></i></button><?php endif ?></div><?= $quote($m['reply']) ?><div class="text"><?= Chat::html($m['text']) ?></div></div></div>
        <?php endif ?>
      <?php endforeach ?>
    </div>
  </div>
  <div class="chat-foot">
    <?php if ($ws && $workspace['upload']): ?>
    <div class="pv-compose" data-pv-compose hidden>
      <div class="pv-compose-head"><b>Нове прев'ю · буде <span data-pv-next>v1</span></b><button type="button" class="icon-btn" data-pv-cancel aria-label="Скасувати"><i class="fal fa-times"></i></button></div>
      <div class="pv-compose-row">
        <div class="pv-compose-thumb" data-pv-thumb><i class="fal fa-file-pdf"></i></div>
        <div class="pv-compose-fields">
          <div class="pv-compose-name" data-pv-name></div>
          <textarea rows="2" maxlength="<?= Messages::TEXT_MAX ?>" placeholder="Підпис: що в цій версії…" aria-label="Підпис до прев'ю" data-pv-caption></textarea>
        </div>
      </div>
      <div class="pv-based-on" data-pv-based-wrap><span>На основі:</span><div class="pv-chips" data-pv-based role="radiogroup" aria-label="На основі версії"></div></div>
      <div class="pv-progress" data-pv-progress hidden><div class="pv-bar"><span data-pv-bar></span></div><small data-pv-progress-text></small></div>
      <p class="chat-error" data-pv-error role="alert" hidden></p>
      <button type="button" class="btn btn-primary w-100" data-pv-send>Надіслати прев'ю</button>
    </div>
    <?php endif ?>
    <?php if ($ws): ?>
    <div class="chat-quote" data-chat-quote hidden>
      <img class="quote-thumb" alt="" data-chat-quote-thumb hidden>
      <div class="quote-body"><b data-chat-quote-title></b><span data-chat-quote-text></span></div>
      <button type="button" class="icon-btn" data-chat-quote-close aria-label="Скасувати відповідь"><i class="fal fa-times"></i></button>
    </div>
    <?php endif ?>
    <form class="chat-form" data-chat-form<?= $write ? '' : ' hidden' ?>>
      <?php if ($ws && $workspace['upload']): ?>
      <button type="button" class="chat-attach" data-pv-add aria-label="Додати прев'ю" title="Додати прев'ю: JPG, PNG, TIFF або PDF"><i class="fal fa-image"></i></button>
      <input type="file" accept=".jpg,.jpeg,.png,.tif,.tiff,.pdf,image/jpeg,image/png,image/tiff,application/pdf" data-pv-file hidden>
      <?php endif ?>
      <textarea name="text" rows="1" maxlength="<?= Messages::TEXT_MAX ?>" placeholder="Повідомлення…" aria-label="Повідомлення" data-chat-input></textarea>
      <button type="submit" class="chat-send" aria-label="Надіслати"><i class="fal fa-paper-plane"></i></button>
    </form>
    <p class="chat-closed" data-chat-closed<?= $write ? ' hidden' : '' ?>><i class="fal fa-lock-alt"></i> Листування закрите</p>
    <p class="chat-error" data-chat-error role="alert" hidden></p>
    <?php if ($footer !== null): ?><div class="chat-extra"><?= $footer ?></div><?php endif ?>
  </div>
  <?php if ($ws): View::render('partials/preview-viewer', ['hide' => $workspace['hide'], 'comment' => $reply]); endif ?>
</section>
