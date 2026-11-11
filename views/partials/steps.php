<?php
/** @var int $step 1..3 */
$labels = [1 => 'Продукт', 2 => 'Питання', 3 => 'Як обрати дизайнера'];
?><div class="steps" aria-label="Крок <?= (int)$step ?> з 3">
<?php foreach ($labels as $n => $label): $cls = $n < $step ? 'done' : ($n === $step ? 'cur' : ''); ?>
  <div class="step <?= $cls ?>"><span class="num"><?= $n < $step ? '<i class="fas fa-check"></i>' : $n ?></span><span class="lbl"><?= $label ?></span></div>
  <?php if ($n < 3): ?><div class="line"></div><?php endif ?>
<?php endforeach ?>
</div>
