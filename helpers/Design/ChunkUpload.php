<?php

namespace Design;

/**
 * Приймання файлу частинами по 5 МБ (assets/js/chunk-upload.js) через спільний \UppyChunkGlobal (cdn.wolf.ua).
 * UppyChunkGlobal на поганих даних сам пише JSON і робить exit, тож усе перевіряється тут ДО його виклику.
 * Шлях до файлу від браузера не приймається: лише частина з multipart-запиту.
 */
final class ChunkUpload
{
    /** Найбільша частина в браузері (менша — коли upload_max_filesize / post_max_size сервера менші, див. partSize) */
    public const CHUNK = 5 * 1048576;
    /** Частина, від якої рахується межа кількості частин (або менша — partSize(), коли ліміти PHP менші) */
    public const MIN_CHUNK = 1048576;
    /** Запас на частину понад CHUNK */
    private const SLACK = 65536;
    /** Відповіді на останню частину й блокування — поруч із частинами UppyChunkGlobal (та сама ФС, той самий крон чистки) */
    private const RESULTS_DIR = '/mnt/work/tmp/tmp_uppy/design-results/';
    /** Скільки памʼятати відповідь для повтору останньої частини */
    private const RESULT_TTL = 3600;

    /** @var array<string, resource> утримувані блокування цього запиту */
    private static array $locks = [];

    /**
     * Скільки секунд чекати блокування (інший запит з тим самим файлом ще обробляє його); далі — DesignerException з кодом 503.
     * Нескінченне очікування тримало б воркер php-fpm (листопад 2026: зависання пулу dev). Менше за fastcgi_read_timeout nginx
     * на проді (60 с): відповідь «Сервер зайнятий» має дійти до браузера раніше, ніж nginx віддасть 504, а воркер — звільнитись
     */
    public const LOCK_WAIT = 50;
    /** @internal змінюють лише тести */
    public static int $lockWait = self::LOCK_WAIT;
    public const ERR_BUSY = 'Сервер зайнятий — спробуйте ще раз';

    private const ERR_META = 'Невірні дані завантаження. Оновіть сторінку і спробуйте ще раз';
    private const ERR_NONE = 'Файл не надіслано';
    private const ERR_PART_BIG = 'Частина файлу завелика. Оновіть сторінку і спробуйте ще раз';

    /**
     * Одна частина: meta[fileId], meta[chunkIndex], meta[totalChunks], meta[totalSize], meta[originalName] і файл «file».
     * Доступ і CSRF перевіряє сторінка — на кожну частину, до виклику.
     *
     * @param string $scope    "{customerId}:{orderId}:{purpose}" — частини різних людей/замовлень/призначень не змішуються
     * @param int    $maxBytes ліміт файлу
     * @return array{done:bool, path?:string, name?:string, size?:int, key?:string, recalled?:array}
     *         - {done:false} — частину прийнято, чекаємо решту;
     *         - {done:true, path, name, size, key} — файл склеєно: path — у /mnt/work/tmp/tmp_uppy/ (далі переносить або видаляє
     *           сторінка), name — очищене вихідне імʼя, size === заявлений totalSize. Блокування key утримується: сторінка обробляє
     *           файл і викликає remember(key, свою відповідь) — це знімає блокування (інакше воно зніметься в кінці запиту);
     *         - {done:true, recalled} — повтор уже обробленої останньої частини: сторінка віддає recalled як свою відповідь, без обробки.
     * @throws DesignerException помилка з боку користувача
     */
    public static function receive(array $post, array $files, string $scope, int $maxBytes): array
    {
        if ($scope === '' || $maxBytes < 1) throw new \InvalidArgumentException('ChunkUpload: порожній scope або ліміт');

        $meta = is_array($post['meta'] ?? null) ? $post['meta'] : [];
        $fileId = $meta['fileId'] ?? null;
        $index = self::int($meta['chunkIndex'] ?? null);
        // Межа кількості частин — від найменшої частини, яку може надіслати браузер цього сервера
        $basis = min(self::partSize(), self::MIN_CHUNK);
        $total = self::int($meta['totalChunks'] ?? null);
        $size = self::int($meta['totalSize'] ?? null);
        if (!is_string($fileId) || !preg_match('/^[A-Za-z0-9_-]{8,64}$/D', $fileId)
            || $index === null || $total === null || $size === null
            || $total < 1 || $total > intdiv($maxBytes + $basis - 1, $basis) + 1 || $index >= $total) {
            throw new DesignerException(self::ERR_META);
        }
        if ($size < 1) throw new DesignerException('Файл порожній');
        // Заявлений розмір — на кожній частині: перевищення відсікається вже на першій
        if ($size > $maxBytes) throw new DesignerException(Images::tooBig($size, $maxBytes));

        $name = self::name(is_string($meta['originalName'] ?? null) ? $meta['originalName'] : '');
        $tmp = self::part($files);
        // UppyChunkGlobal бере частину з глобального $_FILES — це має бути саме перевірена
        if (isset($_FILES['files']) || ($_FILES['file']['tmp_name'] ?? null) !== $tmp) {
            throw new \LogicException('ChunkUpload: $files не збігається з $_FILES');
        }

        $key = sha1($scope . '|' . $fileId);
        self::lock($key);
        try {
            // Повтор останньої частини (обрив, CF 524), коли файл уже зібрано й оброблено: та сама відповідь, без повторної обробки
            $recalled = self::recall($key);
            if ($recalled !== null) {
                self::unlock($key);
                return ['done' => true, 'recalled' => $recalled];
            }
            $result = \UppyChunkGlobal::upload(['meta' => [
                'fileId' => $key,
                'chunkIndex' => $index,
                'totalChunks' => $total,
                // Імʼя тимчасового файлу UppyChunkGlobal рахує як md5(originalName|time()): з унікальним id збігу між завантаженнями немає
                'originalName' => $key . '.' . self::ext($name),
            ]]);
            if (empty($result['done'])) {
                self::unlock($key);
                return ['done' => false];
            }

            $path = (string)$result['file'];
            clearstatcache(true, $path);
            $actual = filesize($path);
            if ($actual === false) throw new \RuntimeException('ChunkUpload: не прочитати розмір склеєного файлу');
            if ($actual > $maxBytes) {
                @unlink($path);
                throw new DesignerException(Images::tooBig($actual, $maxBytes));
            }
            if ($actual !== $size) {
                @unlink($path);
                throw new DesignerException('Файл пошкоджено під час завантаження — спробуйте ще раз');
            }
        } catch (\Throwable $e) {
            self::unlock($key);
            throw $e;
        }
        // Блокування лишається до remember() або до кінця запиту: повтор цієї частини чекає, доки сторінка обробить файл
        return ['done' => true, 'path' => $path, 'name' => $name, 'size' => $actual, 'key' => $key];
    }

