<?php

namespace Design;

/** Дизайнери (design_designers): заявка, розгляд, каталог. Дизайнер — це клієнт wolf.ua */
final class Designers
{
    public const NAME_MAX = 80;
    public const ABOUT_MAX = 2000;
    public const COMMENT_MAX = 1000;
    public const APPLY_MIN = 3;
    public const APPLY_MAX = 10;
    public const PER_PAGE = 24;

    private const ERR_CHANGED = 'Статус заявки вже змінився — оновіть сторінку';
    private const ERR_LINK = 'Посилання має бути адресою сайту, напр. behance.net/імʼя';
    private const COLS = 'customer_id, status, name, about, link, photo, rating, reject_comment, created, submitted_at, reviewed_at, reviewed_by';

    /** @return array{customer_id:int, status:int, name:string, about:string, link:string, photo:string, rating:string, reject_comment:string, created:string, submitted_at:?string, reviewed_at:?string, reviewed_by:?int}|null */
    public static function get(int $customerId): ?array
    {
        $r = Sql::one('SELECT ' . self::COLS . ' FROM design_designers WHERE customer_id = ?', [$customerId]);
        return $r === null ? null : self::row($r);
    }

    public static function status(int $customerId): ?int
    {
        $s = Sql::value('SELECT status FROM design_designers WHERE customer_id = ?', [$customerId]);
        return $s === null ? null : (int)$s;
    }

    /** Існуюча анкета або нова чернетка з імʼям із профілю клієнта. Повторний виклик нічого не міняє */
    public static function start(int $customerId): array
    {
        $row = self::get($customerId);
        if ($row !== null) return $row;

        $name = (string)(Customers::header($customerId)['name'] ?? '');
        if ($name === 'Клієнт') $name = '';   // заглушка шапки, а не справжнє імʼя
        // IGNORE: два одночасні запити не мають упасти на PRIMARY KEY
        Sql::exec('INSERT IGNORE INTO design_designers (customer_id, status, name) VALUES (?, ?, ?)',
            [$customerId, DesignerStatus::DRAFT, mb_substr($name, 0, self::NAME_MAX)]);
        return self::get($customerId);
    }

    /** Текст зберігається як є (без екранування): екранує шаблон при виведенні */
    public static function saveProfile(int $customerId, string $name, string $about, string $link): void
    {
        $name = trim($name);
        $about = trim($about);
        if (mb_strlen($name) > self::NAME_MAX) throw new DesignerException('Імʼя — не більше ' . self::NAME_MAX . ' символів');
        if (mb_strlen($about) > self::ABOUT_MAX) throw new DesignerException('Про себе — не більше ' . self::ABOUT_MAX . ' символів');
        $link = self::normalizeLink($link);

        $editable = [DesignerStatus::DRAFT, DesignerStatus::REJECTED, DesignerStatus::APPROVED];
        if (!in_array(self::status($customerId), $editable, true)) {
            throw new DesignerException('Профіль зараз не можна змінити');
        }
        Sql::exec('UPDATE design_designers SET name = ?, about = ?, link = ? WHERE customer_id = ? AND status IN (?, ?, ?)',
            array_merge([$name, $about, $link, $customerId], $editable));
        // 0 рядків — або текст не змінився, або статус встиг змінитися (заблокували, подали заявку)
        if (!in_array(self::status($customerId), $editable, true)) {
            throw new DesignerException('Профіль зараз не можна змінити');
        }
    }

    /** Чернетка / відхилена → на розгляд. Повторна подача на розгляді нічого не робить і не шле зайвого сповіщення */
    public static function submit(int $customerId): void
    {
        $row = self::get($customerId);
        $status = $row['status'] ?? null;
        if ($status === DesignerStatus::PENDING) return;
        if ($status !== DesignerStatus::DRAFT && $status !== DesignerStatus::REJECTED) {
            throw new DesignerException('Заявку зараз не можна надіслати');
        }
        if (trim($row['name']) === '') throw new DesignerException('Вкажіть імʼя');
        if (trim($row['about']) === '') throw new DesignerException('Розкажіть про себе');
        $works = (int)Sql::value('SELECT COUNT(*) FROM design_portfolio WHERE designer_id = ? AND file <> \'\'', [$customerId]);
        if ($works < self::APPLY_MIN) throw new DesignerException('Додайте щонайменше ' . self::APPLY_MIN . ' роботи');
        if ($works > self::APPLY_MAX) throw new DesignerException('У заявці — не більше ' . self::APPLY_MAX . ' робіт');

        $n = Sql::exec(
            "UPDATE design_designers SET status = ?, submitted_at = NOW(), reject_comment = '' WHERE customer_id = ? AND status IN (?, ?)",
            [DesignerStatus::PENDING, $customerId, DesignerStatus::DRAFT, DesignerStatus::REJECTED]);
        if ($n === 0) {
            // Хтось устиг раніше: подвійна подача — не помилка, будь-що інше — так
            if (self::status($customerId) === DesignerStatus::PENDING) return;
            throw new DesignerException('Заявку зараз не можна надіслати');
        }

        $ownerId = (int)Sql::value("SELECT value FROM design_settings WHERE `key` = 'applications_notify_user_id'");
        if ($ownerId <= 0) {
            error_log('Design\\Designers: не задано design_settings.applications_notify_user_id — сповіщення про заявку не надіслано');
            return;
        }
        $msg = 'Майстерня дизайну: нова заявка дизайнера — ' . $row['name'] . ', ' . date('d.m H:i')
            . '. Розглянути: ' . Config::adminkaUrl() . '/design-applications/';
        Notify::send(['user_id' => $ownerId, 'msg' => $msg, 'msgSMS' => $msg]);
    }

