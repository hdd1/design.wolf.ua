<?php

namespace Design;

/**
 * Картинки прев'ю: JPG/PNG — одна сторінка, PDF — кожна сторінка окремо (Ghostscript напряму).
 * Кожна сторінка — {previewId}-p{n}-view.webp (вписати в 2000×2000) і -card.webp (вписати в 800×800), WebP q90,
 * sRGB без метаданих, з напівпрозорим водяним знаком під кутом по всій площі. Оригінал не зберігається.
 * Без БД (крім лімітів із Settings) — лише файли в переданій папці.
 */
final class PreviewImages
{
    public const WATERMARK = 'Майстерня дизайну';

    private const VIEW_SIDE = 2000;
    private const CARD_SIDE = 800;
    private const WEBP_QUALITY = 90;

    /** Роздільність сторінок PDF; більші за 2000 px сторінки рендеряться з меншою (див. pdfDpi) */
    private const PDF_DPI = 100;
    /** Скільки чекати gs на підрахунок сторінок і на --version, с */
    private const GS_COUNT_TIMEOUT = 10;
    /** Скільки чекати gs на рендер усіх сторінок (разом з обробкою готових), с; static — тест ставить 0 */
    private static int $gsTimeout = 40;
    /** Скільки stderr gs тримати для журналу */
    private const GS_ERR_BYTES = 4096;

    private const FONT = __DIR__ . '/fonts/Manrope-SemiBold.ttf';
    /** Кут знака (проти годинникової), розмір шрифту = √(площа) / FONT_DIV, непрозорість напису */
    private const MARK_ANGLE = 30;
    private const MARK_FONT_DIV = 26;
    private const MARK_OPACITY = 0.28;

    private const ERR_TYPE = 'Підходять лише JPG, PNG або PDF';
    private const ERR_PAGES = "Забагато сторінок: %d. Максимум — %d. Розбийте на кілька прев'ю";
    private const ERR_PDF_SLOW = 'Не вдалося обробити PDF — спробуйте менший файл';

    /** @var array<int, array{0:\Imagick, 1:float}> готові «штампи» знака за розміром шрифту — у межах одного make() */
    private static array $stamps = [];
    private static ?string $gs = null;
    /** gs ≥ 9.50 — сторінки рахує сам gs (--permit-file-read); null — ще не визначено */
    private static ?bool $gsCounts = null;

    /**
     * @return array{pages:int, type:string} type — image|pdf (як design_previews.source_type)
     * @throws DesignerException файл не підходить (текст для користувача); помилки запису летять як є.
     *         При будь-якій помилці створене цим викликом (файли, папки) прибирається.
     */
    public static function make(string $src, string $dir, int $previewId): array
    {
        $bytes = is_file($src) && is_readable($src) ? filesize($src) : false;
        if ($bytes === false || $bytes === 0) throw new DesignerException(Images::ERR_BROKEN);
        $maxBytes = Settings::previewMaxBytes();
        if ($bytes > $maxBytes) throw new DesignerException(Images::tooBig($bytes, $maxBytes));

        $written = [];
        $dirs = [];
        try {
            if (file_get_contents($src, false, null, 0, 5) === '%PDF-') {
                $pages = self::pdf($src, $dir, $previewId, $written, $dirs);
                return ['pages' => $pages, 'type' => 'pdf'];
            }
            $type = @getimagesize($src);
            if ($type === false) throw new DesignerException(Images::ERR_BROKEN);
            if ($type[2] !== IMAGETYPE_JPEG && $type[2] !== IMAGETYPE_PNG) throw new DesignerException(self::ERR_TYPE);

            // JPEG декодується зменшеним, але не менше 2000 по довшій стороні — далі лише вписування
            $im = Images::load($src, Images::check($src, $maxBytes), self::VIEW_SIDE, 1);
            try {
                $dirs = Images::ensureDir($dir);
                self::page($im, $dir, $previewId, 1, $written);
            } finally {
                $im->clear();
            }
            return ['pages' => 1, 'type' => 'image'];
        } catch (\Throwable $e) {
            Images::remove($written);
            foreach ($dirs as $d) @rmdir($d);
            throw $e;
        } finally {
            foreach (self::$stamps as [$s]) $s->clear();
            self::$stamps = [];
        }
    }

