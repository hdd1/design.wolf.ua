<?php
/**
 * Панель «Макети для друку» робочого простору (макети 6.5 «дизайнер · завантаження макетів», «клієнт · макети завантажено»).
 * Дизайнер (edit): перетягування / «Обрати файли» (кілька одночасно, у кожного прогрес і «Скасувати»), підпис з олівцем,
 * «Замінити», «Видалити» — лише в некуплених; куплений — з позначкою «Куплено», без змін. Клієнт: список — підпис, ім'я
 * файла, розмір; купленому — «Завантажити» (адреса i.fastcdn з Workspace::layouts, лише клієнту) і місце під «Скопіювати
 * в хмару» (data-lp-cloud). Некупленим посилань немає ніде. Оживляє assets/js/workspace-layouts.js (той самий вигляд пунктів)
 * @var int $orderId
 * @var array{layouts:list<array{id:int, label:string, original_name:string, size:int, size_text:string, ext:string, purchased:bool, download:string}>, count:int, rev:string} $panel Workspace::layouts
 * @var bool $edit       дизайнер може змінювати (Previews::canWork)
 * @var bool $client     дивиться клієнт замовлення
 * @var string $csrf
 * @var int $chunk       розмір частини завантаження (ChunkUpload::partSize)
 */
use Design\Layouts;
use Design\Settings;
use Design\View;

$client ??= !$edit;
$ext = static fn(string $e): string => $e === '' ? 'ФАЙЛ' : mb_strtoupper(mb_substr($e, 0, 4));
// Плашка «відкриються після покупки» — поки є некуплені макети або ще жодного
$locked = $panel['count'] === 0 || in_array(false, array_column($panel['layouts'], 'purchased'), true);
?>
<section class="lp card-d" id="layouts" tabindex="-1" data-layouts data-order="<?= (int)$orderId ?>" data-rev="<?= View::e($panel['rev']) ?>"
  data-edit="<?= $edit ? '1' : '0' ?>"<?php if ($edit): ?> data-csrf="<?= View::e($csrf) ?>" data-chunk="<?= (int)$chunk ?>"
  data-max="<?= Settings::layoutMaxBytes() ?>" data-max-text="<?= View::e(View::bytes(Settings::layoutMaxBytes())) ?>" data-label-max="<?= Layouts::LABEL_MAX ?>"<?php endif ?>>
  <h2 class="lp-title">
    <?php if ($edit): ?><i class="fal fa-file-upload"></i><span>Макети для друку</span>
    <?php else: ?><i class="fal fa-folder"></i><span>Макети<span data-lp-count-wrap<?= $panel['count'] ? '' : ' hidden' ?>> · <span data-lp-count><?= $panel['count'] ?></span></span></span><?php endif ?>
  </h2>
  <?php if ($edit): ?>
  <div class="lp-drop" data-lp-drop>
    <i class="fal fa-cloud-upload lp-drop-ic"></i>
    <div class="lp-drop-text">Перетягніть файли сюди або</div>
    <button type="button" class="btn btn-outline-blue" data-lp-pick>Обрати файли</button>
    <div class="lp-drop-hint">PDF, AI, CDR, PSD, TIFF, JPG … · до <?= View::e(View::bytes(Settings::layoutMaxBytes())) ?> кожен</div>
    <input type="file" multiple hidden data-lp-file>
    <input type="file" hidden data-lp-replace-file>
  </div>
  <?php elseif ($client): ?>
  <div class="lp-lock" data-lp-lock<?= $locked ? '' : ' hidden' ?>><i class="fal fa-lock-alt"></i><span>Макети відкриються після покупки. Зараз видно назву, розмір і підпис.</span></div>
  <p class="lp-empty" data-lp-empty<?= $panel['count'] ? ' hidden' : '' ?>>Дизайнер ще не завантажив макети</p>
  <?php else: ?>
  <p class="lp-empty" data-lp-empty<?= $panel['count'] ? ' hidden' : '' ?>>Макетів немає</p>
  <?php endif ?>
  <ul class="lp-list" data-lp-list>
    <?php foreach ($panel['layouts'] as $l): $bought = $l['purchased'] ?>
    <li class="lp-item<?= $bought ? ' is-bought' : '' ?>" data-lp-id="<?= $l['id'] ?>">
      <span class="lp-ext"><?= View::e($ext($l['ext'])) ?></span>
      <div class="lp-info">
        <div class="lp-label"><span data-lp-label><?= View::e($l['label']) ?></span><?php if ($edit && !$bought): ?><button type="button" class="lp-pencil" data-lp-rename aria-label="Змінити підпис" title="Змінити підпис"><i class="fal fa-pen"></i></button><?php endif ?></div>
        <div class="lp-meta"><?= View::e($l['original_name']) ?> · <?= View::e($l['size_text']) ?></div>
        <?php if ($l['download'] !== ''): ?>
        <div class="lp-acts">
          <a class="btn btn-outline-blue btn-sm lp-download" href="<?= View::e($l['download']) ?>"><i class="fal fa-download"></i> Завантажити</a>
          <span class="lp-cloud" data-lp-cloud></span>
        </div>
        <?php endif ?>
      </div>
      <?php if ($bought): ?>
        <?php if ($l['download'] === ''): ?><span class="lp-bought"><i class="fal fa-check-circle"></i> Куплено</span><?php endif ?>
      <?php elseif ($edit): ?>
      <button type="button" class="icon-btn lp-act" data-lp-replace aria-label="Замінити" title="Замінити"><i class="fal fa-sync-alt"></i></button>
      <button type="button" class="icon-btn del" data-lp-delete aria-label="Видалити" title="Видалити"><i class="fal fa-trash-alt"></i></button>
      <?php else: ?>
      <i class="fal fa-lock-alt lp-locked" aria-hidden="true"></i>
      <?php endif ?>
    </li>
    <?php endforeach ?>
  </ul>
  <?php if ($edit): ?>
  <ul class="lp-list" data-lp-uploads aria-live="polite"></ul>
  <p class="chat-error" data-lp-error role="alert" hidden></p>
  <div class="lp-note"><i class="fal fa-info-circle"></i><span>До покупки можна замінити чи видалити файл. Клієнт бачить лише назву, розмір і підпис.</span></div>
  <?php endif ?>
</section>
