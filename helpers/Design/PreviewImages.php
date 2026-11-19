<?php

namespace Design;

/**
 * Картинки прев'ю: JPG/PNG — одна сторінка, PDF — кожна сторінка окремо (Ghostscript напряму).
 * Кожна сторінка — {base}-p{n}-view.webp (вписати в 2000×2000) і -card.webp (вписати в 800×800), WebP q90,
 * base — id прев'ю або тимчасове «tmp-<16 hex>»;
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
    private const SAFE_PATH = '~^/[A-Za-z0-9._/-]+$~D';
    /** Тимчасова основа імен файлів (рендер до появи id) */
    public const TMP_BASE = '~^tmp-[0-9a-f]{16}$~D';
    /** «%PDF-» шукаємо лише в перших стільки байтах (заголовок цілком у них) */
    private const PDF_HEAD = 1023;
    /** Сигнатури картинок: такий файл ніколи не йде в gs, хоч би що було далі в перших байтах */
    private const IMAGE_MAGIC = ["\xFF\xD8\xFF", "\x89PNG", "II*\0", "MM\0*"];
    /** Другий, незалежний від PHP ліміт для gs (coreutils timeout): якщо воркер php-fpm уб'ють, gs не житиме вічно */
    private const TIMEOUT_BIN = '/usr/bin/timeout';

    private const FONT = __DIR__ . '/fonts/Manrope-SemiBold.ttf';
    /** Кут знака (проти годинникової), розмір шрифту = √(площа) / FONT_DIV, непрозорість напису */
    private const MARK_ANGLE = 30;
    private const MARK_FONT_DIV = 26;
    private const MARK_OPACITY = 0.28;

    private const ERR_TYPE = 'Підходять лише JPG, PNG, TIFF або PDF';
    private const ERR_PAGES = "Забагато сторінок: понад %d. Максимум — %d. Розбийте на кілька прев'ю";
    private const ERR_PAGE_HUGE = "Сторінка завелика для прев'ю";
    private const ERR_PAGE_RATIO = "Незвичні пропорції сторінки: вона надто вузька для прев'ю";
    private const ERR_PDF_SLOW = 'Не вдалося обробити PDF — спробуйте менший файл';

    /** @var array<int, array{0:\Imagick, 1:float}> готові «штампи» знака за розміром шрифту — у межах одного make() */
    private static array $stamps = [];
    private static ?string $gs = null;
    /** @var resource|null запущений gs і тимчасова папка рендеру — для аварійного прибирання (abandon) */
    private static $proc = null;
    private static ?string $tmp = null;
    private static bool $shutdownRegistered = false;

    /**
     * $base — початок імен файлів: id прев'ю або тимчасове «tmp-<16 hex>» (рендер до транзакції, див. Previews::add)
     * @return array{pages:int, type:string} type — image|pdf (як design_previews.source_type)
     * @throws DesignerException файл не підходить (текст для користувача); помилки запису летять як є.
     *         При будь-якій помилці створене цим викликом (файли, папки) прибирається.
     */
    public static function make(string $src, string $dir, int|string $base): array
    {
        if (is_string($base) && !preg_match(self::TMP_BASE, $base)) {
            throw new \InvalidArgumentException("Недопустиме ім'я файлів прев'ю: $base");
        }
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
        if (!self::$shutdownRegistered) {
            register_shutdown_function(static function () {
                self::abandon();
            });
            self::$shutdownRegistered = true;
        }

        $written = [];
        $dirs = [];
        try {
            $head = (string)file_get_contents($src, false, null, 0, self::PDF_HEAD);
            $image = false;
            foreach (self::IMAGE_MAGIC as $magic) $image = $image || str_starts_with($head, $magic);
            // «%PDF-» може стояти не на самому початку (сміття перед заголовком) — тоді gs отримає копію з цього місця:
            // gs сам вирішує «PDF чи PostScript», і файл з «%!PS…» попереду виконав би як PostScript
            $pdfAt = $image ? false : strpos($head, '%PDF-');
            if ($pdfAt !== false) {
                $pages = self::pdf($src, $pdfAt, $dir, $base, $written, $dirs);
                return ['pages' => $pages, 'type' => 'pdf'];
            }
            $type = @getimagesize($src);
            if ($type === false) throw new DesignerException(Images::ERR_BROKEN);
            if (!in_array($type[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_TIFF_II, IMAGETYPE_TIFF_MM], true)) {
                throw new DesignerException(self::ERR_TYPE);
            }

            // Пропорції не обмежуємо (довгий банер — нормальне прев'ю); TIFF — лише перша сторінка;
            // JPEG декодується зменшеним, але не менше 2000 по довшій стороні — далі лише вписування
            $im = Images::load($src, Images::check($src, $maxBytes, false, true), self::VIEW_SIDE, 1);
            try {
                $dirs = Images::ensureDir($dir);
                self::page($im, $dir, $base, 1, $written);
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
     * Весь PDF — підрахунок, рендер, обробка — в межах $gsTimeout. gs бачить лише файл, що починається рівно з «%PDF-»:
     * якщо заголовок на зсуві $pdfAt > 0 — приватну копію з цього місця в тимчасовій папці (0700)
     */
    private static function pdf(string $src, int $pdfAt, string $dir, int|string $base, array &$written, array &$dirs): int
    {
        Images::limitResources();
        $deadline = microtime(true) + self::$gsTimeout;
        $tmp = sys_get_temp_dir() . '/design-pdf-' . bin2hex(random_bytes(8));
        if (!@mkdir($tmp, 0700)) throw new \RuntimeException("Не вдалося створити тимчасову папку $tmp");
        self::$tmp = $tmp;
        try {
            chmod($tmp, 0700);
            $pdf = $pdfAt === 0 ? $src : self::copyFrom($src, $pdfAt, "$tmp/source.pdf");
            $max = Settings::previewMaxPages();
            $sizes = self::pdfPages($pdf, $max + 1, $deadline);
            $count = count($sizes);
            if ($count === 0) throw new DesignerException(Images::ERR_BROKEN);
            if ($count > $max) throw new DesignerException(sprintf(self::ERR_PAGES, $max, $max));
            $dpi = self::pdfDpi($sizes);

            $dirs = Images::ensureDir($dir);
            self::renderPdf($pdf, $tmp, $count, $dpi, $deadline, self::pageFromPpm($dir, $base, $written));
            return $count;
        } finally {
            self::removeTmp($tmp);
            self::$tmp = null;
        }
    }

    /** Копія $src від байта $offset — щоб файл для gs починався рівно з «%PDF-» */
    private static function copyFrom(string $src, int $offset, string $dst): string
    {
        $in = fopen($src, 'rb');
        $out = fopen($dst, 'xb');
        if ($in === false || $out === false) throw new \RuntimeException("Не вдалося скопіювати $src");
        try {
            if (stream_copy_to_stream($in, $out, null, $offset) === false) throw new \RuntimeException("Не вдалося скопіювати $src");
        } finally {
            fclose($in);
            fclose($out);
        }
        return $dst;
    }

    /** Обробник готової сторінки-PPM від gs: прочитати, видалити, sRGB без метаданих, view/card зі знаком */
    private static function pageFromPpm(string $dir, int|string $base, array &$written): callable
    {
        return function (int $n, string $ppm) use ($dir, $base, &$written) {
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
                self::page($im, $dir, $base, $n, $written);
            } finally {
                $im->clear();
            }
        };
    }

    /**
     * Одна сторінка: view (вписати в 2000) і card (вписати в 800 — з view ще до знака), кожна зі своїм знаком.
     * $im змінюється (стає view)
     */
    private static function page(\Imagick $im, string $dir, int|string $base, int $n, array &$written): void
    {
        Images::fitInto($im, self::VIEW_SIDE);
        $card = clone $im;
        try {
            Images::fitInto($card, self::CARD_SIDE);
            foreach (['view' => $im, 'card' => $card] as $size => $img) {
                self::watermark($img);
                $path = "$dir/$base-p$n-$size.webp";
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
        $dpi = min((float)self::PDF_DPI, $fit);
        // Коротша сторона менше пікселя (напр. 143 000×1 pt) — gs такого не відрендерить
        foreach ($sizes as [$w, $h]) {
            if (min($w, $h) * $dpi / 72 < 1) throw new DesignerException(self::ERR_PAGE_RATIO);
        }
        return $dpi;
    }

    /**
     * Рендер сторінок 1…$pages одним процесом gs (ppmraw) у приватну тимчасову папку $tmp. Сторінку n gs дописав, коли з'явилась
     * n+1 або gs завершився — тоді $onPage(n, шлях) обробляє її, поки gs рендерить далі. Після $deadline процес убивається
     */
    private static function renderPdf(string $src, string $tmp, int $pages, float $dpi, float $deadline, callable $onPage): void
    {
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
            foreach (glob("$tmp/p-*.ppm") ?: [] as $f) @unlink($f);
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
        if (is_file(self::TIMEOUT_BIN) && is_executable(self::TIMEOUT_BIN)) {
            // Із запасом після нашого ліміту: спрацює, лише якщо PHP уже не стежить (воркер убито)
            $secs = (int)ceil(max(0, $deadline - microtime(true))) + 5;
            $args = array_merge([self::TIMEOUT_BIN, '--signal=KILL', (string)$secs], $args);
        }
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
                    foreach ($pipes as $p) fclose($p);
                    $pipes = [];
                    self::kill($proc);
                    $proc = null;
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
            foreach ($pipes as $p) {
                if (is_resource($p)) fclose($p);
            }
            if (is_resource($proc)) self::kill($proc);
            self::$proc = null;
        }
    }

    /**
     * Зупинити gs і дочекатися. Під обгорткою timeout gs — її дочірній процес у її ж групі (timeout — лідер групи):
     * KILL усій групі (posix), інакше TERM обгортці — вона передасть його gs; наостанок KILL тому, що лишилось
     * @param resource $proc
     */
    private static function kill($proc): void
    {
        $status = @proc_get_status($proc);
        if ($status && $status['running']) {
            if (function_exists('posix_kill')) @posix_kill(-$status['pid'], 9);
            @proc_terminate($proc, 15);
            for ($i = 0; $i < 40 && (@proc_get_status($proc)['running'] ?? false); $i++) usleep(50000);
            if (@proc_get_status($proc)['running'] ?? false) @proc_terminate($proc, 9);
        }
        @proc_close($proc);
    }

    /**
     * Аварійне прибирання після фатальної помилки PHP (max_execution_time тощо), коли finally не виконуються:
     * убити gs, що ще працює, і прибрати тимчасову папку. Реєструється на кожен make(); якщо все завершилось — нічого не робить
     */
    private static function abandon(): void
    {
        if (is_resource(self::$proc)) self::kill(self::$proc);
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
