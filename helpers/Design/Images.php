<?php

namespace Design;

/**
 * Картинки портфоліо й аватари: перевірка завантаження, оригінал як є, зменшені копії WebP через Imagick.
 * Публічні кроки (check, load, toSrgb, fitInto, writeWebp, ensureDir…) використовує й PreviewImages.
 * Без БД і без знання про дизайнерів — лише файли в переданій папці.
 */
final class Images
{
    public const MAX_BYTES = 20 * 1024 * 1024;
    public const MAX_SIDE = 12000;
    /** Довша сторона не більше ніж у стільки разів за коротшу — інакше обрізка «по центру» вироджується */
    public const MAX_RATIO = 20;

    private const CARD_W = 600;
    private const CARD_H = 480;
    private const VIEW_SIDE = 2000;
    private const AVATAR_SIDE = 320;
    private const WEBP_QUALITY = 82;
    /** Права нових файлів: їх читає nginx i.fastcdn (WebP) */
    private const FILE_MODE = 0644;

    /** Тип getimagesize → розширення оригіналу і формат, який має побачити Imagick */
    private const TYPES = [
        IMAGETYPE_JPEG => ['jpg', 'JPEG'],
        IMAGETYPE_PNG => ['png', 'PNG'],
    ];
    /** TIFF — лише для прев'ю ($allowTiff); читається тільки перша сторінка */
    private const TIFF_TYPES = [
        IMAGETYPE_TIFF_II => ['tif', 'TIFF'],
        IMAGETYPE_TIFF_MM => ['tif', 'TIFF'],
    ];
    /** Шлях, який можна віддати Imagick як ім'я файлу з індексом сторінки «[0]» */
    private const SAFE_PATH = '~^/[A-Za-z0-9._/-]+$~D';

    public const ERR_BROKEN = 'Файл пошкоджений або це не картинка';

