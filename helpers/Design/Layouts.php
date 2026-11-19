<?php

namespace Design;

/**
 * Макети замовлення (design_files): файли для друку, які обраний дизайнер вантажить у замовлення «В роботі».
 * Лежать у Paths::layouts({orderId})/{id}.{ext} (папка 0700, файл 0600) — лише на локальному диску, назовні не віддаються.
 * Статус: 1 завантажено, 2 замінено, 3 видалено. Ліміти (кількість і сума) — лише по status = 1.
 * Кожна дія з макетом (крім перейменування) — системне повідомлення в листуванні обраної пропозиції (data.event, data.file_id)
 * і сповіщення клієнту. Замок у транзакціях — рядок замовлення (як у Previews); md5 і перевірки файла — до транзакції,
 * у ній лише рядки БД і rename (той самий диск, що й тимчасова папка завантаження, — миттєвий)
 */
final class Layouts
{
    public const ACTIVE = 1;
    public const REPLACED = 2;
    public const DELETED = 3;

    public const LABEL_MAX = 120;
    private const NAME_MAX = 255;

    private const ERR_ACCESS = 'Макети може завантажувати лише обраний дизайнер';
    private const ERR_GONE = 'Макет не знайдено: його вже замінено або видалено';

    /**
     * Тести: викликається перед транзакцією (md5 і перевірки вже зроблені) — щоб перевірити, що замок замовлення вільний,
     * і зламати rename
     * @internal
     * @var (\Closure(int, string): void)|null (orderId, tmp)
     */
    public static ?\Closure $beforeTransaction = null;

    /**
     * Новий макет із файлу $tmp (склеєне завантаження), підпис — ім'я файла без розширення (до 120 символів).
     * $tmp споживається в будь-якому разі: переноситься в папку макетів або (помилка) видаляється
     * @return array{id:int, label:string, size:int}
     * @throws DesignerException доступ, ліміти, файл (текст для користувача)
     */
    public static function add(int $orderId, int $designerId, string $tmp, string $originalName): array
    {
        return self::store($orderId, $designerId, $tmp, $originalName, null);
    }

    /**
     * Замінює активний макет файлом $tmp: старий → status 2 (файл видаляється після коммиту), новий — з тим самим підписом.
     * @return array{id:int, label:string, size:int} новий макет
     * @throws DesignerException
     */
    public static function replace(int $fileId, int $designerId, string $tmp, string $originalName): array
    {
        $orderId = Sql::value('SELECT order_id FROM design_files WHERE id = ?', [$fileId]);
        if ($orderId === null) {
            if (is_file($tmp)) @unlink($tmp);
            throw new DesignerException(self::ERR_ACCESS);
        }
        return self::store((int)$orderId, $designerId, $tmp, $originalName, $fileId);
    }

    /**
     * Видаляє активний макет: status 3, файл видаляється з диска після коммиту
     * @throws DesignerException
     */
    public static function remove(int $fileId, int $designerId): void
    {
        $offer = null;
        $row = self::locked($fileId, $designerId, function (array $row, array $o) use (&$offer): void {
            $offer = $o;
            Sql::exec('UPDATE design_files SET status = ? WHERE id = ?', [self::DELETED, $row['id']]);
            Messages::system($o['id'], 'Макет «' . $row['label'] . '» видалено', ['event' => 'layout_delete', 'file_id' => (int)$row['id']]);
        });
        self::deleteFile((int)$row['order_id'], (string)$row['file']);
        self::notify($offer, $designerId);
    }

    /**
     * Змінює підпис активного макета: 1–120 символів без керівних, без повідомлення
     * @throws DesignerException
     */
    public static function rename(int $fileId, int $designerId, string $label): void
    {
        $label = self::cleanText($label);
        if ($label === '' || mb_strlen($label) > self::LABEL_MAX) {
            throw new DesignerException('Підпис макета — від 1 до ' . self::LABEL_MAX . ' символів');
        }
        self::locked($fileId, $designerId, function (array $row) use ($label): void {
            Sql::exec('UPDATE design_files SET label = ? WHERE id = ?', [$label, $row['id']]);
        });
    }

    /**
     * Активні макети замовлення за порядком завантаження — без шляхів і посилань
     * @return list<array{id:int, label:string, original_name:string, size:int, ext:string, created:string}>
     */
    public static function forOrder(int $orderId): array
    {
        $rows = Sql::all('SELECT id, label, original_name, file, size, created FROM design_files WHERE order_id = ? AND status = ? ORDER BY id',
            [$orderId, self::ACTIVE]);
        return array_map(static fn(array $r): array => [
            'id' => (int)$r['id'],
            'label' => (string)$r['label'],
            'original_name' => (string)$r['original_name'],
            'size' => (int)$r['size'],
            'ext' => (string)pathinfo((string)$r['file'], PATHINFO_EXTENSION),
            'created' => (string)$r['created'],
        ], $rows);
    }