    public static function approve(int $customerId, int $userId): void
    {
        self::review($customerId, $userId, [DesignerStatus::PENDING], DesignerStatus::APPROVED);
        $msg = 'Майстерня дизайну: вашу заявку дизайнера схвалено (' . date('d.m H:i') . '). Заповніть профіль: '
            . Config::siteUrl() . '/designer/?approved=1';
        Notify::send(['customer_id' => $customerId, 'msg' => $msg, 'msgSMS' => $msg]);
    }

    public static function reject(int $customerId, int $userId, string $comment): void
    {
        $comment = trim($comment);
        if ($comment === '') throw new DesignerException('Напишіть коментар для дизайнера');
        if (mb_strlen($comment) > self::COMMENT_MAX) throw new DesignerException('Коментар — не більше ' . self::COMMENT_MAX . ' символів');
        self::review($customerId, $userId, [DesignerStatus::PENDING], DesignerStatus::REJECTED, $comment);
        $msg = 'Майстерня дизайну: заявку дизайнера відхилено (' . date('d.m H:i') . '). Коментар і що виправити: '
            . Config::siteUrl() . '/designer/';
        Notify::send(['customer_id' => $customerId, 'msg' => $msg, 'msgSMS' => $msg]);
    }

    /** Заблокувати можна будь-кого, крім уже заблокованого */
    public static function block(int $customerId, int $userId): void
    {
        self::review($customerId, $userId,
            [DesignerStatus::DRAFT, DesignerStatus::PENDING, DesignerStatus::APPROVED, DesignerStatus::REJECTED],
            DesignerStatus::BLOCKED);
    }

    public static function unblock(int $customerId, int $userId): void
    {
        self::review($customerId, $userId, [DesignerStatus::BLOCKED], DesignerStatus::APPROVED);
    }

