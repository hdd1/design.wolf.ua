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
    /** Скільки чекати gs на весь PDF — підрахунок сторінок, рендер і обробку готових сторінок, с; static — тест ставить 0 */
    private static int $gsTimeout = 40;
    /** Скільки stderr gs тримати для журналу */
    private const GS_ERR_BYTES = 4096;
    /** Шлях до файлу, який віддаємо gs/Imagick: лише безпечні символи (жодних «[», «:», пробілів, лапок) */
    private const SAFE_PATH = '~^/[A-Za-z0-9._/-]+$~';

    private const FONT = __DIR__ . '/fonts/Manrope-SemiBold.ttf';
    /** Кут знака (проти годинникової), розмір шрифту = √(площа) / FONT_DIV, непрозорість напису */
    private const MARK_ANGLE = 30;
    private const MARK_FONT_DIV = 26;
    private const MARK_OPACITY = 0.28;

    private const ERR_TYPE = 'Підходять лише JPG, PNG або PDF';
    private const ERR_PAGES = "Забагато сторінок: понад %d. Максимум — %d. Розбийте на кілька прев'ю";
    private const ERR_PAGE_HUGE = "Сторінка завелика для прев'ю";
    private const ERR_PDF_SLOW = 'Не вдалося обробити PDF — спробуйте менший файл';

    /** @var array<int, array{0:\Imagick, 1:float}> готові «штампи» знака за розміром шрифту — у межах одного make() */
    private static array $stamps = [];
    private static ?string $gs = null;
    /** @var resource|null запущений gs і тимчасова папка рендеру — для аварійного прибирання (abandon) */
    private static $proc = null;
    private static ?string $tmp = null;

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
        $real = realpath($src);
        if ($real === false || !preg_match(self::SAFE_PATH, $real)) {
            throw new \RuntimeException("Небезпечний шлях до файлу прев'ю: $src");
        }
        $src = $real;
        // Якщо PHP впаде фатально (max_execution_time) посеред рендеру, finally не виконаються — тоді приберемо тут
        register_shutdown_function(static function () {
            self::abandon();
        });

        $written = [];
        $dirs = [];
        try {
            $head = (string)file_get_contents($src, false, null, 0, 1024);
            $image = strncmp($head, "\xFF\xD8\xFF", 3) === 0 || strncmp($head, "\x89PNG", 4) === 0;
            // %PDF- може стояти не на самому початку (сміття перед заголовком gs пропускає з попередженням)
            if (!$image && str_contains($head, '%PDF-')) {
                $pages = self::pdf($src, $dir, $previewId, $written, $dirs);
                return ['pages' => $pages, 'type' => 'pdf'];
            }
            $type = @getimagesize($src);
            if ($type === false) throw new DesignerException(Images::ERR_BROKEN);
            if ($type[2] !== IMAGETYPE_JPEG && $type[2] !== IMAGETYPE_PNG) throw new DesignerException(self::ERR_TYPE);

            // Пропорції не обмежуємо (довгий банер — нормальне прев'ю); JPEG декодується зменшеним,
            // але не менше 2000 по довшій стороні — далі лише вписування
            $im = Images::load($src, Images::check($src, $maxBytes, false), self::VIEW_SIDE, 1);
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

    /**
     * Рахує сторінки (не далі за ліміт+1), рендерить їх одним процесом gs і обробляє кожну, щойно gs її дописав.
     * Весь PDF — підрахунок, рендер, обробка — в межах $gsTimeout
     */
    private static function pdf(string $src, string $dir, int $previewId, array &$written, array &$dirs): int
    {
        Images::limitResources();
        $deadline = microtime(true) + self::$gsTimeout;
        $max = Settings::previewMaxPages();
        $sizes = self::pdfPages($src, $max + 1, $deadline);
        $count = count($sizes);
        if ($count === 0) throw new DesignerException(Images::ERR_BROKEN);
        if ($count > $max) throw new DesignerException(sprintf(self::ERR_PAGES, $max, $max));
        $dpi = self::pdfDpi($sizes);

        $dirs = Images::ensureDir($dir);
        self::renderPdf($src, $count, $dpi, $deadline, function (int $n, string $ppm) use ($dir, $previewId, &$written) {
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
     * Розміри сторінок (PageSize, пункти) — і цим їх число. Прохід gs на nullpage з мізерною роздільністю: хук EndPage
     * друкує «PAGE ширина висота» на кожну сторінку. PDF — названий вхідний файл (без оператора file, тож працює під
     * -dSAFER і на prod 9.27); -dLastPage=$limit — далі за ліміт gs не йде, хоч би скільки сторінок було у файлі.
     * Межа часу — та сама $deadline, що й для рендеру
     * @return array<int, array{0:float, 1:float}> не більше $limit записів
     */
    private static function pdfPages(string $src, int $limit, float $deadline): array
    {
        $hook = '<< /EndPage { exch pop dup 0 eq { (PAGE ) print currentpagedevice /PageSize get { =only ( ) print } forall'
            . ' () = flush } if 2 ne } >> setpagedevice';
        $args = [
            self::gs(), '-q', '-dSAFER', '-dBATCH', '-dNOPAUSE', '-dNOPROMPT', '-dNoUserUnit', '-sDEVICE=nullpage', '-r10',
            '-dFirstPage=1', "-dLastPage=$limit", '-c', $hook, '-f', $src,
        ];
        $out = '';
        if (self::run($args, $deadline, null, $out) !== 0) return [];
        // Скарги PDF-інтерпретатора («**** Error…») gs теж пише в stdout — беремо лише рядки з міткою
        preg_match_all('/^PAGE ([\d.]+) ([\d.]+) *$/m', $out, $m, PREG_SET_ORDER);
        $sizes = [];
        foreach (array_slice($m, 0, $limit) as $p) $sizes[] = [(float)$p[1], (float)$p[2]];
        return $sizes;
    }

    /**
     * PDF_DPI, але не більше, ніж треба, щоб найбільша сторінка вийшла 2000 px по довшій стороні
     * (дробова, до сотих униз — gs приймає -r16.93). Сторінка, якій треба менше 1 dpi (понад 144 000 pt ≈ 50 м), — відмова
     */
    private static function pdfDpi(array $sizes): float
    {
        $long = 0.0;
        foreach ($sizes as [$w, $h]) {
            if ($w <= 0 || $h <= 0) throw new DesignerException(Images::ERR_BROKEN);
            $long = max($long, $w, $h);
        }
        $fit = floor(self::VIEW_SIDE * 72 / $long * 100) / 100;
        if ($fit < 1) throw new DesignerException(self::ERR_PAGE_HUGE);
        return min((float)self::PDF_DPI, $fit);
    }

    /**
     * Рендер сторінок 1…$pages одним процесом gs (ppmraw) у приватну тимчасову папку. Сторінку n gs дописав, коли з'явилась
     * n+1 або gs завершився — тоді $onPage(n, шлях) обробляє її, поки gs рендерить далі. Після $deadline процес убивається
     */
    private static function renderPdf(string $src, int $pages, float $dpi, float $deadline, callable $onPage): void
    {
        $tmp = sys_get_temp_dir() . '/design-pdf-' . bin2hex(random_bytes(8));
        if (!@mkdir($tmp, 0700)) throw new \RuntimeException("Не вдалося створити тимчасову папку $tmp");
        self::$tmp = $tmp;
        chmod($tmp, 0700);
        $file = fn(int $n) => sprintf('%s/p-%03d.ppm', $tmp, $n);
        try {
            $args = [
                self::gs(), '-q', '-dSAFER', '-dBATCH', '-dNOPAUSE', '-dNOPROMPT', '-dNoUserUnit', '-sDEVICE=ppmraw',
                '-r' . round($dpi, 2), '-dTextAlphaBits=4', '-dGraphicsAlphaBits=4', '-dFirstPage=1', "-dLastPage=$pages",
                "-sOutputFile=$tmp/p-%03d.ppm", '-f', $src,
            ];
            $next = 1;
            $code = self::run($args, $deadline, function () use (&$next, $pages, $file, $onPage) {
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
            self::removeTmp($tmp);
            self::$tmp = null;
        }
    }

    private static function removeTmp(string $tmp): void
    {
        foreach (glob("$tmp/*") ?: [] as $f) @unlink($f);
        @rmdir($tmp);
    }

    /**
     * Запуск gs без оболонки (масив аргументів), stdin — /dev/null, stdout — у $out (не більше 64 КБ),
     * stderr — у журнал лише при збої. $tick викликається між читаннями (≈ кожні 50 мс).
     * @return int код завершення
     * @throws DesignerException ERR_PDF_SLOW, якщо процес не завершився до $deadline (його вбито)
     */
    private static function run(array $args, float $deadline, ?callable $tick, ?string &$out = null): int
    {
        if (!function_exists('proc_open')) throw new \RuntimeException('proc_open вимкнено — PDF-прев\'ю неможливі');
        $pipes = [];
        $proc = proc_open($args, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) throw new \RuntimeException('Не вдалося запустити ' . $args[0]);
        self::$proc = $proc;
        $out = '';
        $err = '';
        try {
            foreach ($pipes as $p) stream_set_blocking($p, false);
            $open = [1 => $pipes[1], 2 => $pipes[2]];
            while ($open) {
                if (microtime(true) > $deadline) {
                    proc_terminate($proc, 9);
                    error_log('Design\\PreviewImages: gs не вклався в ліміт часу, зупинено');
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
            self::$proc = null;
        }
    }

    /**
     * Аварійне прибирання після фатальної помилки PHP (max_execution_time тощо), коли finally не виконуються:
     * убити gs, що ще працює, і прибрати тимчасову папку. Реєструється на кожен make(); якщо все завершилось — нічого не робить
     */
    private static function abandon(): void
    {
        if (is_resource(self::$proc)) {
            @proc_terminate(self::$proc, 9);
            @proc_close(self::$proc);
        }
        self::$proc = null;
        if (self::$tmp !== null) self::removeTmp(self::$tmp);
        self::$tmp = null;
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
}
