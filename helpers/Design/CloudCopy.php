<?php

namespace Design;

/**
 * «Скопіювати в хмару» (етап 5): копія купленого макета в хмару клієнта на wolf.ua — звичайним файлом, як завантаження
 * в uploadStorage.php wolf.ua: ім'я hash_hmac('sha256', md5_file, customer_id) + розширення, шлях f/xx/yy/zz/решта
 * (Path::cloudPath(name, 1)), рядок customers_cloud (type_id 1, original_name «Дизайн №N — {label}.{ext}», file_size).
 *
 * Стан у design_files.cloud_status: 0 немає, 1 у черзі, 2 у хмарі (cloud_id), 3 помилка (cloud_error).
 * Кнопка лише ставить у чергу (request — тільки БД). Копіює фонове завдання run() (крон wolf.ua/cron/design_cloud_copy.php
 * раз на хвилину): /mnt/cloud на проді — Storage Box через sshfs, повільний, і його зависання вішає PHP-процеси, тож:
 * - до /mnt/cloud звертається лише run(), і кожна операція (test, mkdir, stat, cp, mv, rm) — окремий процес із тайм-аутом
 *   (set_time_limit не рахує час у системних викликах — завислий stat чекав би вічно); дерево хмари не обходиться;
 * - на початку прогону — маркер {root}/is_mount.txt (той самий, що RfGlobal::mount() cdn.wolf.ua, але через op() з тайм-аутом): sshfs відпав — /mnt/cloud порожня локальна
 *   папка, копія лягла б на диск сервера і сховалась би під точкою монтування; без маркера прогін нічого не бере;
 * - перший тайм-аут зупиняє прогін (хмара, певно, зависла): решта лишається в черзі до наступного;
 * - один прогін за раз (flock на локальному файлі): завислий прогін не множиться кроном;
 * - не більше limit макетів і не більше RUN_BUDGET секунд на нові копії за прогін;
 * - копія — у тимчасове ім'я поруч ({name}.part) і mv після звірки розміру: напівфайла під справжнім іменем не буває;
 *   збій — тимчасовий файл видаляється; старий {name}.part (прогін убили посеред копії) видаляється перед копією.
 * Превʼю: рядок пишеться з is_preview = 10 («готово або не треба», preview NULL) — крон api2 cloud/update-preview бере лише
 * is_preview = 0 і везе весь файл у Київ на рендер; макет у сотні МБ туди не йде, а хмара wolf.ua (customer-cloud.php) для
 * is_preview = 10 без preview показує значок за розширенням, як для файлів без превʼю.
 */
final class CloudCopy
{
    public const NONE = 0;
    public const QUEUED = 1;
    public const DONE = 2;
    public const FAILED = 3;

    /** customers_cloud.is_preview: «готово або не треба» — api2 cloud/update-preview рядок не чіпає */
    private const PREVIEW_SKIP = 10;
    private const DEFAULT_ROOT = '/mnt/cloud/';
    /** Позначка захопленого рядка (cloud_error при cloud_status 1) */
    private const MARK = 'copying';
    /** Після стількох секунд прогону нові копії не починаються (крон — раз на хвилину) */
    private const RUN_BUDGET = 45;
    /** Найнижча швидкість копіювання, на яку чекаємо, байт/с */
    private const MIN_SPEED = 1048576;
    /** customers_cloud.file_size — int */
    private const INT_MAX = 2147483647;
    /** Код винятку op(): не дочекались */
    private const TIMEOUT = 408;
    /** Маркер змонтованої хмари — той самий, що перевіряє RfGlobal::mount() cdn.wolf.ua (/mnt/cloud/is_mount.txt) */
    private const MOUNT_MARKER = 'is_mount.txt';

    /** Тайм-аут дрібної операції з хмарою (mkdir, stat, mv, rm), с. Тести зменшують */
    public static int $timeout = 20;
    /** Тайм-аут копіювання: copyBase + розмір / MIN_SPEED, с. Тести зменшують */
    public static int $copyBase = 60;
    /**
     * Тести: підміна команди перед запуском (імітація зависання)
     * @internal
     * @var (\Closure(list<string>): list<string>)|null
     */
    public static ?\Closure $command = null;

