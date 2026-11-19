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
    }

    protected function tearDown(): void
    {
        self::rmTree($this->tmp);
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

    public function test_rejects_31_pages(): void
    {
        $max = Settings::previewMaxPages();
        $src = $this->pdf($max + 1, 'many.pdf', 40, 40);
        $this->assertRejected(
            sprintf("Забагато сторінок: %d. Максимум — %d. Розбийте на кілька прев'ю", $max + 1, $max),
            fn() => PreviewImages::make($src, $this->dir, 1)
        );
    }

    /**
     * Шлях prod (gs 9.27 не рахує сторінки під -dSAFER): число й розміри сторінок — ping Imagick.
     * Примусово вмикаємо його й на dev (gs 9.50), де інакше рахує gs
     */
    public function test_pdf_pages_counted_by_imagick_when_gs_cannot(): void
    {
        $gsCounts = new ReflectionProperty(PreviewImages::class, 'gsCounts');
        $gsCounts->setAccessible(true);
        $saved = $gsCounts->getValue();
        $gsCounts->setValue(null, false);
        try {
            $this->assertSame(['pages' => 3, 'type' => 'pdf'], PreviewImages::make($this->pdf(3, 'three.pdf'), $this->dir, 14));
            [$w, $h] = self::info("$this->dir/14-p3-view.webp");
            $this->assertEqualsWithDelta(826, $w, 2);
            $this->assertEqualsWithDelta(1169, $h, 2);
            $this->assertCount(6, $this->files());
            foreach ($this->files() as $f) unlink("$this->dir/$f");

            $max = Settings::previewMaxPages();
            $many = $this->pdf($max + 1, 'many.pdf', 40, 40);
            $this->assertRejected(
                sprintf("Забагато сторінок: %d. Максимум — %d. Розбийте на кілька прев'ю", $max + 1, $max),
                fn() => PreviewImages::make($many, $this->dir, 1)
            );
            $fake = "$this->tmp/fake.pdf";
            file_put_contents($fake, "%PDF-1.4\nтекст\n");
            $this->assertRejected(Images::ERR_BROKEN, fn() => PreviewImages::make($fake, $this->dir, 1));
        } finally {
            $gsCounts->setValue(null, $saved);
        }
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
        $this->assertRejected('Підходять лише JPG, PNG або PDF', fn() => PreviewImages::make($gif, $this->dir, 1));

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
            $this->assertRejected("Забагато сторінок: 31. Максимум — 30. Розбийте на кілька прев'ю",
                fn() => PreviewImages::make($this->pdf(31, 'many.pdf', 30, 30), $dir, 1));
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
        $timeout->setValue(null, 0);
        $before = glob(sys_get_temp_dir() . '/design-pdf-*');
        $log = ini_set('error_log', '/dev/null');         // запис «gs не вклався…» у журнал — очікуваний
        try {
            $src = $this->pdf(2, 'slow.pdf', 100, 100);
            $this->assertRejected('Не вдалося обробити PDF — спробуйте менший файл',
                fn() => PreviewImages::make($src, "$this->dir/new/sub", 1));
        } finally {
            $timeout->setValue(null, $saved);
            ini_set('error_log', (string)$log);
        }
        $this->assertDirectoryDoesNotExist("$this->dir/new");
        $this->assertSame($before, glob(sys_get_temp_dir() . '/design-pdf-*'), 'тимчасова папка gs прибрана');
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
        // На dev ~0.8 с; межа з запасом, але ловить повне декодування 48 Мп
        $this->assertLessThan(2.5, $elapsed, sprintf('make() 8000×6000 JPEG: %.2f с', $elapsed));
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
