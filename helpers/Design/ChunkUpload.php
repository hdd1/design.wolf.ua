<?php

namespace Design;

/**
 * Приймання файлу частинами по 5 МБ (assets/js/chunk-upload.js) через спільний \UppyChunkGlobal (cdn.wolf.ua).
 * UppyChunkGlobal на поганих даних сам пише JSON і робить exit, тож усе перевіряється тут ДО його виклику.
 * Шлях до файлу від браузера не приймається: лише частина з multipart-запиту.
 */
final class ChunkUpload
{
    /** Розмір частини в браузері */
    public const CHUNK = 5 * 1048576;
    /** Запас на частину понад CHUNK */
    private const SLACK = 65536;

    private const ERR_META = 'Невірні дані завантаження. Оновіть сторінку і спробуйте ще раз';
    private const ERR_NONE = 'Файл не надіслано';
    private const ERR_PART_BIG = 'Частина файлу завелика. Оновіть сторінку і спробуйте ще раз';

    /**
     * Одна частина: meta[fileId], meta[chunkIndex], meta[totalChunks], meta[totalSize], meta[originalName] і файл «file».
     * Доступ і CSRF перевіряє сторінка — на кожну частину, до виклику.
     *
     * @param string $scope    "{customerId}:{orderId}:{purpose}" — частини різних людей/замовлень/призначень не змішуються
     * @param int    $maxBytes ліміт файлу
     * @return array{done:bool, path?:string, name?:string, size?:int} path — склеєний файл у /mnt/work/tmp/tmp_uppy/
     *         (його далі переносить або видаляє викликач); name — очищене вихідне імʼя
     * @throws DesignerException помилка з боку користувача
     */
    public static function receive(array $post, array $files, string $scope, int $maxBytes): array
    {
        if ($scope === '' || $maxBytes < 1) throw new \InvalidArgumentException('ChunkUpload: порожній scope або ліміт');

        $meta = is_array($post['meta'] ?? null) ? $post['meta'] : [];
        $fileId = $meta['fileId'] ?? null;
        $index = self::int($meta['chunkIndex'] ?? null);
        $total = self::int($meta['totalChunks'] ?? null);
        $size = self::int($meta['totalSize'] ?? null);
        if (!is_string($fileId) || !preg_match('/^[A-Za-z0-9_-]{8,64}$/D', $fileId)
            || $index === null || $total === null || $size === null
            || $total < 1 || $total > intdiv($maxBytes + self::CHUNK - 1, self::CHUNK) + 1 || $index >= $total) {
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

        $id = sha1($scope . '|' . $fileId);
        $result = \UppyChunkGlobal::upload(['meta' => [
            'fileId' => $id,
            'chunkIndex' => $index,
            'totalChunks' => $total,
            // Імʼя тимчасового файлу UppyChunkGlobal рахує як md5(originalName|time()): з унікальним id збігу між завантаженнями немає
            'originalName' => $id . '.' . self::ext($name),
        ]]);
        if (empty($result['done'])) return ['done' => false];

        $path = (string)$result['file'];
        clearstatcache(true, $path);
        $actual = (int)filesize($path);
        if ($actual > $maxBytes) {
            @unlink($path);
            throw new DesignerException(Images::tooBig($actual, $maxBytes));
        }
        return ['done' => true, 'path' => $path, 'name' => $name, 'size' => $actual];
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
        if (is_int($v)) return $v;
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
