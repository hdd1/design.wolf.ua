<?php

namespace Design;

/**
 * Макети замовлення (design_files): файли для друку, які обраний дизайнер вантажить у замовлення «В роботі».
 * Лежать у Paths::layouts({orderId})/{id}.{ext} (папка 0755, файл 0644) — звичайні файли на локальному диску. Посилання
 * i.fastcdn (cf) — лише на куплені й лише клієнту (Workspace::layouts); тут їх немає.
 * Куплений макет (purchase_id не null) незмінний: ні заміни, ні перейменування, ні видалення.
 * Статус: 1 завантажено, 2 замінено, 3 видалено. Ліміти (кількість і сума) — лише по status = 1.
 * Кожна дія з макетом (крім перейменування) — системне повідомлення в листуванні обраної пропозиції (data.event, data.file_id);
 * сповіщення клієнту методи не шлють, а повертають notice (Workspace::notifyClient — сторінка шле його після відповіді).
 * Замок у транзакціях — рядок замовлення (як у Previews); md5 і перевірки файла — до транзакції,
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
    private const ERR_BOUGHT = 'Куплений макет змінити не можна';

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
     * @return array{id:int, label:string, size:int, notice:array} notice — що сповістити клієнту (Workspace::notifyClient)
     * @throws DesignerException доступ, ліміти, файл (текст для користувача)
     */
    public static function add(int $orderId, int $designerId, string $tmp, string $originalName): array
    {
        return self::store($orderId, $designerId, $tmp, $originalName, null);
    }

    /**
     * Замінює активний макет файлом $tmp: старий → status 2 (файл видаляється після коммиту), новий — з тим самим підписом.
     * @return array{id:int, label:string, size:int, notice:array} новий макет; notice — як в add
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
     * @return array notice — що сповістити клієнту (Workspace::notifyClient)
     * @throws DesignerException
     */
    public static function remove(int $fileId, int $designerId): array
    {
        $offer = null;
        $row = self::locked($fileId, $designerId, function (array $row, array $o) use (&$offer): void {
            $offer = $o;
            Sql::exec('UPDATE design_files SET status = ? WHERE id = ?', [self::DELETED, $row['id']]);
            Messages::system($o['id'], 'Макет «' . $row['label'] . '» видалено', ['event' => 'layout_delete', 'file_id' => (int)$row['id']]);
        });
        self::deleteFile((int)$row['order_id'], (string)$row['file']);
        return Workspace::notice($offer, $designerId);
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
     * Активні макети замовлення за порядком завантаження — без шляхів і посилань; purchased — макет куплено (purchase_id)
     * @return list<array{id:int, label:string, original_name:string, size:int, ext:string, created:string, purchased:bool}>
     */
    public static function forOrder(int $orderId): array
    {
        $rows = Sql::all('SELECT id, label, original_name, file, size, created, purchase_id FROM design_files WHERE order_id = ? AND status = ? ORDER BY id',
            [$orderId, self::ACTIVE]);
        return array_map(static fn(array $r): array => [
            'id' => (int)$r['id'],
            'label' => (string)$r['label'],
            'original_name' => (string)$r['original_name'],
            'size' => (int)$r['size'],
            'ext' => (string)pathinfo((string)$r['file'], PATHINFO_EXTENSION),
            'created' => (string)$r['created'],
            'purchased' => $r['purchase_id'] !== null,
        ], $rows);
    }

    /**
     * Файл макета на диску (без створення папок): {root}/layouts/{orderId}/{id}.{ext} — так його називає store()
     * @param array{id:int, ext:string} $layout рядок forOrder
     */
    public static function path(int $orderId, array $layout): string
    {
        return Paths::root() . '/layouts/' . $orderId . '/' . $layout['id'] . ($layout['ext'] === '' ? '' : '.' . $layout['ext']);
    }

    /**
     * Перевірка до прийому файла (перша частина завантаження, заявлений розмір): доступ, розмір одного макета, ліміти замовлення.
     * replaceFileId — замінюваний макет: має бути активним макетом цього замовлення і в підрахунок лімітів не входить.
     * Без замка: остаточно ліміти перевіряє add/replace після склеювання
     * @throws DesignerException
     */
    public static function precheck(int $orderId, int $designerId, int $declaredSize, ?int $replaceFileId = null): void
    {
        if (Previews::canWork($orderId, $designerId) === null) throw new DesignerException(self::ERR_ACCESS);
        if ($replaceFileId !== null) {
            $old = Sql::one('SELECT purchase_id FROM design_files WHERE id = ? AND order_id = ? AND status = ?', [$replaceFileId, $orderId, self::ACTIVE]);
            if ($old === null) throw new DesignerException(self::ERR_GONE);
            if ($old['purchase_id'] !== null) throw new DesignerException(self::ERR_BOUGHT);
        }
        self::checkSize($declaredSize);
        self::checkLimits($orderId, $declaredSize, $replaceFileId);
    }

    /**
     * Версія списку активних макетів: змінюється з додаванням, заміною, видаленням, перейменуванням і купівлею
     * (опитування листування порівнює її, щоб оновити панель «Макети» у співрозмовника)
     * @param list<array{id:int, label:string, purchased:bool}>|null $layouts уже прочитаний forOrder (без повторного запиту)
     */
    public static function rev(int $orderId, ?array $layouts = null): string
    {
        $layouts ??= self::forOrder($orderId);
        return substr(sha1(json_encode(array_map(static fn(array $l): array => [$l['id'], $l['label'], $l['purchased']], $layouts),
            JSON_UNESCAPED_UNICODE)), 0, 16);
    }

    /** Кількість активних макетів замовлення (для «чий хід») */
    public static function count(int $orderId): int
    {
        return (int)Sql::value('SELECT COUNT(*) FROM design_files WHERE order_id = ? AND status = ?', [$orderId, self::ACTIVE]);
    }

    /**
     * Спільна частина add/replace.
     * 1) Без замка: доступ, розмір, папка (0755), 0644 для $tmp, md5 — усе повільне до транзакції.
     * 2) Коротка транзакція з замком рядка замовлення: повторний доступ, (replace) старий → status 2, ліміти, рядок, системне
     *    повідомлення, rename $tmp → {id}.{ext}, коммит. Помилка — відкат, готовий файл видаляється.
     * 3) Після коммиту: видалення файла старого макета. Сповіщення клієнту — notice у відповіді (шле сторінка).
     * @return array{id:int, label:string, size:int, notice:array}
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
            self::checkSize($size);
            $dir = Paths::layouts($orderId);
            // Права — явно (umask php-fpm 022, а ChunkUpload кладе результат 0600): макет — звичайний файл 0644
            if (!chmod($tmp, 0644)) throw new \RuntimeException('Не вдалося виставити права файла макета');
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
                    $old = Sql::one('SELECT id, label, file, purchase_id FROM design_files WHERE id = ? AND order_id = ? AND status = ? FOR UPDATE',
                        [$replaceId, $orderId, self::ACTIVE]);
                    if ($old === null) throw new DesignerException(self::ERR_GONE);
                    if ($old['purchase_id'] !== null) throw new DesignerException(self::ERR_BOUGHT);
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
        return ['id' => $id, 'label' => $label, 'size' => $size, 'notice' => Workspace::notice($offer, $designerId)];
    }

    /**
     * Активний некуплений макет під замком замовлення: доступ дизайнера, status = 1, purchase_id null; $fn(row, offer)
     * виконується в транзакції. Немає макета й немає доступу — та сама відповідь (id не перебрати)
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
            $row = Sql::one('SELECT id, order_id, label, file, purchase_id FROM design_files WHERE id = ? AND status = ? FOR UPDATE', [$fileId, self::ACTIVE]);
            if ($row === null) throw new DesignerException(self::ERR_GONE);
            if ($row['purchase_id'] !== null) throw new DesignerException(self::ERR_BOUGHT);
            $fn($row, $offer);
            $pdo->commit();
            return $row;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** Розмір одного макета: 1 байт … layout_max_mb */
    private static function checkSize(int $size): void
    {
        if ($size <= 0) throw new DesignerException('Файл порожній');
        if ($size > Settings::layoutMaxBytes()) {
            throw new DesignerException('Файл більший за ' . View::bytes(Settings::layoutMaxBytes()) . ' — це максимум для одного макета');
        }
    }

    /** Ліміти активних макетів замовлення з новим файлом розміром $size (без макета $exclude — замінюваного) */
    private static function checkLimits(int $orderId, int $size, ?int $exclude = null): void
    {
        $r = Sql::one('SELECT COUNT(*) AS n, COALESCE(SUM(size), 0) AS total FROM design_files WHERE order_id = ? AND status = ? AND id <> ?',
            [$orderId, self::ACTIVE, $exclude ?? 0]);
        if ((int)$r['n'] >= Settings::layoutMaxFiles()) {
            throw new DesignerException('Макетів уже ' . Settings::layoutMaxFiles() . ' — це максимум');
        }
        if ((int)$r['total'] + $size > Settings::layoutMaxTotalBytes()) {
            throw new DesignerException('Разом не більше ' . View::bytes(Settings::layoutMaxTotalBytes()) . ' на замовлення');
        }
    }

    /** Видаляє файл макета з диска (після коммиту); збій лише в журнал — рядок уже не активний */
    private static function deleteFile(int $orderId, string $file): void
    {
        $path = Paths::root() . '/layouts/' . $orderId . '/' . basename($file);
        if ($file !== '' && is_file($path) && !@unlink($path)) {
            error_log("design: не вдалося видалити файл макета $path");
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