    /**
     * Перевіряє картинку, кладе оригінал як є в $dir/$base.jpg|png, робить $base-card.webp (обрізка по центру 600×480)
     * і $base-view.webp (вписати в 2000×2000, не збільшуючи). width/height — оригіналу після повороту за EXIF.
     * @return array{width:int, height:int, file:string}
     * @throws DesignerException якщо файл не підходить; помилки запису летять як є
     */
    public static function store(string $src, string $dir, string $base): array
    {
        $info = self::check($src);
        $im = self::load($src, $info, self::VIEW_SIDE, self::CARD_W);
        $written = [];
        try {
            self::ensureDir($dir);
            $file = "$base.{$info['ext']}";
            $written[] = "$dir/$file";
            if (!@copy($src, "$dir/$file")) throw new \RuntimeException("Не вдалося записати $dir/$file");
            chmod("$dir/$file", self::FILE_MODE);

            $view = clone $im;
            self::fitInto($view, self::VIEW_SIDE);
            $written[] = "$dir/$base-view.webp";
            self::writeWebp($view, "$dir/$base-view.webp");

            // Картку — з уже зменшеного view, якщо його вистачає на 600×480 без збільшення (вузькі — з основи)
            $fromView = $view->getImageWidth() >= self::CARD_W && $view->getImageHeight() >= self::CARD_H;
            $card = $fromView ? $view : clone $im;
            $card->cropThumbnailImage(self::CARD_W, self::CARD_H);
            $written[] = "$dir/$base-card.webp";
            self::writeWebp($card, "$dir/$base-card.webp");
            $card->clear();
            $view->clear();

            return ['width' => $info['width'], 'height' => $info['height'], 'file' => $file];
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
        $im = self::load($src, self::check($src), self::AVATAR_SIDE, self::AVATAR_SIDE);
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

    /** «Файл завеликий: 48 МБ. Максимум — 20 МБ»; розмір невідомий (відкинув сервер) — без числа; $maxBytes — інший ліміт (прев'ю, макети) */
    public static function tooBig(?int $bytes, int $maxBytes = self::MAX_BYTES): string
    {
        $max = intdiv($maxBytes, 1048576);
        return $bytes !== null && $bytes > 0
            ? sprintf('Файл завеликий: %d МБ. Максимум — %d МБ', (int)ceil($bytes / 1048576), $max)
            : sprintf('Файл завеликий. Максимум — %d МБ', $max);
    }

    /**
     * Розмір, тип, сторони й пропорції — до повного декодування. $maxBytes — інший ліміт, $checkRatio = false —
     * без межі пропорцій (прев'ю: довгий банер — нормальна картинка), $allowTiff — ще й TIFF (прев'ю; перша сторінка)
     * @return array{ext:string, jpeg:bool, tiff:bool, w:int, h:int, width:int, height:int}
     *         w/h — як у файлі, width/height — після повороту за EXIF
     */
    public static function check(string $src, int $maxBytes = self::MAX_BYTES, bool $checkRatio = true, bool $allowTiff = false): array
    {
        $bytes = is_file($src) && is_readable($src) ? filesize($src) : false;
        if ($bytes === false || $bytes === 0) throw new DesignerException(self::ERR_BROKEN);
        if ($bytes > $maxBytes) throw new DesignerException(self::tooBig($bytes, $maxBytes));

        // Тип — за сигнатурою силами PHP, щоб Imagick не підбирав кодек (SVG, MVG, PS…) до чужого файлу
        $info = @getimagesize($src);
        if ($info === false) throw new DesignerException(self::ERR_BROKEN);
        $types = $allowTiff ? self::TYPES + self::TIFF_TYPES : self::TYPES;
        if (!isset($types[$info[2]])) throw new DesignerException('Підходять лише JPG або PNG');
        [$ext, $format] = $types[$info[2]];

        self::limitResources();
        $ping = new \Imagick();
        $fh = fopen($src, 'rb');
        try {
            if ($format === 'TIFF') {
                $ping->pingImage(self::firstTiffPage($src));
            } else {
                $ping->pingImageFile($fh);
            }
            $actual = strtoupper($ping->getImageFormat());
            $w = $ping->getImageWidth();
            $h = $ping->getImageHeight();
            $orientation = $ping->getImageOrientation();
        } catch (\ImagickException $e) {
            throw new DesignerException(self::ERR_BROKEN);
        } finally {
            fclose($fh);
            $ping->clear();
        }
        if ($actual !== $format || $w < 1 || $h < 1) throw new DesignerException(self::ERR_BROKEN);
        if (max($w, $h) > self::MAX_SIDE) throw new DesignerException('Зображення завелике: до 12 000 px по стороні');
        if ($checkRatio && max($w, $h) > self::MAX_RATIO * min($w, $h)) {
            throw new DesignerException('Незвичні пропорції: сторони відрізняються більш ніж у 20 разів');
        }
        // LEFTTOP…LEFTBOTTOM (5–8) повертають на 90°
        $turned = $orientation >= \Imagick::ORIENTATION_LEFTTOP && $orientation <= \Imagick::ORIENTATION_LEFTBOTTOM;
        return [
            'ext' => $ext, 'jpeg' => $format === 'JPEG', 'tiff' => $format === 'TIFF', 'w' => $w, 'h' => $h,
            'width' => $turned ? $h : $w, 'height' => $turned ? $w : $h,
        ];
    }

    /**
     * Декодування і підготовка спільної основи для копій: поворот, sRGB, без метаданих.
     * JPEG декодується одразу зменшеним (DCT-масштаб libjpeg), але не менше ніж $needLong по довшій стороні
     * і $needShort по коротшій (або оригінал, якщо він менший) — щоб копії не довелося збільшувати.
     */
    public static function load(string $src, array $info, int $needLong, int $needShort): \Imagick
    {
        $im = new \Imagick();
        if ($info['jpeg']) {
            $wide = $info['w'] >= $info['h'];
            $hintW = min($info['w'], $wide ? $needLong : $needShort);
            $hintH = min($info['h'], $wide ? $needShort : $needLong);
            if ($hintW < $info['w'] && $hintH < $info['h']) $im->setOption('jpeg:size', "{$hintW}x{$hintH}");
        }
        $fh = fopen($src, 'rb');
        try {
            if (!empty($info['tiff'])) {
                $im->readImage(self::firstTiffPage($src));
                $im->setImageFilename('');   // інакше префікс «TIFF:» з імені переважить формат при записі WebP
            } else {
                $im->readImageFile($fh);
            }
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

    /**
     * «TIFF:/шлях[0]» — явний кодек і лише перша сторінка (багатосторінковий TIFF не декодується весь).
     * Ім'я файлу Imagick розбирає сам, тож шлях — лише з безпечних символів
     */
    private static function firstTiffPage(string $src): string
    {
        if (!preg_match(self::SAFE_PATH, $src)) throw new \RuntimeException("Небезпечний шлях до TIFF: $src");
        return "TIFF:{$src}[0]";
    }

    /**
     * Ліміти, щоб 20-мегабайтний JPEG не роздув php-fpm: надлишок піксельного кешу йде на диск.
     * Один потік: max_execution_time рахує CPU всіх потоків процесу, а паралельні завантаження й так дає клієнт
     */
    public static function limitResources(): void
    {
        \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_MEMORY, 256 * 1024 * 1024);
        \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_MAP, 512 * 1024 * 1024);
        \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_THREAD, 1);
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

    /**
     * До sRGB: вбудований не-sRGB профіль (CMYK, Adobe RGB, Display P3…) — конвертація через ICC, бо stripImage
     * його далі викине і кольори зблякнуть; CMYK без профілю (або з битим) — формулою.
     */
    public static function toSrgb(\Imagick $im): void
    {
        if ($im->getImageProfiles('icc', false) && stripos((string)$im->getImageProperty('icc:description'), 'sRGB') === false) {
            try {
                $im->profileImage('icc', gzinflate(base64_decode(self::SRGB_ICC)));
            } catch (\ImagickException $e) {
                // битий або несумісний профіль — не привід відмовляти: лишаються кольори без профілю
            }
        }
        if ($im->getImageColorspace() === \Imagick::COLORSPACE_CMYK) {
            $im->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
        }
    }

    /** Вписати в квадрат $side, не збільшуючи */
    public static function fitInto(\Imagick $im, int $side): void
    {
        $w = $im->getImageWidth();
        $h = $im->getImageHeight();
        $scale = min($side / $w, $side / $h);
        if ($scale >= 1) return;
        $im->resizeImage(max(1, (int)round($w * $scale)), max(1, (int)round($h * $scale)), \Imagick::FILTER_LANCZOS, 1);
    }

    /** WebP з правами 0644; $quality — інша якість (прев'ю) */
    public static function writeWebp(\Imagick $im, string $path, int $quality = self::WEBP_QUALITY): void
    {
        $im->setImagePage(0, 0, 0, 0);
        $im->setImageFormat('webp');
        $im->setImageCompressionQuality($quality);
        $fh = @fopen($path, 'wb');
        if ($fh === false) throw new \RuntimeException("Не вдалося записати $path");
        try {
            $im->writeImageFile($fh);
        } finally {
            fclose($fh);
        }
        // Файл створено за umask процесу; nginx на prod читає як «other»
        chmod($path, self::FILE_MODE);
    }

    /**
     * Створює папку (і відсутні батьківські) з правами 0755: на prod nginx читає як «other», а mkdir віддає права
     * за umask процесу. Наявні папки не чіпаємо.
     * @return string[] створені папки, від найглибшої — щоб прибрати їх після невдачі
     */
    public static function ensureDir(string $dir): array
    {
        $missing = [];
        for ($d = $dir; !is_dir($d); $d = dirname($d)) $missing[] = $d;
        if ($missing === []) return [];
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Не вдалося створити папку $dir");
        }
        foreach ($missing as $d) @chmod($d, 0755);
        return $missing;
    }

    /** @param string[] $paths */
    public static function remove(array $paths): void
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