    /**
     * Запамʼятати остаточну відповідь сторінки на завантаження $key (з receive) на RESULT_TTL і зняти блокування.
     * Можна запамʼятати й помилку обробки (['error' => …]) — повтор отримає її ж.
     */
    public static function remember(string $key, array $result): void
    {
        self::lock($key);
        try {
            $file = self::dir() . $key . '.json';
            $tmp = $file . '.' . bin2hex(random_bytes(4));
            $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (file_put_contents($tmp, $json) === false
                || !chmod($tmp, 0600) || !rename($tmp, $file)) {
                @unlink($tmp);
                throw new \RuntimeException('ChunkUpload: не записати результат ' . $key);
            }
            self::sweep();
        } finally {
            self::unlock($key);
        }
    }

    /** Запамʼятована відповідь для $key або null (немає чи старша за RESULT_TTL — тоді видаляється) */
    public static function recall(string $key): ?array
    {
        $own = !isset(self::$locks[$key]);
        self::lock($key);
        try {
            $file = self::dir() . $key . '.json';
            clearstatcache(true, $file);
            $mtime = @filemtime($file);
            if ($mtime === false) return null;
            if ($mtime < time() - self::RESULT_TTL) {
                @unlink($file);
                return null;
            }
            $data = json_decode((string)@file_get_contents($file), true);
            return is_array($data) ? $data : null;
        } finally {
            if ($own) self::unlock($key);
        }
    }

    /**
     * Ексклюзивне блокування $key (файл {key}.lock; повторно в тому самому запиті — без змін).
     * Чекає не довше за $lockWait секунд
     * @throws DesignerException ERR_BUSY з кодом 503 — блокування так і не звільнилось
     */
    private static function lock(string $key): void
    {
        if (!preg_match('/^[0-9a-f]{40}$/D', $key)) throw new \InvalidArgumentException('ChunkUpload: поганий ключ');
        if (isset(self::$locks[$key])) return;
        $file = self::dir() . $key . '.lock';
        $fh = @fopen($file, 'c');
        if ($fh === false) throw new \RuntimeException('ChunkUpload: не заблокувати ' . $key);
        $until = microtime(true) + self::$lockWait;
        while (!flock($fh, LOCK_EX | LOCK_NB, $wouldBlock)) {
            if (!$wouldBlock) {
                fclose($fh);
                throw new \RuntimeException('ChunkUpload: не заблокувати ' . $key);
            }
            if (microtime(true) >= $until) {
                fclose($fh);
                throw new DesignerException(self::ERR_BUSY, 503);
            }
            usleep(200000);
        }
        @chmod($file, 0600);
        touch($file);   // свіжий mtime — sweep() не видалить файл, поки ним користуються
        self::$locks[$key] = $fh;
    }