    /** Рахує сторінки, рендерить їх одним процесом gs і обробляє кожну, щойно gs її дописав */
    private static function pdf(string $src, string $dir, int $previewId, array &$written, array &$dirs): int
    {
        Images::limitResources();
        [$count, $sizes] = self::pdfPages($src);
        if ($count === 0) throw new DesignerException(Images::ERR_BROKEN);
        $max = Settings::previewMaxPages();
        if ($count > $max) throw new DesignerException(sprintf(self::ERR_PAGES, $count, $max));

        $dirs = Images::ensureDir($dir);
        self::renderPdf($src, $count, self::pdfDpi($sizes), function (int $n, string $ppm) use ($dir, $previewId, &$written) {
            $im = new \Imagick();
            try {
                try {
                    $im->readImage("ppm:$ppm");
                    $im->setImageFilename('');   // інакше префікс «ppm:» з імені переважить формат при записі WebP
                } catch (\ImagickException $e) {
                    throw new DesignerException(Images::ERR_BROKEN);
                }
                @unlink($ppm);
                Images::toSrgb($im);
                $im->stripImage();
                self::page($im, $dir, $previewId, $n, $written);
            } finally {
                $im->clear();
            }
        });
        return $count;
    }

    /**
     * Одна сторінка: view (вписати в 2000) і card (вписати в 800 — з view ще до знака), кожна зі своїм знаком.
     * $im змінюється (стає view)
     */
    private static function page(\Imagick $im, string $dir, int $previewId, int $n, array &$written): void
    {
        Images::fitInto($im, self::VIEW_SIDE);
        $card = clone $im;
        try {
            Images::fitInto($card, self::CARD_SIDE);
            foreach (['view' => $im, 'card' => $card] as $size => $img) {
                self::watermark($img);
                $path = "$dir/$previewId-p$n-$size.webp";
                $written[] = $path;
                Images::writeWebp($img, $path, self::WEBP_QUALITY);
            }
        } finally {
            $card->clear();
        }
    }

    /**
     * Напівпрозорий напис WATERMARK під кутом, «цеглинками» по всій площі. Розмір шрифту і крок — від розміру картинки,
     * тож на view і card знак займає ту саму частку. Напис малюється один раз («штамп») і накладається в кожну точку решітки
     */
    private static function watermark(\Imagick $im): void
    {
        $w = $im->getImageWidth();
        $h = $im->getImageHeight();
        $fs = max(10, (int)round(sqrt($w * $h) / self::MARK_FONT_DIV));
        [$stamp, $textW] = self::stamp($fs);
        $sw = $stamp->getImageWidth();
        $sh = $stamp->getImageHeight();

        // Решітка в повернутих осях: u — уздовж напису, v — поперек; непарні ряди зсунуті на півкроку
        $a = deg2rad(self::MARK_ANGLE);
        [$ux, $uy, $vx, $vy] = [cos($a), -sin($a), sin($a), cos($a)];
        $stepU = $textW + 2 * $fs;
        $stepV = 3.6 * $fs;
        $reach = hypot($w, $h) / 2 + max($sw, $sh);
        $nu = (int)ceil($reach / $stepU) + 1;
        $nv = (int)ceil($reach / $stepV);
        for ($j = -$nv; $j <= $nv; $j++) {
            for ($i = -$nu; $i <= $nu; $i++) {
                $u = ($i + ($j & 1) / 2) * $stepU;
                $v = $j * $stepV;
                $cx = $w / 2 + $u * $ux + $v * $vx;
                $cy = $h / 2 + $u * $uy + $v * $vy;
                if ($cx < -$sw / 2 || $cx > $w + $sw / 2 || $cy < -$sh / 2 || $cy > $h + $sh / 2) continue;
                $im->compositeImage($stamp, \Imagick::COMPOSITE_OVER, (int)round($cx - $sw / 2), (int)round($cy - $sh / 2));
            }
        }
    }

    /**
     * Повернутий напис на прозорому тлі: темний з тонким світлим контуром (видно і на світлому, і на темному),
     * прозорість — множенням альфи всього штампа, щоб контур і заливка не накладалися плямами
     * @return array{0:\Imagick, 1:float} штамп і ширина напису до повороту
     */
    private static function stamp(int $fs): array
    {
        if (!isset(self::$stamps[$fs])) {
            if (!is_file(self::FONT)) throw new \RuntimeException('Немає шрифту водяного знака ' . self::FONT);
            $draw = new \ImagickDraw();
            $draw->setFont(self::FONT);
            $draw->setFontSize($fs);
            $draw->setFillColor('rgb(20,20,20)');
            $draw->setStrokeColor('white');
            $draw->setStrokeWidth(max(1, $fs / 18));
            $draw->setStrokeAntialias(true);
            $draw->setTextAntialias(true);

            $s = new \Imagick();
            $s->newImage(1, 1, 'none');
            $m = $s->queryFontMetrics($draw, self::WATERMARK);
            $pad = (int)ceil($fs / 4);
            $s->newImage((int)ceil($m['textWidth']) + 2 * $pad, (int)ceil($m['textHeight']) + 2 * $pad, 'none');
            $s->annotateImage($draw, $pad, $pad + $m['ascender'], 0, self::WATERMARK);
            $s->rotateImage('none', -self::MARK_ANGLE);
            $s->trimImage(0);
            $s->setImagePage(0, 0, 0, 0);
            $s->evaluateImage(\Imagick::EVALUATE_MULTIPLY, self::MARK_OPACITY, \Imagick::CHANNEL_ALPHA);
            self::$stamps[$fs] = [$s, (float)$m['textWidth']];
        }
        return self::$stamps[$fs];
    }

