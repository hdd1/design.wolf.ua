<?php

use Design\DesignerException;
use Design\Images;
use PHPUnit\Framework\TestCase;

final class ImagesTest extends TestCase
{
    /** CMYK-профіль для тесту вбудованого ICC; на dev лежить у сусідньому сайті */
    private const CMYK_ICC = '/home/connect/wolf.ua/img/icc/CoatedFOGRA39.icc';
    /** Не-sRGB RGB-профілі (Adobe RGB, Display P3), шукаємо на диску; першого знайденого досить */
    private const WIDE_ICC = [
        '/home/connect/wolf.ua/img/icc/AdobeRGB1998.icc',
        '/usr/share/color/icc/AdobeRGB1998.icc',
        '/usr/share/color/icc/DisplayP3.icc',
        '/usr/share/color/icc/colord/AdobeRGB1998.icc',
    ];

    private string $tmp;
    private string $dir;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/design-images-' . bin2hex(random_bytes(4));
        $this->dir = $this->tmp . '/out';
        mkdir($this->dir, 0777, true);
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

    /** Створює картинку w×h у форматі jpeg|png|gif; $prep може змінити її перед записом */
    private function image(int $w, int $h, string $format, string $name, ?callable $prep = null): string
    {
        $im = new Imagick();
        $im->newPseudoImage($w, $h, 'gradient:red-blue');
        $im->setImageFormat($format);
        if ($prep) $prep($im);
        $path = $this->tmp . '/' . $name;
        $im->writeImage("$format:$path");            // формат явно: розширення імені може брехати (GIF у .jpg)
        $im->clear();
        return $path;
    }

    /** @return array{0:int,1:int,2:string} ширина, висота, формат */
    private static function info(string $path): array
    {
        $im = new Imagick($path);
        return [$im->getImageWidth(), $im->getImageHeight(), $im->getImageFormat()];
    }

