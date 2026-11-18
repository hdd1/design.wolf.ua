<?php

namespace Design;

/**
 * Віддача WebP-картинок портфоліо через PHP (файли лежать у сховищі поза webroot).
 * Імʼя картинки без розширення: «{workId}-card», «{workId}-view» або «avatar-{8 hex}». Оригінали не віддаються ніколи.
 */
final class Media
{
    private const NAME_RE = '/^(?:(?<work>\d{1,9})-(?:card|view)|(?<avatar>avatar-[0-9a-f]{8}))$/';

    /** Відносна адреса картинки для сторінок design */
    public static function url(int $designerId, string $name): string
    {
        return "/media/$designerId/$name";
    }

    /** Абсолютна адреса з підписом і строком дії — для adminka, де немає куки design */
    public static function signedUrl(int $designerId, string $name, int $ttl = 3600, ?int $now = null): string
    {
        $exp = ($now ?? time()) + $ttl;
        return Config::siteUrl() . self::url($designerId, $name) . '?exp=' . $exp . '&sig=' . self::sign($designerId, $name, $exp);
    }

    /** Шлях до файлу. Папку не створює (на відміну від Portfolio::dir): читання не повинно залишати слідів у сховищі */
    public static function path(int $designerId, string $name): string
    {
        return Config::storageRoot() . "/portfolio/$designerId/$name.webp";
    }

    /**
     * Чи можна показати картинку. Спершу вона має існувати: робота — готовий рядок (file <> ''), аватар — поточний photo дизайнера.
     * Далі: власник — так; дійсний непрострочений підпис — так; вошедший клієнт — якщо дизайнер схвалений
     * і це аватар або показана робота; інакше ні.
     * @param array<string, mixed> $query $_GET: exp, sig
     */
    public static function canView(int $viewer, int $designerId, string $name, array $query, ?int $now = null): bool
    {
        if (!preg_match(self::NAME_RE, $name, $m)) return false;
        $isAvatar = ($m['avatar'] ?? '') !== '';

        if ($isAvatar) {
            $designer = Designers::get($designerId);
            if ($designer === null || $designer['photo'] !== "$name.webp") return false;
            $shown = true;
        } else {
            $work = Sql::one('SELECT shw FROM design_portfolio WHERE id = ? AND designer_id = ? AND file <> \'\'', [(int)$m['work'], $designerId]);
            if ($work === null) return false;
            $shown = (bool)$work['shw'];
            $designer = null;
        }

        if ($viewer > 0 && $viewer === $designerId) return true;
        if (self::signatureOk($designerId, $name, $query, $now ?? time())) return true;
        if ($viewer <= 0 || !$shown) return false;
        $status = $designer['status'] ?? Designers::status($designerId);
        return $status === DesignerStatus::APPROVED;
    }

    private static function signatureOk(int $designerId, string $name, array $query, int $now): bool
    {
        $exp = $query['exp'] ?? null;
        $sig = $query['sig'] ?? null;
        if (!is_string($exp) || !is_string($sig) || !preg_match('/^\d{1,12}$/', $exp)) return false;
        if ((int)$exp < $now) return false;
        return hash_equals(self::sign($designerId, $name, (int)$exp), $sig);
    }

    private static function sign(int $designerId, string $name, int $exp): string
    {
        return hash_hmac('sha256', "$designerId/$name/$exp", 'media|' . Config::csrfSecret());
    }
}
