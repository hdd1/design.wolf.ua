<?php

namespace Design;

/**
 * Картинки портфоліо й аватари: перевірка завантаження, оригінал як є, зменшені копії WebP через Imagick.
 * Без БД і без знання про дизайнерів — лише файли в переданій папці.
 */
final class Images
{
    public const MAX_BYTES = 20 * 1024 * 1024;
    public const MAX_SIDE = 12000;

    private const CARD_W = 600;
    private const CARD_H = 480;
    private const VIEW_SIDE = 2000;
    private const AVATAR_SIDE = 320;
    private const WEBP_QUALITY = 82;

    /** Тип getimagesize → розширення оригіналу і формат, який має побачити Imagick */
    private const TYPES = [
        IMAGETYPE_JPEG => ['jpg', 'JPEG'],
        IMAGETYPE_PNG => ['png', 'PNG'],
    ];

    private const ERR_BROKEN = 'Файл пошкоджений або це не картинка';

    /**
     * Перевіряє картинку, кладе оригінал як є в $dir/$base.jpg|png, робить $base-card.webp (обрізка по центру 600×480)
     * і $base-view.webp (вписати в 2000×2000, не збільшуючи). width/height — оригіналу після повороту за EXIF.
     * @return array{width:int, height:int, file:string}
     * @throws DesignerException якщо файл не підходить; помилки запису летять як є
     */
    public static function store(string $src, string $dir, string $base): array
    {
        $ext = self::check($src);
        $im = self::load($src);
        $written = [];
        try {
            self::ensureDir($dir);
            $file = "$base.$ext";
            $written[] = "$dir/$file";
            if (!@copy($src, "$dir/$file")) throw new \RuntimeException("Не вдалося записати $dir/$file");

            $card = clone $im;
            $card->cropThumbnailImage(self::CARD_W, self::CARD_H);
            $written[] = "$dir/$base-card.webp";
            self::writeWebp($card, "$dir/$base-card.webp");
            $card->clear();

            $view = clone $im;
            self::fitInto($view, self::VIEW_SIDE);
            $written[] = "$dir/$base-view.webp";
            self::writeWebp($view, "$dir/$base-view.webp");
            $view->clear();

            return ['width' => $im->getImageWidth(), 'height' => $im->getImageHeight(), 'file' => $file];
        } catch (\Throwable $e) {
            self::remove($written);
            throw $e;
        } finally {
            $im->clear();
        }
    }

    /**
     * Ті ж перевірки; квадратний аватар 320×320 по центру в $dir/avatar-<8 hex>.webp. Оригінал не зберігається.
     * @return string ім'я файлу
     */
    public static function avatar(string $src, string $dir): string
    {
        self::check($src);
        $im = self::load($src);
        $file = 'avatar-' . bin2hex(random_bytes(4)) . '.webp';
        try {
            self::ensureDir($dir);
            $im->cropThumbnailImage(self::AVATAR_SIDE, self::AVATAR_SIDE);
            self::writeWebp($im, "$dir/$file");
            return $file;
        } catch (\Throwable $e) {
            self::remove(["$dir/$file"]);
            throw $e;
        } finally {
            $im->clear();
        }
    }

    /** Розмір, тип і сторони — до повного декодування. Повертає розширення оригіналу. */
    private static function check(string $src): string
    {
        $bytes = is_file($src) && is_readable($src) ? filesize($src) : false;
        if ($bytes === false || $bytes === 0) throw new DesignerException(self::ERR_BROKEN);
        if ($bytes > self::MAX_BYTES) {
            throw new DesignerException(sprintf('Файл завеликий: %d МБ. Максимум — %d МБ',
                (int)ceil($bytes / 1048576), self::MAX_BYTES / 1048576));
        }

        // Тип — за сигнатурою силами PHP, щоб Imagick не підбирав кодек (SVG, MVG, PS…) до чужого файлу
        $info = @getimagesize($src);
        if ($info === false) throw new DesignerException(self::ERR_BROKEN);
        if (!isset(self::TYPES[$info[2]])) throw new DesignerException('Підходять лише JPG або PNG');
        [$ext, $format] = self::TYPES[$info[2]];

        self::limitResources();
        $ping = new \Imagick();
        $fh = fopen($src, 'rb');
        try {
            $ping->pingImageFile($fh);
            $actual = strtoupper($ping->getImageFormat());
            $w = $ping->getImageWidth();
            $h = $ping->getImageHeight();
        } catch (\ImagickException $e) {
            throw new DesignerException(self::ERR_BROKEN);
        } finally {
            fclose($fh);
            $ping->clear();
        }
        if ($actual !== $format || $w < 1 || $h < 1) throw new DesignerException(self::ERR_BROKEN);
        if (max($w, $h) > self::MAX_SIDE) throw new DesignerException('Зображення завелике: до 12 000 px по стороні');
        return $ext;
    }