    /**
     * Число сторінок і розміри (MediaBox, пункти) для вибору dpi. На gs ≥ 9.50 — pdfpagecount (~0.05 с); на старших
     * (prod 9.27: під -dSAFER pdfpagecount не читає файл, а --permit-file-read ще немає) і якщо gs не відповів числом —
     * ping Imagick на 10 dpi (повільніше: gs проходить кожну сторінку, але з мізерним растром)
     * @return array{0:int, 1:array<int, array{0:float, 1:float}>} число сторінок і [ширина, висота] перших сторінок
     */
    private static function pdfPages(string $src): array
    {
        $res = self::gsCounts() ? self::pdfPagesGs($src) : null;
        if ($res !== null) return $res;

        $ping = new \Imagick();
        try {
            $ping->setResolution(10, 10);
            $ping->pingImage("pdf:$src");
            $sizes = [];
            foreach ($ping as $frame) {
                $sizes[] = [$frame->getImageWidth() * 7.2, $frame->getImageHeight() * 7.2];
            }
            return [count($sizes), $sizes];
        } catch (\ImagickException $e) {
            return [0, []];
        } finally {
            $ping->clear();
        }
    }

    /** @return array{0:int, 1:array<int, array{0:float, 1:float}>}|null null — gs не дав зрозумілої відповіді (тоді — ping) */
    private static function pdfPagesGs(string $src): ?array
    {
        $limit = Settings::previewMaxPages() + 1;
        // «COUNT n», далі «BOX ширина висота» перших $limit сторінок (більше не треба). Мітки — бо скарги
        // PDF-інтерпретатора на пошкоджений файл («**** Error…») gs теж пише в stdout
        $ps = 'F (r) file runpdfbegin (COUNT ) print pdfpagecount dup = Limit 2 copy gt { exch } if pop 1 exch 1 exch {'
            . ' (BOX ) print pdfgetpage /MediaBox pget { { oforce } forall 3 -1 roll sub abs 3 1 roll exch sub abs =only ( ) print = }'
            . ' { (0 0) = } ifelse } for quit';
        // -dSAFER завжди; з 9.50 файл, не названий як вхідний, читається лише з --permit-file-read
        $args = [
            self::gs(), '-q', '-dSAFER', '-dNODISPLAY', '-dBATCH', '-dNOPAUSE', '-dNOPROMPT',
            "--permit-file-read=$src", "-sF=$src", "-dLimit=$limit", '-c', $ps,
        ];

        $out = '';
        $code = self::run($args, self::GS_COUNT_TIMEOUT, null, $out);
        // gs не відкрив PDF — його не відрендерить і ping (той самий gs), тож одразу «пошкоджений»
        if ($code !== 0) return [0, []];
        if (!preg_match('/^COUNT (\d+)$/m', $out, $m)) return null;
        $count = (int)$m[1];
        preg_match_all('/^BOX ([\d.]+) ([\d.]+)$/m', $out, $boxes, PREG_SET_ORDER);
        $sizes = [];
        foreach (array_slice($boxes, 0, $limit) as $b) $sizes[] = [(float)$b[1], (float)$b[2]];
        return [$count, $sizes];
    }

    /**
     * PDF_DPI, але не більше, ніж треба, щоб найбільша сторінка вийшла 2000 px по довшій стороні
     * (дробова, до сотих униз — gs приймає -r16.93)
     */
    private static function pdfDpi(array $sizes): float
    {
        $long = 0.0;
        foreach ($sizes as [$w, $h]) $long = max($long, $w, $h);
        if ($long <= 0) return self::PDF_DPI;
        return max(1.0, min((float)self::PDF_DPI, floor(self::VIEW_SIDE * 72 / $long * 100) / 100));
    }

