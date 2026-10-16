<?php declare(strict_types=1);

namespace Flora\Driver;

use PDOException;
use Throwable;
use Flora\Exception\{AlreadyExists, Contended, InvalidArgument, NotFound, NotUnique};

/**
 * SQLite driver (3.38 and later). See AbstractVirtualColumnsDriver for how collections and indexes are stored.
 *
 * The data column is TEXT checked by json_valid(), read with the -> operator, which returns JSON text. Index keys have
 * no size limit, so they are never cut, and integers keep their exact value. Strings sort by code point.
 *
 * Objects used as terms are compared as written: {"a": 1, "b": 2} and {"b": 2, "a": 1} are different terms. Refs are
 * always written the same way.
 *
 * Unlike MySQL and MariaDB, SQLite rolls back schema changes with the rest of the transaction.
 *
 * Transactions begin IMMEDIATE: a query takes the write lock before it reads, waiting for it up to the busy timeout of
 * the connection (PDO::ATTR_TIMEOUT, 60 seconds by default). A deferred transaction that reads, then writes, would fail
 * at once when another connection is writing: SQLite cannot make it wait without risking a deadlock.
 */
final readonly class SqliteDriver extends AbstractVirtualColumnsDriver
{

    /**
     * SQLite runs in the process: there are no round trips to save, and long statements take longer to parse.
     *
     * @var positive-int
     */
    protected const int BATCH = 10;

    private const string MIN_VERSION = '3.38.0';

    private const int ERROR = 1;

    private const int BUSY = 5;

    private const int LOCKED = 6;

    private const int CONSTRAINT = 19;

    private const string UNIQUE_FAILED = 'UNIQUE constraint failed: ';

    protected function pdoDriver(): string
    {
        return 'sqlite';
    }

    protected function checkServer(): void
    {
        $version = Row::toString(($this->pdo->query('SELECT sqlite_version()') ?: null)?->fetchColumn(), 'version');
        if (version_compare($version, self::MIN_VERSION, '<')) {
            throw new InvalidArgument(sprintf('SQLite %s is too old: SqliteDriver needs %s or later.', $version, self::MIN_VERSION));
        }
    }

    protected function createTable(string $table): string
    {
        // INTEGER PRIMARY KEY makes id the rowid, which holds any 64-bit integer.
        return sprintf('CREATE TABLE %s (id INTEGER PRIMARY KEY, ts INTEGER NOT NULL, data TEXT NOT NULL CHECK (json_valid(data)))', $table);
    }

    protected function indexStatements(string $table, array $columns, string $index, array $keys, bool $unique): array
    {
        // SQLite adds one column per ALTER TABLE.
        $statements = array_map(static fn (array $column): string => sprintf('ALTER TABLE %s ADD COLUMN %s %s', $table, $column['name'], $column['definition']), $columns);
        $statements[] = sprintf('CREATE %sINDEX %s ON %s (%s)', $unique ? 'UNIQUE ' : '', $index, $table, implode(', ', $keys));

        return $statements;
    }

    protected function dropIndexStatements(string $table, string $index, array $columns): array
    {
        // A column cannot be dropped while an index uses it, and SQLite drops one per ALTER TABLE.
        return [sprintf('DROP INDEX %s', $index), ...array_map(static fn (string $column): string => sprintf('ALTER TABLE %s DROP COLUMN %s', $table, $column), $columns)];
    }

    protected function lockForUpdate(): string
    {
        // SQLite has a single writer: the transaction holds the database until it ends.
        return '';
    }

    protected function translate(PDOException $e, ?string $collection): ?Throwable
    {
        $code = $e->errorInfo[1] ?? null;
        $message = self::message($e);

        return match (true) {
            $code === self::ERROR && str_starts_with($message, 'no such table') => new NotFound(sprintf('Collection "%s" does not exist.', $collection ?? '?'), 0, $e),
            $code === self::ERROR && str_ends_with($message, 'already exists') => new AlreadyExists('A table with this name already exists in the database.', 0, $e),
            $code === self::CONSTRAINT && str_starts_with($message, self::UNIQUE_FAILED) => new NotUnique(self::uniqueIndex($message), $e),
            $code === self::BUSY, $code === self::LOCKED => new Contended('The database is locked by another connection: the query wrote nothing, and can be run again.', 0, $e),
            default => null,
        };
    }

    /**
     * PDO's beginTransaction() runs a deferred BEGIN (or, from PHP 8.5, what the connection was set to).
     */
    protected function begin(): void
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
    }

    protected function commit(): void
    {
        $this->pdo->exec('COMMIT');
    }

    protected function rollBack(): void
    {
        $this->pdo->exec('ROLLBACK');
    }

    protected function isPrimaryKeyConflict(PDOException $e): bool
    {
        // "UNIQUE constraint failed: users.id"
        return str_ends_with(self::message($e), '.id');
    }

    /**
     * The index whose columns a unique constraint failed on: "users.flora_users_by_email_t0, ..." for the columns of the
     * collection, "flora_entries_users_by_tag.t0, ..." for those of a table of entries.
     */
    private static function uniqueIndex(string $message): ?string
    {
        foreach (explode(', ', substr($message, strlen(self::UNIQUE_FAILED))) as $column) {
            if (preg_match('/^flora_entries_(\w+)\./', $column, $match) === 1
                || preg_match('/\.flora_(\w+)_(?:t\d+|v\d+_(?:type|num|str))$/', $column, $match) === 1) {
                return $match[1];
            }
        }

        return null;
    }

    /**
     * The message of the database in an error.
     */
    private static function message(PDOException $e): string
    {
        $message = $e->errorInfo[2] ?? null;

        return is_string($message) ? $message : '';
    }

    protected function rangesOnRowValues(): bool
    {
        return true;
    }

    protected function extract(string $path): string
    {
        return sprintf('(data -> %s)', $path);
    }

    protected function refJson(string $collection): string
    {
        return sprintf("json_object('@ref', json_object('collection', %s, 'id', CAST(id AS TEXT)))", $collection);
    }

    protected function recordEvent(string $history): string
    {
        return sprintf(
            'INSERT INTO %s (id, ts, action, data) VALUES (:id, :ts, :action, :data) ON CONFLICT (id, ts) DO UPDATE SET data = excluded.data,'
            . " action = CASE WHEN action = 'create' AND excluded.action = 'update' THEN 'create' ELSE excluded.action END",
            $history,
        );
    }

    protected function unroll(string $json, string $alias): array
    {
        $list = sprintf(<<<'SQL'
            (CASE WHEN json_type(%1$s) = 'array' THEN %1$s ELSE json_array(%1$s) END)
            SQL, $json);

        // json_each() gives SQL values: the JSON of an item is read from the list, at its path.
        return [sprintf('CROSS JOIN json_each(%s) AS %s', $list, $alias), sprintf('(%s -> %s.fullkey)', $list, $alias)];
    }

    protected function columnType(string $kind): string
    {
        return match ($kind) {
            'type' => 'INTEGER',
            'number' => 'NUMERIC',
            'term', 'string', 'json', 'text' => 'TEXT',
        };
    }

    protected function virtualColumn(string $kind, string $expression, bool $nullable): string
    {
        return sprintf('%s GENERATED ALWAYS AS (%s) VIRTUAL%s', $this->columnType($kind), $expression, $nullable ? '' : ' NOT NULL');
    }

    protected function jsonParameter(string $placeholder): string
    {
        // json() writes the value the way -> does.
        return sprintf('json(%s)', $placeholder);
    }

    protected function jsonEquals(string $a, string $b): ?string
    {
        return null;
    }

    protected function termKey(string $json): string
    {
        // Numbers are keyed by value, so that 36 and 36.0 match; anything else by its JSON text.
        return sprintf(<<<'SQL'
            CASE
                WHEN json_type(%1$s) = 'real' AND json_extract(%1$s, '$') = CAST(json_extract(%1$s, '$') AS INTEGER)
                    THEN 'n:' || CAST(json_extract(%1$s, '$') AS INTEGER)
                WHEN json_type(%1$s) IN ('integer', 'real') THEN 'n:' || json_extract(%1$s, '$')
                ELSE %1$s
            END
            SQL, $json);
    }

    protected function typeKey(string $json): string
    {
        return sprintf(<<<'SQL'
            CASE json_type(%1$s)
                WHEN 'text' THEN 1
                WHEN 'integer' THEN 2
                WHEN 'real' THEN 2
                WHEN 'true' THEN 3
                WHEN 'false' THEN 3
                WHEN 'array' THEN 4
                WHEN 'object' THEN 5
                ELSE 0
            END
            SQL, $json);
    }

    protected function numberKey(string $json): string
    {
        // SQLite compares integers and reals by value, so integers are kept exact.
        return sprintf(<<<'SQL'
            CASE json_type(%1$s)
                WHEN 'integer' THEN json_extract(%1$s, '$')
                WHEN 'real' THEN json_extract(%1$s, '$')
                WHEN 'true' THEN 1
                ELSE 0
            END
            SQL, $json);
    }

    protected function stringKey(string $json): string
    {
        return sprintf(<<<'SQL'
            CASE json_type(%1$s)
                WHEN 'text' THEN json_extract(%1$s, '$')
                WHEN 'array' THEN %1$s
                WHEN 'object' THEN %1$s
                ELSE ''
            END
            SQL, $json);
    }
}