    /** Повне декодування і підготовка спільної основи для копій: поворот, sRGB, без метаданих */
    private static function load(string $src): \Imagick
    {
        $im = new \Imagick();
        $fh = fopen($src, 'rb');
        try {
            $im->readImageFile($fh);
            self::orient($im);
            self::toSrgb($im);
            $im->stripImage();
            return $im;
        } catch (\ImagickException $e) {
            $im->clear();
            throw new DesignerException(self::ERR_BROKEN);
        } finally {
            fclose($fh);
        }
    }

    /** Ліміти, щоб 20-мегабайтний JPEG не роздув php-fpm: надлишок піксельного кешу йде на диск */
    private static function limitResources(): void
    {
        \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_MEMORY, 256 * 1024 * 1024);
        \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_MAP, 512 * 1024 * 1024);
    }

    /** Поворот за EXIF Orientation; autoOrient є не в усіх збірках Imagick */
    private static function orient(\Imagick $im): void
    {
        if (method_exists($im, 'autoOrient')) {
            $im->autoOrient();
        } else {
            self::orientManually($im);
        }
        $im->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);
        $im->setImagePage(0, 0, 0, 0);
    }

    private static function orientManually(\Imagick $im): void
    {
        switch ($im->getImageOrientation()) {
            case \Imagick::ORIENTATION_TOPRIGHT:    $im->flopImage(); break;
            case \Imagick::ORIENTATION_BOTTOMRIGHT: $im->rotateImage('#000', 180); break;
            case \Imagick::ORIENTATION_BOTTOMLEFT:  $im->flipImage(); break;
            case \Imagick::ORIENTATION_LEFTTOP:     $im->transposeImage(); break;
            case \Imagick::ORIENTATION_RIGHTTOP:    $im->rotateImage('#000', 90); break;
            case \Imagick::ORIENTATION_RIGHTBOTTOM: $im->transverseImage(); break;
            case \Imagick::ORIENTATION_LEFTBOTTOM:  $im->rotateImage('#000', 270); break;
        }
    }

    /** CMYK → sRGB: з вбудованим ICC — через профілі (кольори як задумано), інакше — формулою */
    private static function toSrgb(\Imagick $im): void
    {
        if ($im->getImageColorspace() !== \Imagick::COLORSPACE_CMYK) return;
        if ($im->getImageProfiles('icc', false)) {
            $im->profileImage('icc', gzinflate(base64_decode(self::SRGB_ICC)));
        }
        if ($im->getImageColorspace() !== \Imagick::COLORSPACE_SRGB) {
            $im->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
        }
    }

    /** Вписати в квадрат $side, не збільшуючи */
    private static function fitInto(\Imagick $im, int $side): void
    {
        $w = $im->getImageWidth();
        $h = $im->getImageHeight();
        $scale = min($side / $w, $side / $h);
        if ($scale >= 1) return;
        $im->resizeImage(max(1, (int)round($w * $scale)), max(1, (int)round($h * $scale)), \Imagick::FILTER_LANCZOS, 1);
    }

    private static function writeWebp(\Imagick $im, string $path): void
    {
        $im->setImagePage(0, 0, 0, 0);
        $im->setImageFormat('webp');
        $im->setImageCompressionQuality(self::WEBP_QUALITY);
        $fh = @fopen($path, 'wb');
        if ($fh === false) throw new \RuntimeException("Не вдалося записати $path");
        try {
            $im->writeImageFile($fh);
        } finally {
            fclose($fh);
        }
    }

    private static function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException("Не вдалося створити папку $dir");
        }
    }

    /** @param string[] $paths */
    private static function remove(array $paths): void
    {
        foreach ($paths as $p) {
            if (is_file($p)) unlink($p);
        }
    }

    /** sRGB2014.icc (International Color Consortium, вільне поширення), gzdeflate + base64 */
    private const SRGB_ICC = <<<'ICC'
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
ICC;
}