    /**
     * Рендер сторінок 1…$pages одним процесом gs (ppmraw) у приватну тимчасову папку. Сторінку n gs дописав, коли з'явилась
     * n+1 або gs завершився — тоді $onPage(n, шлях) обробляє її, поки gs рендерить далі. Довше $gsTimeout — процес убивається
     */
    private static function renderPdf(string $src, int $pages, float $dpi, callable $onPage): void
    {
        $tmp = sys_get_temp_dir() . '/design-pdf-' . bin2hex(random_bytes(8));
        if (!@mkdir($tmp, 0700)) throw new \RuntimeException("Не вдалося створити тимчасову папку $tmp");
        chmod($tmp, 0700);
        $file = fn(int $n) => sprintf('%s/p-%03d.ppm', $tmp, $n);
        try {
            $args = [
                self::gs(), '-q', '-dSAFER', '-dBATCH', '-dNOPAUSE', '-dNOPROMPT', '-sDEVICE=ppmraw', '-r' . round($dpi, 2),
                '-dTextAlphaBits=4', '-dGraphicsAlphaBits=4', '-dFirstPage=1', "-dLastPage=$pages",
                "-sOutputFile=$tmp/p-%03d.ppm", '-f', $src,
            ];
            $next = 1;
            $code = self::run($args, self::$gsTimeout, function () use (&$next, $pages, $file, $onPage) {
                while ($next < $pages && is_file($file($next + 1))) {
                    $onPage($next, $file($next));
                    $next++;
                }
            });
            if ($code !== 0) throw new DesignerException(Images::ERR_BROKEN);
            for (; $next <= $pages; $next++) {
                if (!is_file($file($next))) throw new DesignerException(Images::ERR_BROKEN);
                $onPage($next, $file($next));
            }
        } finally {
            foreach (glob("$tmp/*") ?: [] as $f) @unlink($f);
            @rmdir($tmp);
        }
    }

    /**
     * Запуск gs без оболонки (масив аргументів), stdin — /dev/null, stdout — у $out (не більше 64 КБ),
     * stderr — у журнал при збої. $tick викликається між читаннями (≈ кожні 50 мс).
     * @return int код завершення
     * @throws DesignerException ERR_PDF_SLOW, якщо процес не вклався в $timeout (його вбито)
     */
    private static function run(array $args, int $timeout, ?callable $tick, ?string &$out = null): int
    {
        if (!function_exists('proc_open')) throw new \RuntimeException('proc_open вимкнено — PDF-прев\'ю неможливі');
        $pipes = [];
        $proc = proc_open($args, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) throw new \RuntimeException('Не вдалося запустити ' . $args[0]);
        $out = '';
        $err = '';
        $deadline = microtime(true) + $timeout;
        try {
            foreach ($pipes as $p) stream_set_blocking($p, false);
            $open = [1 => $pipes[1], 2 => $pipes[2]];
            while ($open) {
                if (microtime(true) > $deadline) {
                    proc_terminate($proc, 9);
                    error_log('Design\\PreviewImages: gs не вклався в ' . $timeout . ' с, зупинено');
                    throw new DesignerException(self::ERR_PDF_SLOW);
                }
                $read = array_values($open);
                $w = $e = null;
                if (@stream_select($read, $w, $e, 0, 50000) > 0) {
                    foreach ($open as $k => $p) {
                        if (!in_array($p, $read, true)) continue;
                        $chunk = fread($p, 8192);
                        if ($chunk === false || ($chunk === '' && feof($p))) {
                            unset($open[$k]);
                            continue;
                        }
                        if ($k === 1 && strlen($out) < 65536) $out .= $chunk;
                        if ($k === 2 && strlen($err) < self::GS_ERR_BYTES) $err .= $chunk;
                    }
                }
                if ($tick) $tick();
            }
            foreach ($pipes as $p) fclose($p);
            $pipes = [];
            $code = proc_close($proc);
            $proc = null;
            if ($code !== 0) {
                error_log('Design\\PreviewImages: gs завершився з кодом ' . $code . ': ' . substr(trim($err), 0, self::GS_ERR_BYTES));
            }
            return $code;
        } finally {
            foreach ($pipes as $p) @fclose($p);
            if (is_resource($proc)) {
                proc_terminate($proc, 9);
                proc_close($proc);
            }
        }
    }

    /** Абсолютний шлях до gs: /usr/bin/gs, /usr/local/bin/gs або з PATH */
    private static function gs(): string
    {
        if (self::$gs === null) {
            $dirs = array_merge(['/usr/bin', '/usr/local/bin'], explode(':', (string)getenv('PATH')));
            foreach ($dirs as $d) {
                if ($d !== '' && is_file("$d/gs") && is_executable("$d/gs")) {
                    self::$gs = "$d/gs";
                    break;
                }
            }
            if (self::$gs === null) throw new \RuntimeException('Не знайдено Ghostscript (gs) — PDF-прев\'ю неможливі');
        }
        return self::$gs;
    }

    /** Чи вміє цей gs порахувати сторінки під -dSAFER: лише з 9.50 (--permit-file-read); версія визначається раз */
    private static function gsCounts(): bool
    {
        if (self::$gsCounts === null) {
            $out = '';
            $code = self::run([self::gs(), '--version'], self::GS_COUNT_TIMEOUT, null, $out);
            self::$gsCounts = $code === 0 && preg_match('/^(\d+)\.(\d+)/', trim($out), $m) === 1
                && version_compare("$m[1].$m[2]", '9.50', '>=');
        }
        return self::$gsCounts;
    }
}