    private function files(): array
    {
        $list = array_values(array_diff(scandir($this->dir), ['.', '..']));
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

    /** EXIF (TIFF little-endian) з одним тегом Orientation та описом — для тестів повороту й метаданих */
    private static function exif(int $orientation): string
    {
        $desc = "Secret camera\0";                         // 14 байт, лежить після IFD
        $ifd = pack('v', 2)
            . pack('vvVvv', 0x0112, 3, 1, $orientation, 0)   // Orientation, SHORT
            . pack('vvVV', 0x010E, 2, strlen($desc), 8 + 2 + 24 + 4) // ImageDescription, ASCII
            . pack('V', 0);
        return "Exif\0\0" . "II*\0" . pack('V', 8) . $ifd . $desc;
    }

    public function test_png_and_jpg_make_card_and_view(): void
    {
        foreach (['jpeg' => 'jpg', 'png' => 'png'] as $format => $ext) {
            $src = $this->image(3000, 2000, $format, "src.$ext");
            $res = Images::store($src, $this->dir, "7$ext");

            $this->assertSame(['width' => 3000, 'height' => 2000, 'file' => "7$ext.$ext"], $res);
            $this->assertSame(md5_file($src), md5_file("$this->dir/7$ext.$ext"), 'оригінал байт-в-байт');
            $this->assertSame([600, 480, 'WEBP'], self::info("$this->dir/7$ext-card.webp"));
            $this->assertSame([2000, 1333, 'WEBP'], self::info("$this->dir/7$ext-view.webp"));
        }
        $this->assertSame(
            ['7jpg-card.webp', '7jpg-view.webp', '7jpg.jpg', '7png-card.webp', '7png-view.webp', '7png.png'],
            $this->files()
        );
    }

    public function test_small_image_not_upscaled(): void
    {
        $src = $this->image(800, 600, 'jpeg', 'small.jpg');
        $res = Images::store($src, $this->dir, '3');

        $this->assertSame(['width' => 800, 'height' => 600, 'file' => '3.jpg'], $res);
        $this->assertSame([800, 600, 'WEBP'], self::info("$this->dir/3-view.webp"));
        $this->assertSame([600, 480, 'WEBP'], self::info("$this->dir/3-card.webp"));
    }

    public function test_rejects_text_named_jpg(): void
    {
        $src = $this->tmp . '/text.jpg';
        file_put_contents($src, "hello, I am not a picture\n");
        $this->assertRejected('Файл пошкоджений або це не картинка', fn() => Images::store($src, $this->dir, '1'));
    }

    public function test_rejects_gif(): void
    {
        $src = $this->image(100, 100, 'gif', 'anim.jpg');
        $this->assertRejected('Підходять лише JPG або PNG', fn() => Images::store($src, $this->dir, '1'));
    }

    public function test_rejects_too_big_bytes(): void
    {
        // Розріджений файл з нулів: якби розмір перевірявся після Imagick, була б помилка «пошкоджений»
        $src = $this->tmp . '/huge.jpg';
        $fh = fopen($src, 'w');
        ftruncate($fh, 48 * 1024 * 1024);
        fclose($fh);
        $this->assertGreaterThan(Images::MAX_BYTES, filesize($src));
        $this->assertRejected('Файл завеликий: 48 МБ. Максимум — 20 МБ', fn() => Images::store($src, $this->dir, '1'));
    }

    public function test_rejects_too_many_pixels(): void
    {
        $src = $this->image(15000, 10, 'png', 'wide.png');
        $this->assertRejected('Зображення завелике: до 12 000 px по стороні', fn() => Images::store($src, $this->dir, '1'));
    }

    public function test_rejects_extreme_aspect_ratio(): void
    {
        $src = $this->image(12000, 13, 'png', 'strip.png');
        $this->assertRejected('Незвичні пропорції: сторони відрізняються більш ніж у 20 разів', fn() => Images::store($src, $this->dir, '1'));
        $tall = $this->image(13, 300, 'jpeg', 'tall.jpg');
        $this->assertRejected('Незвичні пропорції: сторони відрізняються більш ніж у 20 разів', fn() => Images::avatar($tall, $this->dir));
    }

    /** Рівно 1:20 ще можна; view замалий для картки — картка з основи, без помилки і точно 600×480 */
    public function test_narrow_image_at_ratio_limit(): void
    {
        $src = $this->image(6000, 300, 'jpeg', 'pano.jpg');
        $res = Images::store($src, $this->dir, '6');

        $this->assertSame(['width' => 6000, 'height' => 300, 'file' => '6.jpg'], $res);
        $this->assertSame([2000, 100, 'WEBP'], self::info("$this->dir/6-view.webp"));
        $this->assertSame([600, 480, 'WEBP'], self::info("$this->dir/6-card.webp"));
        $this->assertSame([320, 320, 'WEBP'], self::info("$this->dir/" . Images::avatar($src, $this->dir)));
    }

    /** JPEG декодується одразу зменшеним (jpeg:size), тож великий файл — швидко, а розміри копій ті самі */
    public function test_large_jpeg_is_fast_and_sizes_exact(): void
    {
        $src = $this->image(8000, 6000, 'jpeg', 'big.jpg');
        $t = microtime(true);
        $res = Images::store($src, $this->dir, '10');
        $elapsed = microtime(true) - $t;

        $this->assertSame(['width' => 8000, 'height' => 6000, 'file' => '10.jpg'], $res);
        $this->assertSame([2000, 1500, 'WEBP'], self::info("$this->dir/10-view.webp"));
        $this->assertSame([600, 480, 'WEBP'], self::info("$this->dir/10-card.webp"));
        $this->assertSame(md5_file($src), md5_file("$this->dir/10.jpg"));
        // На dev: повне декодування ~2.0 с, з jpeg:size ~0.35 с; межа з запасом ~3×, але ловить повне декодування
        $this->assertLessThan(1.2, $elapsed, sprintf('store() 8000×6000 JPEG: %.2f с', $elapsed));
    }

    /** Підказка розміру береться з файлу (до повороту), а розміри результату — після повороту за EXIF */
    public function test_large_rotated_jpeg_keeps_true_sizes(): void
    {
        $src = $this->image(4000, 2000, 'jpeg', 'turned.jpg', function (Imagick $im) {
            $im->setImageProfile('exif', self::exif(Imagick::ORIENTATION_RIGHTTOP));
            $im->setImageOrientation(Imagick::ORIENTATION_RIGHTTOP);
        });
        $res = Images::store($src, $this->dir, '11');

        $this->assertSame(['width' => 2000, 'height' => 4000, 'file' => '11.jpg'], $res);
        $this->assertSame([1000, 2000, 'WEBP'], self::info("$this->dir/11-view.webp"));
        $this->assertSame([600, 480, 'WEBP'], self::info("$this->dir/11-card.webp"));
    }

    /** RGB з вбудованим Adobe RGB / P3: без конвертації stripImage викине профіль і кольори зблякнуть */
    public function test_wide_gamut_rgb_profile_converted_to_srgb(): void
    {
        $icc = null;
        foreach (self::WIDE_ICC as $path) {
            if (is_file($path)) { $icc = $path; break; }
        }
        if ($icc === null) $this->markTestSkipped('Немає не-sRGB ICC-профілю на диску: ' . implode(', ', self::WIDE_ICC));

        $make = function (string $name, ?string $profile): string {
            $im = new Imagick();
            $im->newImage(200, 200, 'rgb(60,170,90)');
            if ($profile) $im->profileImage('icc', file_get_contents($profile)); // лише вбудовує
            $im->setImageFormat('png');
            $path = $this->tmp . '/' . $name;
            $im->writeImage("png:$path");
            return $path;
        };
        $srgb = '/home/connect/wolf.ua/img/icc/sRGB2014.icc';
        Images::store($make('plain.png', null), $this->dir, 'plain');
        Images::store($make('wide.png', $icc), $this->dir, 'wide');

        $px = fn(string $f) => (new Imagick("$this->dir/$f"))->getImagePixelColor(100, 100)->getColor();
        $plain = $px('plain-view.webp');
        $wide = $px('wide-view.webp');
        $diff = fn(array $a, array $b) => abs($a['r'] - $b['r']) + abs($a['g'] - $b['g']) + abs($a['b'] - $b['b']);
        // Adobe RGB (60,170,90) у sRGB ≈ (0,172,84): насиченіший зелений
        $this->assertGreaterThan(30, $diff($plain, $wide), basename($icc) . ' має конвертуватись у sRGB: ' . json_encode([$plain, $wide]));
        $this->assertSame([], (new Imagick("$this->dir/wide-card.webp"))->getImageProfiles('*', false));

        if (is_file($srgb)) {          // уже sRGB-профіль — кольори не змінюються
            Images::store($make('srgb.png', $srgb), $this->dir, 'srgb');
            $this->assertLessThan(8, $diff($plain, $px('srgb-view.webp')), 'sRGB-профіль не має зсувати кольори');
        }
    }

    public function test_rejects_missing_file(): void
    {
        $this->assertRejected('Файл пошкоджений або це не картинка', fn() => Images::store($this->tmp . '/none.jpg', $this->dir, '1'));
    }

    public function test_cmyk_becomes_srgb(): void
    {
        $src = $this->image(300, 200, 'jpeg', 'cmyk.jpg', function (Imagick $im) {
            $im->transformImageColorspace(Imagick::COLORSPACE_CMYK);
        });
        $this->assertSame(Imagick::COLORSPACE_CMYK, (new Imagick($src))->getImageColorspace());

        Images::store($src, $this->dir, '5');
        foreach (['card', 'view'] as $kind) {
            $this->assertSame(Imagick::COLORSPACE_SRGB, (new Imagick("$this->dir/5-$kind.webp"))->getImageColorspace(), $kind);
        }
    }

    public function test_cmyk_with_embedded_icc_converted_by_profile(): void
    {
        if (!is_file(self::CMYK_ICC)) $this->markTestSkipped('Немає CMYK-профілю ' . self::CMYK_ICC);

        // Однакові CMYK-пікселі (100% M + 100% Y); у другого — вбудований FOGRA39
        $make = function (string $name, bool $icc): string {
            $im = new Imagick();
            $im->newImage(200, 200, 'red');
            $im->transformImageColorspace(Imagick::COLORSPACE_CMYK);
            if ($icc) $im->profileImage('icc', file_get_contents(self::CMYK_ICC)); // без вихідного профілю — лише вбудовує
            $im->setImageFormat('jpeg');
            $path = $this->tmp . '/' . $name;
            $im->writeImage($path);
            return $path;
        };
        Images::store($make('plain.jpg', false), $this->dir, 'plain');
        Images::store($make('icc.jpg', true), $this->dir, 'icc');

        $plain = (new Imagick("$this->dir/plain-card.webp"))->getImagePixelColor(300, 240)->getColor();
        $icc = (new Imagick("$this->dir/icc-card.webp"))->getImagePixelColor(300, 240)->getColor();
        $this->assertSame(Imagick::COLORSPACE_SRGB, (new Imagick("$this->dir/icc-card.webp"))->getImageColorspace());
        // Друкарський червоний через профіль помітно темніший/тепліший за «наївний» 255,0,0
        $diff = abs($plain['r'] - $icc['r']) + abs($plain['g'] - $icc['g']) + abs($plain['b'] - $icc['b']);
        $this->assertGreaterThan(20, $diff, 'конвертація має йти через вбудований ICC: ' . json_encode([$plain, $icc]));
        $this->assertGreaterThan($icc['g'] + 100, $icc['r'], 'лишається червоним');
    }

    public function test_exif_orientation_applied(): void
    {
        $src = $this->image(400, 200, 'jpeg', 'rotated.jpg', function (Imagick $im) {
            $im->setImageProfile('exif', self::exif(Imagick::ORIENTATION_RIGHTTOP));
            $im->setImageOrientation(Imagick::ORIENTATION_RIGHTTOP);
        });
        $this->assertSame(Imagick::ORIENTATION_RIGHTTOP, (new Imagick($src))->getImageOrientation());

        $res = Images::store($src, $this->dir, '9');
        $this->assertSame(['width' => 200, 'height' => 400, 'file' => '9.jpg'], $res);
        $this->assertSame([200, 400, 'WEBP'], self::info("$this->dir/9-view.webp"));
        $this->assertSame([600, 480, 'WEBP'], self::info("$this->dir/9-card.webp"));
        $this->assertSame(md5_file($src), md5_file("$this->dir/9.jpg"), 'оригінал не перевертаємо');
    }

    /** Запасний ручний поворот (для збірок без autoOrient) дає ті ж пікселі, що й autoOrient, для всіх 8 значень */
    public function test_manual_orientation_matches_auto_orient(): void
    {
        if (!method_exists(Imagick::class, 'autoOrient')) $this->markTestSkipped('Немає autoOrient для порівняння');
        $manual = new ReflectionMethod(Images::class, 'orientManually');
        $manual->setAccessible(true);

        for ($o = Imagick::ORIENTATION_TOPLEFT; $o <= Imagick::ORIENTATION_LEFTBOTTOM; $o++) {
            $base = new Imagick();
            $base->newPseudoImage(4, 2, 'gradient:red-blue');
            $draw = new ImagickDraw();
            $draw->setFillColor('lime');
            $draw->point(0, 0);                       // мітка в лівому верхньому куті
            $base->drawImage($draw);
            $base->setImageOrientation($o);

            $a = clone $base;
            $a->autoOrient();
            $b = clone $base;
            $manual->invoke(null, $b);

            $this->assertSame([$a->getImageWidth(), $a->getImageHeight()], [$b->getImageWidth(), $b->getImageHeight()], "orientation $o");
            $this->assertSame(
                $a->exportImagePixels(0, 0, $a->getImageWidth(), $a->getImageHeight(), 'RGB', Imagick::PIXEL_CHAR),
                $b->exportImagePixels(0, 0, $b->getImageWidth(), $b->getImageHeight(), 'RGB', Imagick::PIXEL_CHAR),
                "orientation $o"
            );
        }
    }

    public function test_metadata_stripped(): void
    {
        $src = $this->image(400, 300, 'jpeg', 'exif.jpg', function (Imagick $im) {
            $im->setImageProfile('exif', self::exif(Imagick::ORIENTATION_TOPLEFT));
            $im->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
        });
        $this->assertStringContainsString('Secret camera', file_get_contents($src));

        Images::store($src, $this->dir, '4');
        $avatar = Images::avatar($src, $this->dir);
        foreach (['4-card.webp', '4-view.webp', $avatar] as $file) {
            $path = "$this->dir/$file";
            $this->assertStringNotContainsString('Secret camera', file_get_contents($path), $file);
            $this->assertStringNotContainsString('EXIF', file_get_contents($path), $file);
            $im = new Imagick($path);
            $this->assertSame([], $im->getImageProfiles('*', false), $file);
            $this->assertSame([], $im->getImageProperties('exif:*'), $file);
        }
    }

    public function test_avatar_square(): void
    {
        $src = $this->image(3000, 2000, 'png', 'face.png');
        $a = Images::avatar($src, $this->dir);
        $b = Images::avatar($src, $this->dir);

        $this->assertMatchesRegularExpression('/^avatar-[0-9a-f]{8}\.webp$/', $a);
        $this->assertNotSame($a, $b);
        $this->assertSame([320, 320, 'WEBP'], self::info("$this->dir/$a"));
        $this->assertEqualsCanonicalizing([$a, $b], $this->files(), 'оригінал аватара не зберігається');
    }

    public function test_avatar_rejects_like_store(): void
    {
        $src = $this->image(100, 100, 'gif', 'face.gif');
        $this->assertRejected('Підходять лише JPG або PNG', fn() => Images::avatar($src, $this->dir));
    }

    /** Один потік ImageMagick: max_execution_time рахує CPU всіх потоків процесу, а паралельність дає клієнт */
    public function test_imagemagick_limited_to_one_thread(): void
    {
        $src = $this->image(800, 600, 'png', 't.png');
        foreach ([fn() => Images::store($src, $this->dir, '5'), fn() => Images::avatar($src, $this->dir)] as $call) {
            \Imagick::setResourceLimit(\Imagick::RESOURCETYPE_THREAD, 2);
            $this->assertEquals(2, \Imagick::getResourceLimit(\Imagick::RESOURCETYPE_THREAD));
            $call();
            $this->assertEquals(1, \Imagick::getResourceLimit(\Imagick::RESOURCETYPE_THREAD));
        }
    }

    public function test_creates_missing_dir(): void
    {
        $src = $this->image(800, 600, 'png', 'p.png');
        $dir = $this->dir . '/new/sub';
        Images::store($src, $dir, '2');
        $this->assertFileExists("$dir/2-view.webp");
    }

    public function test_failure_removes_written_files(): void
    {
        $src = $this->image(800, 600, 'jpeg', 'p.jpg');
        mkdir("$this->dir/8-view.webp");             // запис view впаде — оригінал і card мають зникнути
        try {
            Images::store($src, $this->dir, '8');
            $this->fail('Очікувалась помилка запису');
        } catch (RuntimeException $e) {
            $this->assertNotInstanceOf(DesignerException::class, $e, 'це не помилка користувача — летить як є');
        }
        $this->assertSame(['8-view.webp'], $this->files());
    }
}
