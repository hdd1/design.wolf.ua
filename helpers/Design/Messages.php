<?php

namespace Design;

/** Листування по пропозиції: повідомлення, системні записи, «прочитано» і сповіщення про перше непрочитане */
final class Messages
{
    public const ROLE_CLIENT = 1;
    public const ROLE_DESIGNER = 2;
    public const ROLE_SYSTEM = 3;

    public const TEXT_MAX = 2000;

    /** Через скільки секунд після відкриття листування співрозмовник вважається «в чаті» і його не сповіщають */
    private const IN_CHAT_SECONDS = 45;

    /** Додає повідомлення. Права (чи може автор писати в цю пропозицію) не перевіряє */
    public static function add(int $offerId, int $authorId, int $role, string $text): int
    {
        $text = trim($text);
        if ($text === '') {
            throw new DesignerException('Напишіть повідомлення');
        }
        if (mb_strlen($text) > self::TEXT_MAX) {
            throw new DesignerException('Повідомлення — не більше ' . self::TEXT_MAX . ' символів');
        }
        return Sql::insert('design_messages', [
            'offer_id' => $offerId,
            'author_id' => $authorId,
            'author_role' => $role,
            'text' => $text,
        ]);
    }

    /** Системний запис у листуванні (зміна ціни, вибір тощо); data — машинні дані події */
    public static function system(int $offerId, string $text, array $data = []): int
    {
        return Sql::insert('design_messages', [
            'offer_id' => $offerId,
            'author_id' => 0,
            'author_role' => self::ROLE_SYSTEM,
            'text' => $text,
            'data' => $data === [] ? null : json_encode($data, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * Повідомлення після afterId за порядком; limit > 0 — не більше стількох (опитування листування)
     * @return list<array{id:int, role:int, author_id:int, text:string, data:?array, created:string}>
     */
    public static function list(int $offerId, int $afterId = 0, int $limit = 0): array
    {
        $rows = Sql::all(
            'SELECT id, author_role, author_id, text, data, created FROM design_messages WHERE offer_id = ? AND id > ? ORDER BY id'
            . ($limit > 0 ? ' LIMIT ' . $limit : ''),
            [$offerId, $afterId]
        );
        return array_map(static fn(array $r): array => [
            'id' => (int)$r['id'],
            'role' => (int)$r['author_role'],
            'author_id' => (int)$r['author_id'],
            'text' => (string)$r['text'],
            'data' => $r['data'] === null ? null : json_decode((string)$r['data'], true),
            'created' => (string)$r['created'],
        ], $rows);
    }

    /** Кількість повідомлень без системних */
    public static function count(int $offerId): int
    {
        return (int)Sql::value('SELECT COUNT(*) FROM design_messages WHERE offer_id = ? AND author_role <> ?', [$offerId, self::ROLE_SYSTEM]);
    }

    /** Сторона відкрила листування: seen_at = NOW() */
    public static function seen(int $offerId, int $role): void
    {
        $col = self::column($role, 'seen_at');
        Sql::exec("UPDATE design_offers SET $col = NOW() WHERE id = ?", [$offerId]);
    }

    /**
     * Сповіщає сторону про нове повідомлення — лише про перше непрочитане:
     * після попереднього сповіщення вона мала відкрити листування, і вона зараз не «в чаті» (відкрила понад 45 с тому).
     * Перевірка й notified_at = NOW() — один атомарний UPDATE на годиннику БД, тож паралельні повідомлення шлють одне сповіщення.
     * @return bool чи пішло сповіщення
     */
    public static function notifyNew(int $offerId, int $toRole, int $toId, string $fromName, int $orderId): bool
    {
        $seen = self::column($toRole, 'seen_at');
        $notified = self::column($toRole, 'notified_at');
        $claimed = Sql::exec(
            "UPDATE design_offers SET $notified = NOW() WHERE id = ?"
            . " AND ($notified IS NULL OR $seen >= $notified)"
            . " AND ($seen IS NULL OR $seen < NOW() - INTERVAL " . self::IN_CHAT_SECONDS . ' SECOND)',
            [$offerId]
        );
        if ($claimed === 0) {
            return false;
        }
        $path = $toRole === self::ROLE_CLIENT ? '/orders/' : '/feed/';
        Notify::send([
            'customer_id' => $toId,
            'msg' => 'Майстерня дизайну: нове повідомлення від ' . $fromName . ' по №' . $orderId . '. '
                . Config::siteUrl() . $path . $orderId . '/ (' . date('d.m H:i') . ')',
            'msgSMS' => 'Майстерня дизайну: нове повідомлення по №' . $orderId . '. design.wolf.ua',
        ]);
        return true;
    }

    /** Колонка design_offers для ролі: client_seen_at / designer_notified_at тощо */
    private static function column(int $role, string $suffix): string
    {
        return match ($role) {
            self::ROLE_CLIENT => 'client_' . $suffix,
            self::ROLE_DESIGNER => 'designer_' . $suffix,
            default => throw new \InvalidArgumentException('Невідома роль листування: ' . $role),
        };
    }
}
