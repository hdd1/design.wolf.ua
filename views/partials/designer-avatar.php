<?php
/**
 * Аватар дизайнера: фото, а без нього ініціали з імені (до двох слів)
 * @var int $designerId @var string $fullName @var string $photo  design_designers.photo ('' — немає)
 * @var string $class  css-клас кружечка
 */
use Design\Media;
use Design\View;

$words = preg_split('/\s+/u', trim($fullName), -1, PREG_SPLIT_NO_EMPTY);
$initials = implode('', array_map(static fn(string $w): string => mb_strtoupper(mb_substr($w, 0, 1)), array_slice($words, 0, 2)));
// Файлу немає (Media::url → '') — як без фото
$src = $photo !== '' ? Media::url($designerId, basename($photo, '.webp')) : '';
?>
<span class="<?= View::e($class) ?>"><?php if ($src !== ''): ?><img src="<?= View::e($src) ?>" alt="" loading="lazy"><?php elseif ($initials !== ''): ?><?= View::e($initials) ?><?php else: ?><i class="fal fa-user"></i><?php endif ?></span>
