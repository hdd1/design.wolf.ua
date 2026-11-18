<?php

namespace Design;

/**
 * Роботи портфоліо дизайнера (design_portfolio + файли в сховищі).
 * Змінювати можна у чернетці, після відхилення і після схвалення; на розгляді й у блоці — лише читати.
 */
final class Portfolio
{
    public const MAX = 30;
    public const TITLE_MAX = 120;
    /** Рядок «в обробці» старший за це — обірваний запит (обробка триває секунди) */
    private const STALE_MINUTES = 10;

    private const ERR_READONLY = 'Зараз роботи не можна змінювати';
    private const ERR_NOT_FOUND = 'Роботу не знайдено';
    private const ERR_ORDER = 'Список робіт змінився — оновіть сторінку';

    /** Папка файлів робіт дизайнера; створюється при першому зверненні */
    public static function dir(int $designerId): string
    {
        $dir = Config::storageRoot() . "/portfolio/$designerId";
        Images::ensureDir($dir);
        return $dir;
    }

    /**
     * Додає роботу з файлу $tmp. Спершу рядок (потрібен id для імен файлів), потім Images::store;
     * якщо файл не підійшов або запис не вдався — рядок видаляється. Тимчасовий файл не чіпаємо.
     * Рядки «в обробці», старші за STALE_MINUTES, — сліди обірваних запитів (ліміт часу, перезапуск php-fpm):
     * прибираються разом із файлами, щоб не займати слот ліміту назавжди.
     * @return array{id:int}
     * @throws DesignerException
     */
    public static function add(int $designerId, string $tmp): array
    {
        $pdo = Sql::pdo();
        $pdo->beginTransaction();
        try {
            // Блокуємо рядок дизайнера: статус і ліміт перевіряються разом із вставкою, одночасні додавання не обійдуть ліміт
            $status = Sql::value('SELECT status FROM design_designers WHERE customer_id = ? FOR UPDATE', [$designerId]);
            $limit = self::limit($status === null ? null : (int)$status);
            if ($limit === null) throw new DesignerException(self::ERR_READONLY);
            $stale = self::dropStale($designerId);
            // Ліміт рахуємо по всіх рядках, разом із тими, що ще обробляються (file = ''), — щоб паралельні завантаження його не обійшли
            $total = (int)Sql::value('SELECT COUNT(*) FROM design_portfolio WHERE designer_id = ?', [$designerId]);
            $full = $total >= $limit;
            if (!$full) {
                $pos = (int)Sql::value('SELECT COALESCE(MAX(pos), 0) FROM design_portfolio WHERE designer_id = ?', [$designerId]) + 1;
                $id = Sql::insert('design_portfolio', ['designer_id' => $designerId, 'file' => '', 'pos' => $pos]);
            }
            // Комітимо й при повному ліміті: прибирання покинутих рядків не має відкотитися
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        foreach ($stale as $staleId) self::unlinkAny($designerId, $staleId);
        if ($full) throw new DesignerException("Уже $limit робіт — це максимум");

        try {
            $img = Images::store($tmp, self::dir($designerId), (string)$id);
            Sql::exec('UPDATE design_portfolio SET file = ?, width = ?, height = ? WHERE id = ?',
                [$img['file'], $img['width'], $img['height'], $id]);
        } catch (\Throwable $e) {
            Sql::exec('DELETE FROM design_portfolio WHERE id = ?', [$id]);
            self::unlinkFiles($designerId, $id, '');
            throw $e;
        }
        return ['id' => $id];
    }

    /** @return list<array{id:int, title:string, shw:bool, pos:int, width:int, height:int}> усі готові роботи в порядку показу */
    public static function all(int $designerId): array
    {
        return self::list($designerId, '');
    }

    /** @return list<array{id:int, title:string, shw:bool, pos:int, width:int, height:int}> лише показані */
    public static function visible(int $designerId): array
    {
        return self::list($designerId, ' AND shw = 1');
    }

    /** Готові роботи; ті, що ще обробляються (file = ''), не рахуються */
    public static function count(int $designerId): int
    {
        return (int)Sql::value("SELECT COUNT(*) FROM design_portfolio WHERE designer_id = ? AND file <> ''", [$designerId]);
    }

    /** @throws DesignerException */
    public static function remove(int $designerId, int $workId): void
    {
        $work = self::own($designerId, $workId);
        if (Sql::exec('DELETE FROM design_portfolio WHERE id = ? AND designer_id = ?', [$workId, $designerId]) === 0) {
            throw new DesignerException(self::ERR_NOT_FOUND);
        }
        self::unlinkFiles($designerId, $workId, $work['file']);
    }

    /** @throws DesignerException */
    public static function setShw(int $designerId, int $workId, bool $on): void
    {
        self::own($designerId, $workId);
        Sql::exec('UPDATE design_portfolio SET shw = ? WHERE id = ? AND designer_id = ?', [$on ? 1 : 0, $workId, $designerId]);
    }

    /** @throws DesignerException */
    public static function setTitle(int $designerId, int $workId, string $title): void
    {
        $title = trim($title);
        self::own($designerId, $workId);
        if (mb_strlen($title) > self::TITLE_MAX) throw new DesignerException('Назва — не більше ' . self::TITLE_MAX . ' символів');
        Sql::exec('UPDATE design_portfolio SET title = ? WHERE id = ? AND designer_id = ?', [$title, $workId, $designerId]);
    }

    /**
     * Новий порядок: pos = 1..N за переданим списком. Список має містити рівно всі роботи дизайнера, по разу.
     * @param list<int> $ids
     * @throws DesignerException
     */
    public static function reorder(int $designerId, array $ids): void
    {
        self::assertEditable($designerId);
        $ids = array_map('intval', array_values($ids));
        $pdo = Sql::pdo();
        $pdo->beginTransaction();
        try {
            // Той самий замок, що й в add: набір робіт не зміниться між перевіркою й записом
            Sql::value('SELECT status FROM design_designers WHERE customer_id = ? FOR UPDATE', [$designerId]);
            $have = array_map('intval', array_column(Sql::all('SELECT id FROM design_portfolio WHERE designer_id = ? AND file <> \'\'', [$designerId]), 'id'));
            $want = $ids;
            sort($have);
            sort($want);
            if ($have !== $want) throw new DesignerException(self::ERR_ORDER);
            foreach ($ids as $i => $id) {
                Sql::exec('UPDATE design_portfolio SET pos = ? WHERE id = ? AND designer_id = ?', [$i + 1, $id, $designerId]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** Ліміт робіт для статусу; null — змінювати не можна */
    private static function limit(?int $status): ?int
    {
        return match ($status) {
            DesignerStatus::DRAFT, DesignerStatus::REJECTED => Designers::APPLY_MAX,
            DesignerStatus::APPROVED => self::MAX,
            default => null,
        };
    }

    private static function assertEditable(int $designerId): void
    {
        $row = Designers::get($designerId);
        if (self::limit($row === null ? null : $row['status']) === null) throw new DesignerException(self::ERR_READONLY);
    }

    /**
     * Перевіряє статус і що робота належить дизайнеру.
     * @return array{id:int, file:string}
     */
    private static function own(int $designerId, int $workId): array
    {
        self::assertEditable($designerId);
        $row = Sql::one('SELECT id, file FROM design_portfolio WHERE id = ? AND designer_id = ? AND file <> \'\'', [$workId, $designerId]);
        if ($row === null) throw new DesignerException(self::ERR_NOT_FOUND);
        return ['id' => (int)$row['id'], 'file' => (string)$row['file']];
    }

    private static function list(int $designerId, string $extra): array
    {
        $rows = Sql::all(
            "SELECT id, title, shw, pos, width, height FROM design_portfolio WHERE designer_id = ? AND file <> ''$extra ORDER BY pos, id",
            [$designerId]);
        return array_map(static fn(array $r) => [
            'id' => (int)$r['id'],
            'title' => (string)$r['title'],
            'shw' => (bool)$r['shw'],
            'pos' => (int)$r['pos'],
            'width' => (int)$r['width'],
            'height' => (int)$r['height'],
        ], $rows);
    }

    /**
     * Видаляє свої рядки «в обробці», старші за STALE_MINUTES. Викликати під замком рядка дизайнера.
     * @return list<int> id видалених — їхні файли прибрати після коміту
     */
    private static function dropStale(int $designerId): array
    {
        $ids = array_map('intval', array_column(Sql::all(
            "SELECT id FROM design_portfolio WHERE designer_id = ? AND file = '' AND created < NOW() - INTERVAL " . self::STALE_MINUTES . ' MINUTE',
            [$designerId]), 'id'));
        if ($ids !== []) {
            Sql::exec('DELETE FROM design_portfolio WHERE designer_id = ? AND id IN (' . implode(',', $ids) . ')', [$designerId]);
        }
        return $ids;
    }

    /** Усе, що міг записати обірваний Images::store: {id}.* і {id}-*.webp */
    private static function unlinkAny(int $designerId, int $workId): void
    {
        $dir = self::dir($designerId);
        foreach (array_merge(glob("$dir/$workId.*") ?: [], glob("$dir/$workId-*.webp") ?: []) as $f) {
            if (is_file($f)) @unlink($f);
        }
    }

    /** Видаляє файли роботи: оригінал і обидві WebP-копії */
    private static function unlinkFiles(int $designerId, int $workId, string $file): void
    {
        $dir = self::dir($designerId);
        $names = ["$workId-card.webp", "$workId-view.webp"];
        // Файл ще не записано в рядок (збій посередині add) — оригінал міг лягти під будь-яким з двох розширень
        array_push($names, ...($file !== '' ? [basename($file)] : ["$workId.jpg", "$workId.png"]));
        foreach ($names as $n) {
            if (is_file("$dir/$n")) @unlink("$dir/$n");
        }
    }
}