    public static function cloudRoot(): string
    {
        $env = getenv('DESIGN_CLOUD_ROOT');
        return rtrim(is_string($env) && $env !== '' ? $env : self::DEFAULT_ROOT, '/') . '/';
    }

    /** Відносний шлях файла хмари — як Path::cloudPath($name, 1) wolf.ua (тип «файл») */
    public static function cloudPath(string $name): string
    {
        return 'f/' . substr($name, 0, 2) . '/' . substr($name, 2, 2) . '/' . substr($name, 4, 2) . '/' . substr($name, 6);
    }

    /** Файл замка прогону (локальний диск, не хмара) */
    public static function lockFile(): string
    {
        return sys_get_temp_dir() . '/design-cloud-copy.lock';
    }

    /**
     * Клієнт замовлення ставить куплений макет у чергу: 0 / 3 → 1 (помилку стерто); 2 — без змін, якщо рядок у хмарі ще є
     * (клієнт видалив файл у хмарі wolf.ua — знову в чергу); 1 — без змін. Лише БД, хмари не торкається
     * @return int стан після запиту
     * @throws DesignerException не клієнт / немає макета, некуплений макет
     */
    public static function request(int $fileId, int $customerId): int
    {
        $row = Sql::one('SELECT f.status, f.purchase_id, o.customer_id FROM design_files f JOIN design_orders o ON o.id = f.order_id WHERE f.id = ?',
            [$fileId]);
        if ($row === null || (int)$row['customer_id'] !== $customerId) throw new DesignerException('Макет не знайдено');
        if ($row['purchase_id'] === null || (int)$row['status'] !== Layouts::ACTIVE) {
            throw new DesignerException('Скопіювати в хмару можна лише куплений макет');
        }
        Sql::exec('UPDATE design_files SET cloud_status = ?, cloud_error = NULL
                   WHERE id = ? AND (cloud_status IN (?, ?)
                     OR (cloud_status = ? AND NOT EXISTS (SELECT 1 FROM customers_cloud c WHERE c.id = design_files.cloud_id)))',
            [self::QUEUED, $fileId, self::NONE, self::FAILED, self::DONE]);
        return self::read('f.id = ?', $fileId)[$fileId];
    }

    /**
     * Стан копії активних макетів замовлення для панелі клієнта: id → 0..3. «У хмарі», а рядка customers_cloud уже немає
     * (клієнт видалив файл у хмарі wolf.ua) — 0. Лише БД
     * @return array<int, int>
     */
    public static function statuses(int $orderId): array
    {
        return self::read('f.order_id = ? AND f.status = ' . Layouts::ACTIVE, $orderId);
    }