    /**
     * Заявки для adminka (без чернеток), нові зверху. Статус — лише PENDING/APPROVED/REJECTED/BLOCKED, інакше порожньо
     * @return list<array> рядок дизайнера (як get) + works — число робіт
     */
    public static function forAdmin(int $status): array
    {
        $allowed = [DesignerStatus::PENDING, DesignerStatus::APPROVED, DesignerStatus::REJECTED, DesignerStatus::BLOCKED];
        if (!in_array($status, $allowed, true)) return [];

        $rows = Sql::all(
            'SELECT ' . self::COLS . ', (SELECT COUNT(*) FROM design_portfolio p WHERE p.designer_id = d.customer_id AND p.file <> \'\') AS works
             FROM design_designers d WHERE d.status = ?
             ORDER BY d.submitted_at DESC, d.customer_id DESC', [$status]);
        return array_map(static fn(array $r): array => self::row($r) + ['works' => (int)$r['works']], $rows);
    }

    /**
     * Каталог: лише схвалені. Усі рівні, порядок змінюється раз на добу
     * @return array{items:list<array{customer_id:int,name:string,about:string,photo:string,works:list<int>}>, total:int, pages:int}
     */
    public static function catalog(string $q, int $page): array
    {
        $where = 'status = ?';
        $params = [DesignerStatus::APPROVED];
        $q = trim($q);
        if ($q !== '') {
            // | — службовий символ екранування: % і _ у пошуку шукаються як звичайні літери
            $where .= " AND name LIKE ? ESCAPE '|'";
            $params[] = '%' . str_replace(['|', '%', '_'], ['||', '|%', '|_'], $q) . '%';
        }
        $total = (int)Sql::value("SELECT COUNT(*) FROM design_designers WHERE $where", $params);
        $pages = max(1, (int)ceil($total / self::PER_PAGE));
        $offset = (max(1, $page) - 1) * self::PER_PAGE;

        $rows = Sql::all(
            "SELECT customer_id, name, about, photo FROM design_designers WHERE $where
             ORDER BY MD5(CONCAT(customer_id, CURDATE())), customer_id LIMIT " . self::PER_PAGE . " OFFSET $offset", $params);

        $works = [];
        if ($rows !== []) {
            $ids = array_map(static fn(array $r): int => (int)$r['customer_id'], $rows);
            $found = Sql::all(
                'SELECT id, designer_id FROM design_portfolio
                 WHERE shw = 1 AND file <> \'\' AND designer_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
                 ORDER BY pos, id', $ids);
            foreach ($found as $w) {
                $works[(int)$w['designer_id']][] = (int)$w['id'];
            }
        }
        $items = array_map(static fn(array $r): array => [
            'customer_id' => (int)$r['customer_id'],
            'name' => (string)$r['name'],
            'about' => (string)$r['about'],
            'photo' => (string)$r['photo'],
            'works' => array_slice($works[(int)$r['customer_id']] ?? [], 0, 3),
        ], $rows);
        return ['items' => $items, 'total' => $total, 'pages' => $pages];
    }

    /** @return array{customer_id:int, name:string, about:string, link:string, photo:string, works:list<array{id:int,title:string,file:string,width:int,height:int}>}|null лише схвалений */
    public static function publicProfile(int $customerId): ?array
    {
        $r = Sql::one('SELECT customer_id, name, about, link, photo FROM design_designers WHERE customer_id = ? AND status = ?',
            [$customerId, DesignerStatus::APPROVED]);
        if ($r === null) return null;

        $works = Sql::all('SELECT id, title, file, width, height FROM design_portfolio WHERE designer_id = ? AND shw = 1 AND file <> \'\' ORDER BY pos, id', [$customerId]);
        return [
            'customer_id' => (int)$r['customer_id'],
            'name' => (string)$r['name'],
            'about' => (string)$r['about'],
            'link' => (string)$r['link'],
            'photo' => (string)$r['photo'],
            'works' => array_map(static fn(array $w): array => [
                'id' => (int)$w['id'],
                'title' => (string)$w['title'],
                'file' => (string)$w['file'],
                'width' => (int)$w['width'],
                'height' => (int)$w['height'],
            ], $works),
        ];
    }

    /** Атомарний перехід: 0 рядків — статус уже змінили (другий адміністратор, подвійний клік) */
    private static function review(int $customerId, int $userId, array $from, int $to, ?string $comment = null): void
    {
        $set = 'status = ?, reviewed_at = NOW(), reviewed_by = ?';
        $params = [$to, $userId];
        if ($comment !== null) {
            $set .= ', reject_comment = ?';
            $params[] = $comment;
        }
        $n = Sql::exec(
            "UPDATE design_designers SET $set WHERE customer_id = ? AND status IN (" . implode(', ', array_fill(0, count($from), '?')) . ')',
            array_merge($params, [$customerId], $from));
        if ($n === 0) throw new DesignerException(self::ERR_CHANGED);
    }

    /** Порожньо — ок; без схеми дописуємо https://; лише http/https і схоже на адресу сайту */
    private static function normalizeLink(string $link): string
    {
        $link = trim($link);
        if ($link === '') return '';
        if (mb_strlen($link) > 255 || preg_match('~[\s\x00-\x1f<>"]~u', $link)) throw new DesignerException(self::ERR_LINK);

        if (!preg_match('~^[a-z][a-z0-9+.-]*://~i', $link)) {
            // «схема:» без // (javascript:, mailto:, data:) — не адреса сайту; «host:8080» — це порт
            if (preg_match('~^[a-z][a-z0-9+.-]*:(?!\d+(?:[/?#]|$))~i', $link)) throw new DesignerException(self::ERR_LINK);
            $link = 'https://' . $link;
        }
        $p = parse_url($link);
        if ($p === false
            || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true)
            || isset($p['user']) || isset($p['pass'])
            || !preg_match('~^[\p{L}\p{N}-]+(\.[\p{L}\p{N}-]+)+$~u', $p['host'] ?? '')
        ) {
            throw new DesignerException(self::ERR_LINK);
        }
        return $link;
    }

    private static function row(array $r): array
    {
        return [
            'customer_id' => (int)$r['customer_id'],
            'status' => (int)$r['status'],
            'name' => (string)$r['name'],
            'about' => (string)$r['about'],
            'link' => (string)$r['link'],
            'photo' => (string)$r['photo'],
            'rating' => (string)$r['rating'],
            'reject_comment' => (string)$r['reject_comment'],
            'created' => (string)$r['created'],
            'submitted_at' => $r['submitted_at'] === null ? null : (string)$r['submitted_at'],
            'reviewed_at' => $r['reviewed_at'] === null ? null : (string)$r['reviewed_at'],
            'reviewed_by' => $r['reviewed_by'] === null ? null : (int)$r['reviewed_by'],
        ];
    }
}
