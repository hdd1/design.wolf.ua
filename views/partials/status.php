<?php
/** @var int $status */
use Design\OrderStatus;

$icons = [1 => 'fa-file-alt', 2 => 'fa-inbox', 3 => 'fa-hourglass-half', 4 => 'fa-pencil-ruler', 5 => 'fa-file-invoice', 6 => 'fa-check-circle', 7 => 'fa-sync', 8 => 'fa-times-circle'];
?><span class="status s<?= (int)$status ?>"><i class="fal <?= $icons[$status] ?? 'fa-circle' ?>"></i><?= OrderStatus::label($status) ?></span>
