<?php

namespace Design;

/** Один файл із multipart-запиту: тимчасовий шлях або зрозуміла помилка для рядка цього файлу */
final class Upload
{
    private const ERR_NONE = 'Файл не надіслано';

    /**
     * @param array $files  $_FILES
     * @param array $server $_SERVER (CONTENT_LENGTH — розмір, коли PHP файл відкинув)
     * @throws DesignerException помилка з боку користувача (розмір, обрив)
     * @throws \RuntimeException збій сервера (немає тимчасової папки, не записався)
     */
    public static function tmp(string $field, array $files, array $server): string
    {
        $length = (int)($server['CONTENT_LENGTH'] ?? 0);
        $f = $files[$field] ?? null;
        if ($f === null) {
            // Більше post_max_size: PHP відкидає і файли, і поля — лишається лише Content-Length
            if ($files === [] && $length > 0) throw new DesignerException(Images::tooBig($length));
            throw new DesignerException(self::ERR_NONE);
        }
        if (!is_array($f) || !is_int($f['error'] ?? null)) throw new DesignerException(self::ERR_NONE);   // file[] або сміття

        switch ($f['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                // Розміру PHP не повідомляє; запит несе один файл, тож Content-Length — майже його розмір
                throw new DesignerException(Images::tooBig($length > 0 ? $length : null));
            case UPLOAD_ERR_PARTIAL:
                throw new DesignerException('Файл завантажився не повністю — спробуйте ще раз');
            case UPLOAD_ERR_NO_FILE:
                throw new DesignerException(self::ERR_NONE);
            default:
                throw new \RuntimeException('Збій завантаження файлу на сервері, код ' . $f['error']);
        }
        $tmp = (string)($f['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) throw new DesignerException(self::ERR_NONE);
        return $tmp;
    }
}
