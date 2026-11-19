<?php
/**
 * Листування по пропозиції: шапка, повідомлення (перший рендер на сервері), поле вводу.
 * Оживляє assets/js/chat.js — сторінка додає його в 'scripts' layout; вставлене пізніше — window.DesignChat.init(section)
 * @var int $offerId
 * @var string $title     з ким листування: «Петро Коваль» / «Тарас Г.»
 * @var string $subtitle  «1 200 грн · до 20 листопада»
 * @var ?string $note     підказка над повідомленнями («До вибору — лише текстові повідомлення…»), null — без неї
 * @var string $csrf
 * @var list<array{id:int, mine:bool, system:bool, author:string, text:string, time:string}> $messages  Chat::messages()
 * @var bool $write       чи можна писати
 * @var ?string $footer   готовий HTML під полем вводу (кнопка «Обрати Петра за 1 200 грн»), null — без нього
 */
use Design\Chat;
use Design\Messages;
use Design\View;

$last = $messages ? end($messages)['id'] : 0;
$day = '';
?>
<section class="chat" data-chat="<?= (int)$offerId ?>" data-csrf="<?= View::e($csrf) ?>" data-after="<?= (int)$last ?>" data-write="<?= $write ? '1' : '0' ?>">
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
      <?php foreach ($messages as $m): ?>
        <?php if (substr($m['time'], 0, 10) !== $day): $day = substr($m['time'], 0, 10) ?>
      <div class="chat-day" data-day="<?= View::e($day) ?>"><span><?= View::e(Chat::day($m['time'])) ?></span></div>
        <?php endif ?>
        <?php if ($m['system']): ?>
      <div class="chat-sys"><?= Chat::html($m['text']) ?></div>
        <?php elseif ($m['mine']): ?>
      <div class="chat-msg mine"><div class="bubble"><div class="text"><?= Chat::html($m['text']) ?></div><div class="meta"><?= View::e(substr($m['time'], 11)) ?></div></div></div>
        <?php else: ?>
      <div class="chat-msg"><span class="avatar avatar-sm"><?= View::e(Chat::initials($m['author'])) ?></span><div class="bubble"><div class="meta"><b><?= View::e($m['author']) ?></b> <?= View::e(substr($m['time'], 11)) ?></div><div class="text"><?= Chat::html($m['text']) ?></div></div></div>
        <?php endif ?>
      <?php endforeach ?>
    </div>
  </div>
  <div class="chat-foot">
    <form class="chat-form" data-chat-form<?= $write ? '' : ' hidden' ?>>
      <textarea name="text" rows="1" maxlength="<?= Messages::TEXT_MAX ?>" placeholder="Повідомлення…" aria-label="Повідомлення" data-chat-input></textarea>
      <button type="submit" class="chat-send" aria-label="Надіслати"><i class="fal fa-paper-plane"></i></button>
    </form>
    <p class="chat-closed" data-chat-closed<?= $write ? ' hidden' : '' ?>><i class="fal fa-lock-alt"></i> Листування закрите</p>
    <p class="chat-error" data-chat-error role="alert" hidden></p>
    <?php if ($footer !== null): ?><div class="chat-extra"><?= $footer ?></div><?php endif ?>
  </div>
</section>
