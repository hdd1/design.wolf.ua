<?php

namespace Design;

/**
 * PDO с подготовленными запросами для таблиц design_*.
 * cdn Db::insert не экранирует строки, похожие на JSON (апостроф ломает запрос), а остальные прогоняет
 * через htmlspecialchars — для наших данных он не годится.
 */
final class Sql
{
    private static ?\PDO $pdo = null;

    public static function pdo(): \PDO
    {
        return self::$pdo ??= new \PDO(
            'mysql:host=' . \CdnConfig::get('db_host') . ';port=' . \CdnConfig::get('db_port') . ';dbname=w2p;charset=utf8mb4',
            \CdnConfig::get('db_username'),
            \CdnConfig::get('db_password'),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC, \PDO::ATTR_EMULATE_PREPARES => false]
        );
    }

    public static function all(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    /** @return int число затронутых строк */
    public static function exec(string $sql, array $params = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    /** @param array<string, scalar|null> $row */
    public static function insert(string $table, array $row): int
    {
        $cols = array_keys($row);
        self::exec(
            'INSERT INTO `' . $table . '` (`' . implode('`, `', $cols) . '`) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')',
            array_values($row)
        );
        return (int)self::pdo()->lastInsertId();
    }
}
