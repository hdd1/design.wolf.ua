<?php

use Design\DesignerException;
use Design\Images;
use Design\PreviewImages;
use Design\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Картинки прев'ю: JPG/PNG — одна сторінка, PDF — посторінково через gs; на кожній картинці водяний знак.
 * Тестові PDF генеруються тут же (Imagick); великі PDF для замірів часу — поза репозиторієм (див. звіт задачі 3).
 */
final class PreviewImagesTest extends TestCase
{
    private string $tmp;
    private string $dir;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/design-preview-' . bin2hex(random_bytes(4));
        $this->dir = $this->tmp . '/out';
        mkdir($this->dir, 0777, true);
        Settings::reset();
        // Збій gs (пошкоджений PDF, ліміт часу) пишеться в журнал — у тестах це очікувано, у вивід не пускаємо
        $this->errorLog = (string)ini_set('error_log', '/dev/null');
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->errorLog);
        self::rmTree($this->tmp);
    }

    private string $errorLog = '';

    /**
     * Мінімальний PDF без Imagick: $pages сторінок w×h pt з прямокутником; $prefix — байти перед «%PDF-»;
     * $content — свій вміст сторінки (одна спільна сторінкова програма). Швидко навіть на тисячі сторінок
     */
    private function minipdf(string $name, int $pages, float $w = 595, float $h = 842, string $prefix = '', ?string $content = null): string
    {
        $content ??= "0 0 1 rg 10 10 " . ($w / 2) . " " . ($h / 2) . " re f";
        $objs = [1 => '<< /Type /Catalog /Pages 2 0 R >>', 3 => '<< /Length ' . strlen($content) . " >>\nstream\n$content\nendstream"];
        $kids = [];
        for ($i = 0; $i < $pages; $i++) {
            $kids[] = (4 + $i) . ' 0 R';
            $objs[4 + $i] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 $w $h] /Contents 3 0 R >>";
        }
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . "] /Count $pages >>";
        ksort($objs);
        $pdf = "%PDF-1.4\n";
        $off = [];
        foreach ($objs as $id => $o) {
            $off[$id] = strlen($pdf);
            $pdf .= "$id 0 obj\n$o\nendobj\n";
        }
        $xref = strlen($pdf);
        $n = count($objs) + 1;
        $pdf .= "xref\n0 $n\n0000000000 65535 f \n";
        for ($i = 1; $i < $n; $i++) $pdf .= sprintf("%010d 00000 n \n", $off[$i]);
        $pdf .= "trailer\n<< /Size $n /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
        $path = "$this->tmp/$name";
        file_put_contents($path, $prefix . $pdf);
        return $path;
    }

    private static function tooManyPages(): string
    {
        $max = Settings::previewMaxPages();
        return "Забагато сторінок: понад $max. Максимум — $max. Розбийте на кілька прев'ю";
    }

    private static function rmTree(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (array_diff(scandir($path), ['.', '..']) as $f) self::rmTree("$path/$f");
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }

    /** Картинка w×h заданого кольору (або псевдо-картинки) у форматі jpeg|png|gif */
    private function image(int $w, int $h, string $format, string $name, string $fill = 'white', ?callable $prep = null): string
    {
        $im = new Imagick();
        $im->newPseudoImage($w, $h, str_contains($fill, ':') ? $fill : "xc:$fill");
        $im->setImageFormat($format);
        if ($prep) $prep($im);
        $path = "$this->tmp/$name";
        $im->writeImage("$format:$path");
        $im->clear();
        return $path;
    }

    /**
     * PDF з $pages сторінок w×h пунктів (растр 72 dpi усередині); $colorspace — CMYK, щоб перевірити переведення в sRGB
     */
    private function pdf(int $pages, string $name, int $w = 595, int $h = 842, string $fill = 'white', ?int $colorspace = null): string
    {
        $doc = new Imagick();
        for ($i = 0; $i < $pages; $i++) {
            $p = new Imagick();
            $p->newImage($w, $h, $fill);
            if ($colorspace !== null) $p->transformImageColorspace($colorspace);
            $p->setImageFormat('pdf');
            $p->setImageResolution(72, 72);
            $p->setImageUnits(Imagick::RESOLUTION_PIXELSPERINCH);
            $doc->addImage($p);
            $p->clear();
        }
        $path = "$this->tmp/$name";
        $doc->writeImages("pdf:$path", true);
        $doc->clear();
        return $path;
    }

    /** @return array{0:int,1:int,2:string} ширина, висота, формат */
    private static function info(string $path): array
    {
        $im = new Imagick($path);
        return [$im->getImageWidth(), $im->getImageHeight(), $im->getImageFormat()];
    }

    private function files(?string $dir = null): array
    {
        $list = array_values(array_diff(scandir($dir ?? $this->dir), ['.', '..']));
        sort($list);
        return $list;
    }

    private function assertRejected(string $message, callable $call): void
    {
        try {
            $call();
            $this->fail('Очікувався DesignerException');
        } catch (DesignerException $e) {
            $this->assertSame($message, $e->getMessage());
        }
        $this->assertSame([], $this->files(), 'після відмови папка має бути порожня');
    }

    /**
     * Знак на білій картинці: у кожній з 3×3 клітинок є не білі пікселі (візерунок по всій площі),
     * але навіть найтемніший піксель світліший за середньо-сірий (знак напівпрозорий — макет видно)
     */
    private function assertWatermarkedWhite(string $path): void
    {
        $im = new Imagick($path);
        $w = $im->getImageWidth();
        $h = $im->getImageHeight();
        $min = 1.0;
        for ($cy = 0; $cy < 3; $cy++) {
            for ($cx = 0; $cx < 3; $cx++) {
                $cell = clone $im;
                $cell->cropImage(intdiv($w, 3), intdiv($h, 3), $cx * intdiv($w, 3), $cy * intdiv($h, 3));
                $range = $cell->getImageChannelRange(Imagick::CHANNEL_RED);
                $quantum = $im->getQuantumRange()['quantumRangeLong'];
                $this->assertLessThan(0.97, $range['minima'] / $quantum, "клітинка $cx,$cy у $path без знака");
                $min = min($min, $range['minima'] / $quantum);
                $cell->clear();
            }
        }
        $this->assertGreaterThan(0.5, $min, "знак у $path надто темний — має бути напівпрозорим");
        $im->clear();
    }

    public function test_jpg_and_png_make_one_page_with_both_sizes_watermarked(): void
    {
        foreach (['jpeg' => 'jpg', 'png' => 'png'] as $format => $ext) {
            $id = $ext === 'jpg' ? 7 : 8;
            $src = $this->image(3000, 2000, $format, "src.$ext");

            $this->assertSame(['pages' => 1, 'type' => 'image'], PreviewImages::make($src, $this->dir, $id));
            $this->assertSame([2000, 1333, 'WEBP'], self::info("$this->dir/$id-p1-view.webp"));
            $this->assertSame([800, 533, 'WEBP'], self::info("$this->dir/$id-p1-card.webp"));
            $this->assertWatermarkedWhite("$this->dir/$id-p1-view.webp");
            $this->assertWatermarkedWhite("$this->dir/$id-p1-card.webp");
        }
        $this->assertSame(['7-p1-card.webp', '7-p1-view.webp', '8-p1-card.webp', '8-p1-view.webp'], $this->files(),
            'оригінал прев\'ю не зберігається');
    }

    public function test_small_image_not_upscaled(): void
    {
        $src = $this->image(600, 400, 'png', 'small.png');
        PreviewImages::make($src, $this->dir, 3);
        $this->assertSame([600, 400, 'WEBP'], self::info("$this->dir/3-p1-view.webp"));
        $this->assertSame([600, 400, 'WEBP'], self::info("$this->dir/3-p1-card.webp"));
    }

    /** Розмір і крок знака — від розміру картинки: частка площі під знаком на view і card однакова */
    public function test_watermark_scales_with_image(): void
    {
        $src = $this->image(3000, 2000, 'png', 'w.png');
        PreviewImages::make($src, $this->dir, 4);
        $mean = fn(string $f) => (new Imagick("$this->dir/$f"))->getImageChannelMean(Imagick::CHANNEL_RED)['mean'] / 65535;
        $view = 1 - $mean('4-p1-view.webp');
        $card = 1 - $mean('4-p1-card.webp');
        $this->assertGreaterThan(0.005, $view);
        // Знак фіксованого розміру в пікселях укрив би картку в (2000/800)² ≈ 6 разів щільніше
        $this->assertEqualsWithDelta(1.0, $card / $view, 0.35, sprintf('затемнення view %.4f, card %.4f', $view, $card));
    }

    public function test_pdf_three_pages(): void
    {
        $src = $this->pdf(3, 'three.pdf');

        $this->assertSame(['pages' => 3, 'type' => 'pdf'], PreviewImages::make($src, $this->dir, 12));
        $this->assertSame([
            '12-p1-card.webp', '12-p1-view.webp', '12-p2-card.webp', '12-p2-view.webp', '12-p3-card.webp', '12-p3-view.webp',
        ], $this->files());
        // A4 (595×842 pt) при 100 dpi = 826×1169 (± округлення gs); картка вписана в 800×800
        [$w, $h, $f] = self::info("$this->dir/12-p2-view.webp");
        $this->assertSame('WEBP', $f);
        $this->assertEqualsWithDelta(826, $w, 2);
        $this->assertEqualsWithDelta(1169, $h, 2);
        [$cw, $ch] = self::info("$this->dir/12-p2-card.webp");
        $this->assertSame(800, $ch);
        $this->assertEqualsWithDelta(565, $cw, 2);
        $this->assertWatermarkedWhite("$this->dir/12-p3-view.webp");
        $this->assertWatermarkedWhite("$this->dir/12-p3-card.webp");
    }

    /** Величезна сторінка (плакат 3×1 м) рендериться з меншою роздільністю — не більше 2000 px по довшій стороні */
    public function test_huge_pdf_page_rendered_at_lower_dpi(): void
    {
        $src = $this->pdf(1, 'poster.pdf', 8504, 2835);   // 3000×1000 мм у пунктах
        PreviewImages::make($src, $this->dir, 13);
        [$w, $h] = self::info("$this->dir/13-p1-view.webp");
        $this->assertLessThanOrEqual(2000, $w);
        $this->assertGreaterThan(1990, $w);
        $this->assertEqualsWithDelta($w / 3, $h, 3);
    }

    /** Сторінка, якій для 2000 px треба менше 1 dpi (200 000 pt), — відмова до рендеру, а не надія на збій gs */
    public function test_rejects_gigantic_pdf_page(): void
    {
        $src = $this->minipdf('giant.pdf', 1, 200000, 200000);
        $t = microtime(true);
        $this->assertRejected("Сторінка завелика для прев'ю", fn() => PreviewImages::make($src, $this->dir, 1));
        $this->assertLessThan(10, microtime(true) - $t);
    }

    /** Довгий банер (пропорції 70:1) — нормальне прев'ю: межа пропорцій портфоліо тут не діє */
    public function test_long_banner_pdf_and_png_accepted(): void
    {
        PreviewImages::make($this->minipdf('banner.pdf', 1, 14000, 200), $this->dir, 16);
        [$w, $h] = self::info("$this->dir/16-p1-view.webp");
        $this->assertLessThanOrEqual(2000, $w);
        $this->assertGreaterThan(1990, $w);
        $this->assertEqualsWithDelta(28, $h, 2);

        PreviewImages::make($this->image(4000, 100, 'png', 'banner.png'), $this->dir, 17);
        $this->assertSame([2000, 50, 'WEBP'], self::info("$this->dir/17-p1-view.webp"));
        $this->assertSame([800, 20, 'WEBP'], self::info("$this->dir/17-p1-card.webp"));
    }

    public function test_rejects_31_pages(): void
    {
        $src = $this->pdf(Settings::previewMaxPages() + 1, 'many.pdf', 40, 40);
        $this->assertRejected(self::tooManyPages(), fn() => PreviewImages::make($src, $this->dir, 1));
    }

    /** 15 000 сторінок: gs рахує не далі за ліміт+1, тож відмова швидка, хоч би скільки сторінок було у файлі */
    public function test_rejects_15000_pages_fast(): void
    {
        $src = $this->minipdf('p15000.pdf', 15000);
        $t = microtime(true);
        $this->assertRejected(self::tooManyPages(), fn() => PreviewImages::make($src, $this->dir, 1));
        $this->assertLessThan(10, microtime(true) - $t, 'підрахунок має зупинятися на ліміті');
    }

    /** Рівно максимум сторінок — приймається */
    public function test_accepts_max_pages(): void
    {
        $max = Settings::previewMaxPages();
        $this->assertSame(['pages' => $max, 'type' => 'pdf'], PreviewImages::make($this->minipdf('max.pdf', $max, 100, 100), $this->dir, 18));
        $this->assertCount(2 * $max, $this->files());
    }

    /** «%PDF-» не на самому початку (у перших 1023 байтах) — теж PDF; межа: зсув 1018 — так, 1019 — ні */
    public function test_pdf_header_after_garbage(): void
    {
        $src = $this->minipdf('junk.pdf', 2, 200, 100, str_repeat('x', 500) . "\n");
        $this->assertSame(['pages' => 2, 'type' => 'pdf'], PreviewImages::make($src, $this->dir, 19));

        $at1018 = $this->minipdf('at1018.pdf', 1, 200, 100, str_repeat('x', 1018));
        $this->assertSame(['pages' => 1, 'type' => 'pdf'], PreviewImages::make($at1018, $this->dir, 20));
        foreach ($this->files() as $f) unlink("$this->dir/$f");

        $at1019 = $this->minipdf('at1019.pdf', 1, 200, 100, str_repeat('x', 1019));
        $this->assertRejected(Images::ERR_BROKEN, fn() => PreviewImages::make($at1019, $this->dir, 21));
    }

    /**
     * gs сам вирішує «PDF чи PostScript»: файл, що починається з «%!PS» чи «%%», він виконав би як PostScript, навіть
     * якщо далі є «%PDF-». Тому gs бачить лише копію від «%PDF-». Перевірка: PostScript попереду ніколи не виконується
     */
    public function test_postscript_before_pdf_header_never_executed(): void
    {
        // a) PostScript заливає сторінку червоним, далі справжній PDF (синій прямокутник у лівому нижньому куті на білому).
        //    Виконайся PS — верхній правий кут прев'ю був би червоним
        $red = "%!PS-Adobe-3.0\n1 0 0 setrgbcolor clippath fill showpage\n%%EOF\n";
        $src = $this->minipdf('ps-red.pdf', 1, 400, 400, $red);
        $this->assertSame(['pages' => 1, 'type' => 'pdf'], PreviewImages::make($src, $this->dir, 30));
        $im = new Imagick("$this->dir/30-p1-view.webp");
        $im->cropImage(intdiv($im->getImageWidth(), 4), intdiv($im->getImageHeight(), 4), intdiv($im->getImageWidth() * 3, 4), 0);
        $this->assertGreaterThan(0.8, $im->getImageChannelMean(Imagick::CHANNEL_GREEN)['mean'] / 65535, 'PostScript виконався');
        foreach ($this->files() as $f) unlink("$this->dir/$f");

        // b) Знахідка рев'ю: «%!PS … %%PDF-1.4 showpage» — gs отримує лише «%PDF-1.4 showpage» → пошкоджений PDF
        $probe = "$this->tmp/review.pdf";
        file_put_contents($probe, "%!PS\n(PS-EXECUTED) = flush\n%%PDF-1.4\nshowpage\n");
        $this->assertRejected(Images::ERR_BROKEN, fn() => PreviewImages::make($probe, $this->dir, 31));

        // c) «%%»-коментар і нескінченний цикл PostScript попереду: виконайся він — спрацював би ліміт часу
        $loop = $this->minipdf('ps-loop.pdf', 1, 400, 400, "%%Title: x\n{ } loop\n");
        $t = microtime(true);
        $this->assertSame(['pages' => 1, 'type' => 'pdf'], PreviewImages::make($loop, $this->dir, 32));
        $this->assertLessThan(10, microtime(true) - $t);
    }

    /** Сторінка 143 000×1 pt: коротша сторона менше пікселя — зрозуміла відмова, а не «пошкоджений» */
    public function test_rejects_needle_pdf_page(): void
    {
        $src = $this->minipdf('needle.pdf', 1, 143000, 1);
        $this->assertRejected("Незвичні пропорції сторінки: вона надто вузька для прев'ю", fn() => PreviewImages::make($src, $this->dir, 1));
    }

    /** TIFF (рішення власника 2026-11-20): за сигнатурою, ті самі межі, що й JPG/PNG, без межі пропорцій */
    public function test_tiff_preview(): void
    {
        $src = $this->image(3000, 2000, 'tiff', 'photo.dat', 'gradient:red-blue');     // розширення не важить
        $this->assertSame(['pages' => 1, 'type' => 'image'], PreviewImages::make($src, $this->dir, 40));
        $this->assertSame([2000, 1333, 'WEBP'], self::info("$this->dir/40-p1-view.webp"));
        $this->assertSame([800, 533, 'WEBP'], self::info("$this->dir/40-p1-card.webp"));

        // Big-endian (MM) теж
        $mm = $this->image(400, 300, 'tiff', 'mm.tif', 'white', fn(Imagick $im) => $im->setOption('tiff:endian', 'msb'));
        $this->assertSame("MM\0*", file_get_contents($mm, false, null, 0, 4));
        $this->assertSame(['pages' => 1, 'type' => 'image'], PreviewImages::make($mm, $this->dir, 41));
        $this->assertWatermarkedWhite("$this->dir/41-p1-view.webp");
    }

    public function test_cmyk_tiff_to_srgb(): void
    {
        // Блакитний (cyan) у CMYK: C=100%
        $src = $this->image(600, 400, 'tiff', 'cmyk.tif', 'cyan', function (Imagick $im) {
            $im->transformImageColorspace(Imagick::COLORSPACE_CMYK);
        });
        $this->assertSame(Imagick::COLORSPACE_CMYK, (new Imagick($src))->getImageColorspace());
        PreviewImages::make($src, $this->dir, 42);
        $im = new Imagick("$this->dir/42-p1-view.webp");
        $this->assertSame(Imagick::COLORSPACE_SRGB, $im->getImageColorspace());
        $this->assertLessThan(0.3, $im->getImageChannelMean(Imagick::CHANNEL_RED)['mean'] / 65535);
        $this->assertGreaterThan(0.6, $im->getImageChannelMean(Imagick::CHANNEL_BLUE)['mean'] / 65535);
    }

    public function test_tiff_limits_and_first_page_only(): void
    {
        $big = $this->image(13000, 50, 'tiff', 'big.tif');
        $this->assertRejected('Зображення завелике: до 12 000 px по стороні', fn() => PreviewImages::make($big, $this->dir, 1));

        // Багатосторінковий TIFF: лише перша сторінка (червона), друга (синя) ігнорується
        $two = new Imagick();
        foreach (['red', 'blue'] as $c) {
            $p = new Imagick();
            $p->newImage(300, 200, $c);
            $p->setImageFormat('tiff');
            $two->addImage($p);
        }
        $two->writeImages("tiff:$this->tmp/two.tif", true);
        $this->assertSame(['pages' => 1, 'type' => 'image'], PreviewImages::make("$this->tmp/two.tif", $this->dir, 43));
        $im = new Imagick("$this->dir/43-p1-view.webp");
        $this->assertGreaterThan(0.6, $im->getImageChannelMean(Imagick::CHANNEL_RED)['mean'] / 65535);
        $this->assertLessThan(0.4, $im->getImageChannelMean(Imagick::CHANNEL_BLUE)['mean'] / 65535);
        $this->assertSame(['43-p1-card.webp', '43-p1-view.webp'], $this->files());
    }

    /** gs і Imagick бачать лише шлях із безпечних символів — інакше відмова до будь-якого їх виклику */
    public function test_unsafe_path_refused(): void
    {
        // Пробіл і «[0]»; ім'я з переведенням рядка в кінці (без модифікатора D «$» пропустив би його)
        foreach (['with space[0].pdf', "newline.pdf\n"] as $name) {
            $src = $this->minipdf($name, 1);
            try {
                PreviewImages::make($src, $this->dir, 1);
                $this->fail("Очікувався RuntimeException: $name");
            } catch (RuntimeException $e) {
                $this->assertNotInstanceOf(DesignerException::class, $e);
            }
            $this->assertSame([], $this->files());
        }
    }

    /**
     * Вивід gs при підрахунку сторінок не вмістився в межу (скарги битого PDF витіснили б рядки PAGE) — відмова «пошкоджений»,
     * а не прев'ю без останніх сторінок. Межу тест ставить такою, що вміщує лише перший рядок PAGE із трьох
     */
    public function test_page_count_output_overflow_rejected(): void
    {
        $max = new ReflectionProperty(PreviewImages::class, 'gsOutMax');
        $max->setAccessible(true);
        $saved = $max->getValue();
        $src = $this->minipdf('three.pdf', 3);
        try {
            $max->setValue(null, 20);
            $this->assertRejected(Images::ERR_BROKEN, fn() => PreviewImages::make($src, $this->dir, 1));
        } finally {
            $max->setValue(null, $saved);
        }
        $this->assertSame(3, PreviewImages::make($src, $this->dir, 2)['pages'], 'зі звичайною межею — усі 3 сторінки');
    }

    public function test_rejects_fake_pdf(): void
    {
        $src = "$this->tmp/fake.pdf";
        file_put_contents($src, "%PDF-1.4\nце не PDF, а текст із правильним початком\n");
        $this->assertRejected(Images::ERR_BROKEN, fn() => PreviewImages::make($src, $this->dir, 1));
    }

    public function test_rejects_pdf_without_pages(): void
    {
        $src = "$this->tmp/empty.pdf";
        file_put_contents($src, "%PDF-1.4\n1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n2 0 obj << /Type /Pages /Kids [] /Count 0 >> endobj\n"
            . "trailer << /Root 1 0 R >>\n%%EOF\n");
        $this->assertRejected(Images::ERR_BROKEN, fn() => PreviewImages::make($src, $this->dir, 1));
    }

    public function test_cmyk_pdf_to_srgb(): void
    {
        $src = $this->pdf(1, 'cmyk.pdf', 300, 300, 'cmyk(100%,0%,0%,0%)', Imagick::COLORSPACE_CMYK);
        $this->assertSame(['pages' => 1, 'type' => 'pdf'], PreviewImages::make($src, $this->dir, 2));

        foreach (['view', 'card'] as $size) {
            $im = new Imagick("$this->dir/2-p1-$size.webp");
            $this->assertSame(Imagick::COLORSPACE_SRGB, $im->getImageColorspace(), $size);
            $this->assertSame([], $im->getImageProfiles('*', false), "$size без профілів і метаданих");
            // Блакитний (cyan): червоного мало, синього багато
            $this->assertLessThan(0.3, $im->getImageChannelMean(Imagick::CHANNEL_RED)['mean'] / 65535, $size);
            $this->assertGreaterThan(0.6, $im->getImageChannelMean(Imagick::CHANNEL_BLUE)['mean'] / 65535, $size);
        }
    }

    public function test_metadata_stripped(): void
    {
        $src = $this->image(800, 600, 'jpeg', 'meta.jpg', 'white', function (Imagick $im) {
            $im->setImageProperty('comment', 'Secret comment');
        });
        PreviewImages::make($src, $this->dir, 6);
        foreach (['view', 'card'] as $size) {
            $this->assertStringNotContainsString('Secret', file_get_contents("$this->dir/6-p1-$size.webp"), $size);
        }
    }

    public function test_rejects_gif_and_text(): void
    {
        $gif = $this->image(100, 100, 'gif', 'a.gif');
        $this->assertRejected('Підходять лише JPG, PNG, TIFF або PDF', fn() => PreviewImages::make($gif, $this->dir, 1));

        $txt = "$this->tmp/a.jpg";
        file_put_contents($txt, 'просто текст');
        $this->assertRejected(Images::ERR_BROKEN, fn() => PreviewImages::make($txt, $this->dir, 1));
        $this->assertRejected(Images::ERR_BROKEN, fn() => PreviewImages::make("$this->tmp/none.pdf", $this->dir, 1));
    }

    /** Ліміт розміру — preview_max_mb, і для PDF теж (до будь-якого розбору) */
    public function test_rejects_too_big(): void
    {
        $max = Settings::previewMaxBytes();
        $src = "$this->tmp/big.pdf";
        $fh = fopen($src, 'wb');
        fwrite($fh, "%PDF-1.4\n");
        ftruncate($fh, $max + 1);                     // розріджений файл — місця на диску не займає
        fclose($fh);
        $this->assertRejected(Images::tooBig($max + 1, $max), fn() => PreviewImages::make($src, $this->dir, 1));
    }

    /** Прев'ю, як і портфоліо, роздає nginx: файли 0644, нові папки 0755 за будь-якої umask; відмова не лишає папок */
    public function test_files_and_dirs_world_readable(): void
    {
        $dir = "$this->dir/previews/15";
        $old = umask(077);
        try {
            $this->assertRejected(self::tooManyPages(),
                fn() => PreviewImages::make($this->pdf(Settings::previewMaxPages() + 1, 'many.pdf', 30, 30), $dir, 1));
            $this->assertDirectoryDoesNotExist("$this->dir/previews");

            PreviewImages::make($this->pdf(2, 'two.pdf', 200, 100), $dir, 9);
            PreviewImages::make($this->image(300, 200, 'png', 'p.png'), $dir, 10);
        } finally {
            umask($old);
        }
        $this->assertSame('0755', self::mode("$this->dir/previews"));
        $this->assertSame('0755', self::mode($dir));
        $files = $this->files($dir);
        $this->assertCount(6, $files);
        foreach ($files as $f) $this->assertSame('0644', self::mode("$dir/$f"), $f);
    }

    private static function mode(string $path): string
    {
        clearstatcache(true, $path);
        return sprintf('%04o', fileperms($path) & 07777);
    }

    /** Збій запису посередині — прибрати все, що встиг створити цей виклик; чуже в папці лишається */
    public function test_failure_removes_written_files(): void
    {
        touch("$this->dir/other.webp");
        mkdir("$this->dir/5-p2-card.webp");            // запис картки 2-ї сторінки впаде
        try {
            PreviewImages::make($this->pdf(3, 'three.pdf', 100, 100), $this->dir, 5);
            $this->fail('Очікувався збій запису');
        } catch (RuntimeException $e) {
            $this->assertNotInstanceOf(DesignerException::class, $e);
        }
        $this->assertSame(['5-p2-card.webp', 'other.webp'], $this->files());
    }

    /** gs довше за ліміт — процес убито, «Не вдалося обробити PDF…», створені цим викликом папки й файли прибрано */
    public function test_slow_pdf_killed_and_cleaned(): void
    {
        $timeout = new ReflectionProperty(PreviewImages::class, 'gsTimeout');
        $timeout->setAccessible(true);
        $saved = $timeout->getValue();
        $before = glob(sys_get_temp_dir() . '/design-pdf-*');
        try {
            // Рахується за мілісекунди, а растр — секунди: ліміт спрацює саме на рендері, коли папку вже створено
            $src = $this->minipdf('slow.pdf', 2, 595, 842, '', str_repeat("1 0 0 rg 0 0 595 842 re f 0 0 1 rg 0 0 595 842 re f\n", 10000));
            $timeout->setValue(null, 1);
            $this->assertRejected('Не вдалося обробити PDF — спробуйте менший файл',
                fn() => PreviewImages::make($src, "$this->dir/new/sub", 1));
        } finally {
            $timeout->setValue(null, $saved);
        }
        $this->assertDirectoryDoesNotExist("$this->dir/new");
        $this->assertSame($before, glob(sys_get_temp_dir() . '/design-pdf-*'), 'тимчасова папка gs прибрана');
    }

    /**
     * Фатальна помилка PHP (max_execution_time) посеред рендеру: finally не виконуються, тож gs, що ще працює,
     * убиває і тимчасову папку прибирає shutdown-функція. Окремий процес PHP з set_time_limit(2) (час CPU самого PHP)
     */
    public function test_fatal_error_kills_gs_and_removes_temp(): void
    {
        // 10 сторінок 2000×2000 px із 20 000 різнобарвних прямокутників: gs ~0.7 с/стор., PHP ~0.7 с CPU/стор. —
        // ліміт у 2 с CPU спрацює на 3-й сторінці, коли gs ще рендерить
        mt_srand(1);
        $content = '';
        for ($i = 0; $i < 20000; $i++) {
            $content .= sprintf("%.2f %.2f %.2f rg %d %d %d %d re f\n", mt_rand(0, 100) / 100, mt_rand(0, 100) / 100, mt_rand(0, 100) / 100,
                mt_rand(0, 1400), mt_rand(0, 1400), mt_rand(5, 60), mt_rand(5, 60));
        }
        $src = $this->minipdf('fatal.pdf', 10, 1440, 1440, '', $content);
        $script = "$this->tmp/fatal.php";
        file_put_contents($script, '<?php require ' . var_export(dirname(__DIR__, 2) . '/helpers/Design/bootstrap.php', true) . ";\n"
            . "set_time_limit(2);\nDesign\\PreviewImages::make(\$argv[1], \$argv[2], 1);\necho 'НЕ ВПАЛО';\n");
        $before = glob(sys_get_temp_dir() . '/design-pdf-*');
        $proc = proc_open([PHP_BINARY, '-d', 'display_errors=stdout', '-d', 'log_errors=0', $script, $src, "$this->dir/f"],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        $this->assertStringContainsString('Maximum execution time', $out);
        $this->assertSame($before, glob(sys_get_temp_dir() . '/design-pdf-*'), 'тимчасова папка gs прибрана');
        foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $f) {
            $this->assertStringNotContainsString($src, (string)@file_get_contents($f), 'gs по цьому PDF ще працює');
        }
    }

    /** @return string[] cmdline процесів, у яких згадано $needle */
    private static function processesWith(string $needle): array
    {
        $found = [];
        foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $f) {
            $cmd = (string)@file_get_contents($f);
            if (str_contains($cmd, $needle)) $found[] = str_replace("\0", ' ', $cmd);
        }
        return $found;
    }

    /**
     * Воркер убито SIGKILL (php-fpm request_terminate_timeout тощо): ні finally, ні shutdown не виконаються.
     * gs запущено під «timeout --signal=KILL» — другий ліміт сам зупинить його (ліміт PHP + 5 с)
     */
    public function test_orphan_gs_stopped_by_timeout_wrapper(): void
    {
        if (!is_executable('/usr/bin/timeout')) $this->markTestSkipped('немає /usr/bin/timeout');
        $src = $this->minipdf('orphan.pdf', 5, 595, 842, '', str_repeat("1 0 0 rg 0 0 595 842 re f 0 0 1 rg 0 0 595 842 re f\n", 10000));
        $script = "$this->tmp/orphan.php";
        file_put_contents($script, '<?php require ' . var_export(dirname(__DIR__, 2) . '/helpers/Design/bootstrap.php', true) . ";\n"
            . "\$t = new ReflectionProperty(Design\\PreviewImages::class, 'gsTimeout'); \$t->setAccessible(true); \$t->setValue(null, 2);\n"
            . "Design\\PreviewImages::make(\$argv[1], \$argv[2], 1);\n");
        $null = ['file', '/dev/null', 'w'];
        $before = glob(sys_get_temp_dir() . '/design-pdf-*') ?: [];
        $proc = proc_open([PHP_BINARY, $script, $src, "$this->dir/o"], [0 => ['file', '/dev/null', 'r'], 1 => $null, 2 => $null], $pipes);
        // Чекаємо, поки gs почне рендер, і вбиваємо PHP так, як це робить php-fpm
        $rendering = fn() => array_filter(self::processesWith($src), fn($cmd) => str_contains($cmd, '-sDEVICE=ppmraw'));
        for ($i = 0; $i < 100 && !$rendering(); $i++) usleep(50000);
        $this->assertNotEmpty($rendering(), 'gs не почав рендер');
        usleep(300000);
        proc_terminate($proc, 9);
        proc_close($proc);

        $t = microtime(true);
        while (self::processesWith($src) && microtime(true) - $t < 15) usleep(200000);
        $this->assertSame([], self::processesWith($src), 'gs-сирота не зупинився');
        $this->assertLessThan(10, microtime(true) - $t);
        // Тимчасову папку прибрати нікому (воркер убито) — прибирає тест (лише ту, що з'явилась за цей тест);
        // у проді — systemd-tmpfiles / tmpwatch
        foreach (array_diff(glob(sys_get_temp_dir() . '/design-pdf-*') ?: [], $before) as $d) {
            foreach (glob("$d/*") ?: [] as $f) unlink($f);
            rmdir($d);
        }
    }

    /** Великий JPEG декодується одразу зменшеним (jpeg:size) — разом зі знаком і WebP q90 укладається швидко */
    public function test_large_jpeg_is_fast(): void
    {
        $src = $this->image(8000, 6000, 'jpeg', 'big.jpg', 'gradient:red-blue');
        $t = microtime(true);
        PreviewImages::make($src, $this->dir, 11);
        $elapsed = microtime(true) - $t;
        $this->assertSame([2000, 1500, 'WEBP'], self::info("$this->dir/11-p1-view.webp"));
        $this->assertSame([800, 600, 'WEBP'], self::info("$this->dir/11-p1-card.webp"));
        // На dev ~0.9 с; межа з великим запасом, щоб не «блимати» на завантаженій машині — ловить лише явну аварію
        $this->assertLessThan(8, $elapsed, sprintf('make() 8000×6000 JPEG: %.2f с', $elapsed));
    }

    public function test_imagemagick_limited_to_one_thread(): void
    {
        foreach ([$this->image(300, 200, 'png', 't.png'), $this->pdf(1, 't.pdf', 100, 100)] as $i => $src) {
            \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_THREAD, 2);
            PreviewImages::make($src, $this->dir, 20 + $i);
            $this->assertEquals(1, \Imagick::getResourceLimit(\Imagick::RESOURCETYPE_THREAD));
        }
    }
}