    /** Кількість активних макетів замовлення (для «чий хід») */
    public static function count(int $orderId): int
    {
        return (int)Sql::value('SELECT COUNT(*) FROM design_files WHERE order_id = ? AND status = ?', [$orderId, self::ACTIVE]);
    }

    /**
     * Спільна частина add/replace.
     * 1) Без замка: доступ, розмір, папка (0700), 0600 для $tmp, md5 — усе повільне до транзакції.
     * 2) Коротка транзакція з замком рядка замовлення: повторний доступ, (replace) старий → status 2, ліміти, рядок, системне
     *    повідомлення, rename $tmp → {id}.{ext}, коммит. Помилка — відкат, готовий файл видаляється.
     * 3) Після коммиту: видалення файла старого макета, сповіщення клієнту.
     * @return array{id:int, label:string, size:int}
     */
    private static function store(int $orderId, int $designerId, string $tmp, string $originalName, ?int $replaceId): array
    {
        $final = '';
        try {
            if (Previews::canWork($orderId, $designerId) === null) throw new DesignerException(self::ERR_ACCESS);
            [$base, $ext] = self::splitName($originalName);
            clearstatcache(true, $tmp);
            if (!is_file($tmp)) throw new DesignerException('Файл не знайдено — завантажте його ще раз');
            $size = (int)filesize($tmp);
            if ($size <= 0) throw new DesignerException('Файл порожній');
            if ($size > Settings::layoutMaxBytes()) {
                throw new DesignerException('Файл більший за ' . View::bytes(Settings::layoutMaxBytes()) . ' — це максимум для одного макета');
            }
            $dir = self::dir($orderId);
            // Права — до перенесення: у папку макетів файл потрапляє вже закритим для «інших»
            if (!chmod($tmp, 0600)) throw new \RuntimeException('Не вдалося закрити права файла макета');
            $md5 = md5_file($tmp);
            if ($md5 === false) throw new \RuntimeException('Не вдалося порахувати md5 файла макета');
            if (self::$beforeTransaction !== null) (self::$beforeTransaction)($orderId, $tmp);

            $pdo = Sql::pdo();
            $pdo->beginTransaction();
            try {
                Sql::one('SELECT id FROM design_orders WHERE id = ? FOR UPDATE', [$orderId]);
                $offer = Previews::canWork($orderId, $designerId);
                if ($offer === null) throw new DesignerException(self::ERR_ACCESS);
                $old = null;
                $label = $base;
                if ($replaceId !== null) {
                    $old = Sql::one('SELECT id, label, file FROM design_files WHERE id = ? AND order_id = ? AND status = ? FOR UPDATE',
                        [$replaceId, $orderId, self::ACTIVE]);
                    if ($old === null) throw new DesignerException(self::ERR_GONE);
                    Sql::exec('UPDATE design_files SET status = ? WHERE id = ?', [self::REPLACED, $replaceId]);
                    $label = (string)$old['label'];
                }
                self::checkLimits($orderId, $size);
                $id = Sql::insert('design_files', [
                    'order_id' => $orderId,
                    'label' => $label,
                    'original_name' => self::originalName($originalName),
                    'size' => $size,
                    'md5' => $md5,
                ]);
                $file = $id . ($ext === '' ? '' : '.' . $ext);
                Sql::exec('UPDATE design_files SET file = ? WHERE id = ?', [$file, $id]);
                $event = $old === null ? 'layout_add' : 'layout_replace';
                Messages::system($offer['id'], $old === null
                    ? 'Завантажено макет «' . $label . '» · ' . View::bytes($size)
                    : 'Макет «' . $label . '» замінено', ['event' => $event, 'file_id' => $id]);
                if (!@rename($tmp, "$dir/$file")) throw new \RuntimeException('Не вдалося перенести макет у ' . $dir);
                $final = "$dir/$file";
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
        } catch (\Throwable $e) {
            if ($final !== '') @unlink($final);
            throw $e;
        } finally {
            if (is_file($tmp)) @unlink($tmp);
        }

        if ($old !== null) self::deleteFile($orderId, (string)$old['file']);
        self::notify($offer, $designerId);
        return ['id' => $id, 'label' => $label, 'size' => $size];
    }

    /**
     * Активний макет під замком замовлення: доступ дизайнера, status = 1; $fn(row, offer) виконується в транзакції.
     * Немає макета й немає доступу — та сама відповідь (id не перебрати)
     * @param \Closure(array, array): void $fn
     * @return array рядок макета (id, order_id, label, file)
     * @throws DesignerException
     */
    private static function locked(int $fileId, int $designerId, \Closure $fn): array
    {
        $orderId = Sql::value('SELECT order_id FROM design_files WHERE id = ?', [$fileId]);
        if ($orderId === null) throw new DesignerException(self::ERR_ACCESS);
        $orderId = (int)$orderId;

        $pdo = Sql::pdo();
        $pdo->beginTransaction();
        try {
            Sql::one('SELECT id FROM design_orders WHERE id = ? FOR UPDATE', [$orderId]);
            $offer = Previews::canWork($orderId, $designerId);
            if ($offer === null) throw new DesignerException(self::ERR_ACCESS);
            $row = Sql::one('SELECT id, order_id, label, file FROM design_files WHERE id = ? AND status = ? FOR UPDATE', [$fileId, self::ACTIVE]);
            if ($row === null) throw new DesignerException(self::ERR_GONE);
            $fn($row, $offer);
            $pdo->commit();
            return $row;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** Ліміти активних макетів замовлення з новим файлом розміром $size (під замком замовлення) */
    private static function checkLimits(int $orderId, int $size): void
    {
        $r = Sql::one('SELECT COUNT(*) AS n, COALESCE(SUM(size), 0) AS total FROM design_files WHERE order_id = ? AND status = ?',
            [$orderId, self::ACTIVE]);
        if ((int)$r['n'] >= Settings::layoutMaxFiles()) {
            throw new DesignerException('Макетів уже ' . Settings::layoutMaxFiles() . ' — це максимум');
        }
        if ((int)$r['total'] + $size > Settings::layoutMaxTotalBytes()) {
            throw new DesignerException('Разом не більше ' . View::bytes(Settings::layoutMaxTotalBytes()) . ' на замовлення');
        }
    }

    /**
     * Папка макетів замовлення, створена й гарантовано 0700 (Paths::layouts наявні папки не чіпає):
     * якщо права інші й виправити не вдалося — файли не кладемо, бо nginx / «інші» могли б їх прочитати
     */
    private static function dir(int $orderId): string
    {
        $dir = Paths::layouts($orderId);
        clearstatcache(true, $dir);
        if ((fileperms($dir) & 0777) !== 0700) {
            if (!@chmod($dir, 0700)) throw new \RuntimeException('Папка макетів ' . $dir . ' має не 0700 і права не виправити');
            clearstatcache(true, $dir);
            if ((fileperms($dir) & 0777) !== 0700) throw new \RuntimeException('Папка макетів ' . $dir . ' має не 0700');
        }
        return $dir;
    }

    /** Видаляє файл макета з диска (після коммиту); збій лише в журнал — рядок уже не активний */
    private static function deleteFile(int $orderId, string $file): void
    {
        $path = Paths::root() . '/layouts/' . $orderId . '/' . basename($file);
        if ($file !== '' && is_file($path) && !@unlink($path)) {
            error_log("design: не вдалося видалити файл макета $path");
        }
    }

    /** Сповіщення клієнту після коммиту: його збій не скасовує дію з макетом */
    private static function notify(array $offer, int $designerId): void
    {
        try {
            Messages::notifyNew($offer['id'], Messages::ROLE_CLIENT, $offer['customer_id'],
                Designers::get($designerId)['name'] ?? 'дизайнера', $offer['order_id']);
        } catch (\Throwable $e) {
            error_log("design: сповіщення про макет замовлення {$offer['order_id']}: " . $e);
        }
    }

    /**
     * Ім'я файла від браузера → [підпис без розширення (до 120), розширення].
     * Розширення — після останньої крапки, лише [a-z0-9]{1,10} (нижній регістр), інакше його немає, а підписом лишається все ім'я.
     * Імена на диску складаються лише з id та цього розширення
     * @return array{0:string, 1:string}
     */
    private static function splitName(string $originalName): array
    {
        $name = self::originalName($originalName);
        $ext = '';
        $base = $name;
        $dot = strrpos($name, '.');
        if ($dot !== false && $dot > 0) {
            $tail = strtolower(substr($name, $dot + 1));
            if (preg_match('/^[a-z0-9]{1,10}$/', $tail) === 1) {
                $ext = $tail;
                $base = substr($name, 0, $dot);
            }
        }
        $base = trim(mb_substr(trim($base), 0, self::LABEL_MAX));
        return [$base === '' ? 'Макет' : $base, $ext];
    }

    /** Ім'я файла для показу: без шляху, керівних і невидимих символів, до 255 символів */
    private static function originalName(string $name): string
    {
        $name = self::cleanText(preg_replace('~^.*[/\\\\]~su', '', mb_scrub($name)) ?? '');
        return mb_substr($name, 0, self::NAME_MAX);
    }

    /** Текст без керівних, форматувальних і невидимих символів (переноси рядків, RTL-перевертання), обрізаний */
    private static function cleanText(string $s): string
    {
        return trim(preg_replace('/[\p{C}\p{Zl}\p{Zp}]/u', '', mb_scrub($s)) ?? '');
    }
}