    /** @return array<int, int> стан копії макетів за умовою $where з одним параметром */
    private static function read(string $where, int $param): array
    {
        $rows = Sql::all("SELECT f.id, f.cloud_status, c.id AS cloud_row FROM design_files f LEFT JOIN customers_cloud c ON c.id = f.cloud_id
                          WHERE $where", [$param]);
        $out = [];
        foreach ($rows as $r) {
            $s = (int)$r['cloud_status'];
            $out[(int)$r['id']] = $s === self::DONE && $r['cloud_row'] === null ? self::NONE : $s;
        }
        return $out;
    }

    /**
     * Фонове завдання: копіює макети з cloud_status = 1 (не більше $limit за прогін). Інший прогін працює — одразу 0
     * @return int скільки макетів тепер у хмарі
     */
    public static function run(int $limit = 5): int
    {
        // Файл замка міг створити інший користувач: у /tmp (sticky, fs.protected_regular) O_CREAT на чужий файл заборонено
        // навіть root — тоді відкриваємо наявний на читання (flock працює й так)
        $lock = @fopen(self::lockFile(), 'c') ?: @fopen(self::lockFile(), 'r');
        if ($lock === false) throw new \RuntimeException('Не вдалося відкрити замок ' . self::lockFile());
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return 0;
        }
        @chmod(self::lockFile(), 0666);   // крон і тести можуть бути різними користувачами
        try {
            // Хмара не змонтована (чи маркер не відповів за тайм-аут) — нічого не беремо, черга чекає наступного прогону.
            // Той самий маркер /mnt/cloud/is_mount.txt, що перевіряє RfGlobal::mount() (cdn.wolf.ua). Сам mount() не
            // викликаємо: він вимагає ще й київські /mnt/work2, робить die() і is_file без тайм-ауту (висить на завислому sshfs).
            // Без «--»: coreutils test його не розуміє (шлях і так абсолютний)
            try {
                $mounted = self::op(['test', '-f', self::cloudRoot() . self::MOUNT_MARKER], self::$timeout, 'перевірити хмару', true) !== null;
            } catch (\Throwable $e) {
                $mounted = false;
                error_log('design: копія макетів у хмару: ' . $e->getMessage());
            }
            if (!$mounted) {
                error_log('design: копія макетів у хмару: немає ' . self::cloudRoot() . self::MOUNT_MARKER . ' — хмара не змонтована, черга чекає');
                return 0;
            }
            // Замок наш — отже, позначки «копіюється» лишились від прогону, що помер посеред роботи: рядки знову вільні
            Sql::exec('UPDATE design_files SET cloud_error = NULL WHERE cloud_status = ? AND cloud_error = ?', [self::QUEUED, self::MARK]);
            $rows = Sql::all('SELECT f.id, f.order_id, f.label, f.file, o.customer_id FROM design_files f JOIN design_orders o ON o.id = f.order_id
                              WHERE f.cloud_status = ? AND f.cloud_error IS NULL ORDER BY f.id LIMIT ' . max(1, $limit), [self::QUEUED]);
            $started = microtime(true);
            $done = 0;
            foreach ($rows as $row) {
                if (microtime(true) - $started > self::RUN_BUDGET) break;
                // Захоплення умовним UPDATE (позначка MARK): рядок беремо, лише якщо він досі в черзі й нічий — запобіжник
                // поверх замка (замок — на одну машину; крон design_cloud_copy.php — лише на проді)
                if (Sql::exec('UPDATE design_files SET cloud_error = ? WHERE id = ? AND cloud_status = ? AND cloud_error IS NULL',
                        [self::MARK, $row['id'], self::QUEUED]) !== 1) {
                    continue;
                }
                $result = self::copyOne($row);
                if ($result === self::DONE) $done++;
                if ($result === self::TIMEOUT) break;   // хмара зависла — решта чекає наступного прогону
            }
            return $done;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Один макет: файл у хмару, рядок customers_cloud, стан 2; збій — стан 3, текст у cloud_error, тимчасовий файл видалено
     * @return int DONE | FAILED | TIMEOUT (збій через тайм-аут операції з хмарою — прогін зупиняється)
     */
    private static function copyOne(array $row): int
    {
        $id = (int)$row['id'];
        $part = null;
        try {
            $ext = (string)pathinfo((string)$row['file'], PATHINFO_EXTENSION);
            $src = Layouts::path((int)$row['order_id'], ['id' => $id, 'ext' => $ext]);
            clearstatcache(true, $src);
            if (!is_file($src)) throw new \RuntimeException('Немає файла макета ' . $src);
            $size = (int)filesize($src);
            $md5 = md5_file($src);
            if ($md5 === false) throw new \RuntimeException('Не вдалося прочитати файл макета ' . $src);
            $customerId = (int)$row['customer_id'];
            $name = hash_hmac('sha256', $md5, (string)$customerId) . ($ext === '' ? '' : '.' . $ext);
            $dest = self::cloudRoot() . self::cloudPath($name);

            self::op(['mkdir', '-p', '--', dirname($dest)], self::$timeout, 'створити папку в хмарі');
            // Той самий файл уже в хмарі (інший макет із тим самим вмістом, чи клієнт сам завантажував) — не копіюємо вдруге
            if (self::regularSize($dest) !== $size) {
                $part = $dest . '.part';
                // Старий тимчасовий файл (прогін убили посеред копії) — геть: cp у FIFO чи чуже повисло б
                self::op(['rm', '-f', '--', $part], self::$timeout, 'видалити старий тимчасовий файл у хмарі');
                self::op(['cp', '--', $src, $part], self::$copyBase + intdiv($size, self::MIN_SPEED), 'скопіювати в хмару');
                $got = self::regularSize($part);
                if ($got !== $size) throw new \RuntimeException("У хмарі $got байт замість $size");
                self::op(['mv', '-f', '-T', '--', $part, $dest], self::$timeout, 'перейменувати файл у хмарі');
                $part = null;
            }

            Sql::exec('INSERT INTO customers_cloud (type_id, customer_id, original_name, file, file_size, is_preview)
                       VALUES (1, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), created = CURRENT_TIMESTAMP',
                [$customerId, self::originalName((int)$row['order_id'], (string)$row['label'], $ext), $name, min($size, self::INT_MAX), self::PREVIEW_SKIP]);
            $cloudId = (int)Sql::pdo()->lastInsertId();
            Sql::exec('UPDATE design_files SET cloud_status = ?, cloud_id = ?, cloud_error = NULL WHERE id = ?', [self::DONE, $cloudId, $id]);
            return self::DONE;
        } catch (\Throwable $e) {
            if ($part !== null) {
                try {
                    self::op(['rm', '-f', '--', $part], self::$timeout, 'видалити тимчасовий файл у хмарі');
                } catch (\Throwable $e2) {
                    error_log("design: копія макета $id у хмару: " . $e2->getMessage());
                }
            }
            error_log("design: копія макета $id у хмару: " . $e->getMessage());
            Sql::exec('UPDATE design_files SET cloud_status = ?, cloud_error = ? WHERE id = ?',
                [self::FAILED, mb_substr($e->getMessage(), 0, 255), $id]);
            return $e->getCode() === self::TIMEOUT ? self::TIMEOUT : self::FAILED;
        }
    }

    /**
     * Назва для хмари wolf.ua: «Дизайн №N — {label}.{ext}». Хмара виводить original_name як є (uploadStorage кладе
     * html::encode), тож — htmlspecialchars так само; колонка utf8mb3 — 4-байтові символи (емодзі) прибрано
     */
    private static function originalName(int $orderId, string $label, string $ext): string
    {
        $name = 'Дизайн №' . $orderId . ' — ' . $label . ($ext === '' ? '' : '.' . $ext);
        $name = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $name) ?? '';
        return mb_substr(htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false), 0, 1024);
    }

    /** Розмір звичайного файла в хмарі; null — немає (чи не звичайний файл) */
    private static function regularSize(string $path): ?int
    {
        $r = self::op(['stat', '-c', '%F|%s', '--', $path], self::$timeout, 'перевірити файл у хмарі', true);
        if ($r === null || preg_match('/^regular (?:empty )?file\|(\d+)$/', trim($r), $m) !== 1) return null;
        return (int)$m[1];
    }

    /**
     * Команда окремим процесом із тайм-аутом. Не дочекались — процес убито (SIGKILL), виняток «тайм-аут».
     * $soft — ненульовий код виходу не помилка (повертає null); тайм-аут — завжди помилка
     * @param list<string> $cmd
     * @return string|null stdout; null — ненульовий код при $soft
     */
    private static function op(array $cmd, int $timeout, string $what, bool $soft = false): ?string
    {
        if (self::$command !== null) $cmd = (self::$command)($cmd);
        $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) throw new \RuntimeException("Не вдалося $what: процес не запустився");
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = $err = '';
        $deadline = microtime(true) + max(1, $timeout);
        while (true) {
            $out .= (string)stream_get_contents($pipes[1]);
            $err .= (string)stream_get_contents($pipes[2]);
            $st = proc_get_status($proc);
            if (!$st['running']) break;
            if (microtime(true) > $deadline) {
                proc_terminate($proc, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                // proc_close не викликаємо: процес у завислій ФС може не вийти одразу, а proc_close чекав би на нього
                throw new \RuntimeException("Не вдалося $what: тайм-аут {$timeout} с", self::TIMEOUT);
            }
            usleep(20000);
        }
        $out .= (string)stream_get_contents($pipes[1]);
        $err .= (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        $code = $st['exitcode'];
        if ($code === 0) return $out;
        if ($soft) return null;
        throw new \RuntimeException("Не вдалося $what: " . trim($err !== '' ? $err : "код $code"));
    }
}
