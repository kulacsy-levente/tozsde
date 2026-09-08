<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;
use RuntimeException;

/**
 * Vékony PDO-burkoló SQLite fölé.
 *
 * WAL módban fut, hogy az olvasás (a másodpercenkénti tick-kérés) ne akadjon el
 * egy párhuzamosan futó íráson.
 */
final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $path = config('db_path');
        $dir  = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException("Nem sikerult letrehozni a db mappat: $dir");
        }

        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        self::$pdo = $pdo;
        return $pdo;
    }

    /**
     * A kapcsolat elengedése.
     *
     * Windowson a nyitott SQLite-fájl nem törölhető, és a saját kapcsolatunk is
     * számít nyitottnak — az újratelepítésnek tehát előbb el kell engednie.
     */
    public static function close(): void
    {
        self::$pdo = null;
    }

    /** Le van-e már futtatva a séma? */
    public static function isInstalled(): bool
    {
        try {
            $row = self::pdo()
                ->query("SELECT name FROM sqlite_master WHERE type='table' AND name='market_state'")
                ->fetch();
            return $row !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** A séma lefuttatása (idempotens, mindenhol IF NOT EXISTS). */
    public static function install(): void
    {
        $sql = file_get_contents(config('schema_path'));
        if ($sql === false) {
            throw new RuntimeException('Nem olvashato a schema.sql');
        }
        self::pdo()->exec($sql);

        // A CREATE TABLE IF NOT EXISTS nem bővíti a már létező táblákat,
        // ezért az utólag hozzávett oszlopokat külön kell pótolni.
        self::ensureColumn('candles_1d', 'bmin', 'REAL');
        self::ensureColumn('candles_1d', 'bmax', 'REAL');
    }

    /** Oszlop hozzáadása, ha még nincs meg. */
    public static function ensureColumn(string $table, string $column, string $definition): void
    {
        foreach (self::all("PRAGMA table_info($table)") as $existing) {
            if (strcasecmp((string) $existing['name'], $column) === 0) {
                return;
            }
        }
        self::run("ALTER TABLE $table ADD COLUMN $column $definition");
    }

    public static function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /** Egyetlen sor, vagy null. */
    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** Egyetlen skalár érték az első oszlopból. */
    public static function value(string $sql, array $params = [], $default = null)
    {
        $value = self::run($sql, $params)->fetchColumn();
        return $value === false ? $default : $value;
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql  = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $cols),
            implode(', ', array_map(fn($c) => ':' . $c, $cols))
        );
        self::run($sql, $data);
        return (int) self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $sets = implode(', ', array_map(fn($c) => "$c = :set_$c", array_keys($data)));
        $params = [];
        foreach ($data as $k => $v) {
            $params['set_' . $k] = $v;
        }
        $stmt = self::run("UPDATE $table SET $sets WHERE $where", $params + $whereParams);
        return $stmt->rowCount();
    }

    /**
     * Callback futtatása tranzakcióban. A pénzügyi műveletek mind ezen mennek
     * keresztül, hogy ne maradhasson fél-végrehajtott trade.
     */
    public static function transaction(callable $fn)
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $fn($pdo);   // beágyazott hívás: a külső tranzakció visz mindent
        }
        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Tömeges beszúrás egyetlen tranzakcióban, darabolva.
     * A gyertyagenerálás ezzel ír be több tízezer sort elfogadható idő alatt.
     */
    public static function bulkInsert(string $table, array $columns, array $rows, string $orIgnore = 'OR REPLACE'): int
    {
        if (!$rows) {
            return 0;
        }
        $colList     = implode(', ', $columns);
        $placeholder = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
        $chunkSize   = (int) floor(900 / max(1, count($columns)));
        $total       = 0;

        self::transaction(function () use ($rows, $chunkSize, $table, $colList, $placeholder, $orIgnore, &$total) {
            foreach (array_chunk($rows, $chunkSize) as $chunk) {
                $sql = "INSERT $orIgnore INTO $table ($colList) VALUES "
                     . implode(', ', array_fill(0, count($chunk), $placeholder));
                $flat = [];
                foreach ($chunk as $row) {
                    foreach ($row as $v) {
                        $flat[] = $v;
                    }
                }
                self::run($sql, $flat);
                $total += count($chunk);
            }
        });

        return $total;
    }

    /** Kulcs-érték beállítás olvasása a settings táblából. */
    public static function setting(string $key, $default = null)
    {
        $v = self::value('SELECT value FROM settings WHERE key = ?', [$key]);
        return $v === null ? $default : $v;
    }

    public static function setSetting(string $key, $value): void
    {
        self::run(
            'INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value',
            [$key, (string) $value]
        );
    }
}