    private static function unlock(string $key): void
    {
        if (!isset(self::$locks[$key])) return;
        flock(self::$locks[$key], LOCK_UN);
        fclose(self::$locks[$key]);
        unset(self::$locks[$key]);
    }

    /** Каталог результатів і блокувань: /mnt/work/tmp/tmp_uppy/design-results/ (0700) */
    private static function dir(): string
    {
        $dir = self::RESULTS_DIR;
        if (is_dir($dir)) return $dir;
        // Батьківські — як їх створює сам UppyChunkGlobal (0777), щоб рекурсивний mkdir не зробив їх 0700
        \FileGlobal::folder(dirname($dir) . '/');
        if (!@mkdir($dir, 0700) && !is_dir($dir)) {
            throw new \RuntimeException('ChunkUpload: не створити ' . $dir);
        }
        return $dir;
    }

    /** Прибрати прострочені результати й блокування */
    private static function sweep(): void
    {
        $old = time() - self::RESULT_TTL;
        foreach (glob(self::RESULTS_DIR . '*.{json,lock}', GLOB_BRACE) ?: [] as $file) {
            if (@filemtime($file) < $old) @unlink($file);
        }
    }

    /**
     * Розмір частини для браузера (data-chunk сторінки): min(CHUNK, upload_max_filesize, post_max_size) − SLACK —
     * частина з полями форми мусить пройти обидва ліміти PHP (на dev upload_max_filesize 2M, на проді 1000M → 5 МБ)
     */
    public static function partSize(): int
    {
        $limit = self::CHUNK;
        foreach (['upload_max_filesize', 'post_max_size'] as $ini) {
            $v = self::iniBytes((string)ini_get($ini));
            if ($v > 0) $limit = min($limit, $v);
        }
        return max(self::SLACK, $limit - self::SLACK);
    }

    /** «2M», «512K», «1G», «8388608» → байти; 0 / порожньо / незрозуміле — 0 (без ліміту) */
    public static function iniBytes(string $v): int
    {
        if (!preg_match('/^\s*(\d+)\s*([KMG]?)\s*$/iD', $v, $m)) return 0;
        return (int)$m[1] * match (strtoupper($m[2])) {
            'K' => 1024,
            'M' => 1048576,
            'G' => 1073741824,
            default => 1,
        };
    }

    /** Вихідне імʼя без шляху й керівних символів, до 255 символів; порожнє → «file» */
    public static function name(string $raw): string
    {
        $s = preg_replace('/[\p{Cc}\p{Cf}]/u', '', mb_scrub($raw, 'UTF-8'));
        $s = str_replace('\\', '/', $s);
        $slash = strrpos($s, '/');
        if ($slash !== false) $s = substr($s, $slash + 1);   // не basename(): та залежить від локалі на не-ASCII
        $s = trim(mb_substr(trim($s), 0, 255));
        return $s === '' || $s === '.' || $s === '..' ? 'file' : $s;
    }

    /** Розширення для тимчасового файлу: лише [a-z0-9]{1,10}, інакше «bin» */
    private static function ext(string $name): string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return preg_match('/^[a-z0-9]{1,10}$/D', $ext) ? $ext : 'bin';
    }

    /** Ціле ≥ 0 з поля форми або null */
    private static function int(mixed $v): ?int
    {
        if (is_int($v)) return $v >= 0 ? $v : null;
        return is_string($v) && preg_match('/^(0|[1-9][0-9]{0,11})$/D', $v) ? (int)$v : null;
    }

    /** Тимчасовий файл частини з поля «file» */
    private static function part(array $files): string
    {
        // «files» UppyChunkGlobal читає першим — щоб узяв саме перевірену частину, приймаємо лише «file»
        if (isset($files['files'])) throw new DesignerException(self::ERR_META);
        $f = $files['file'] ?? null;
        if (!is_array($f) || !is_int($f['error'] ?? null)) throw new DesignerException(self::ERR_NONE);
        switch ($f['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                throw new DesignerException(self::ERR_PART_BIG);
            case UPLOAD_ERR_PARTIAL:
                throw new DesignerException('Файл завантажився не повністю — спробуйте ще раз');
            case UPLOAD_ERR_NO_FILE:
                throw new DesignerException(self::ERR_NONE);
            default:
                throw new \RuntimeException('Збій завантаження частини на сервері, код ' . $f['error']);
        }
        $tmp = $f['tmp_name'] ?? null;
        // Лише файл, прийнятий PHP у цьому запиті: інакше move_uploaded_file в UppyChunkGlobal зробив би exit з 500
        if (!is_string($tmp) || $tmp === '' || !is_uploaded_file($tmp)) throw new DesignerException(self::ERR_NONE);
        if (filesize($tmp) > self::CHUNK + self::SLACK) throw new DesignerException(self::ERR_PART_BIG);
        return $tmp;
    }
}
