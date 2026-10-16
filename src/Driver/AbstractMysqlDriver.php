<?php declare(strict_types=1);

namespace Flora\Driver;

use PDOException;
use Throwable;
use Flora\Exception\{AlreadyExists, Contended, InvalidArgument, NotFound, NotUnique};

/**
 * What the MySQL and MariaDB drivers share. See AbstractVirtualColumnsDriver for how collections and indexes are stored.
 *
 * Index keys are limited in size, so string keys are cut at KEY_LENGTH characters: the JSON values are compared as
 * well, so that terms match exactly, but entries whose strings only differ after KEY_LENGTH characters are ordered by
 * ref, and collide in unique indexes. Strings sort by code point.
 *
 * Both databases commit the current transaction before any schema change, so writes made earlier in a query that
 * creates a collection or an index are committed, even if the query fails afterwards. Writes made after it are still
 * atomic.
 */
abstract readonly class AbstractMysqlDriver extends AbstractVirtualColumnsDriver
{

    public const int KEY_LENGTH = 128;

    /** The JSON_TYPE() of numbers. */
    protected const string NUMBER_TYPES = "'INTEGER', 'UNSIGNED INTEGER', 'DOUBLE', 'DECIMAL'";

    private const int TABLE_EXISTS = 1050;

    private const int DUPLICATE_ENTRY = 1062;

    private const int NO_SUCH_TABLE = 1146;

    private const int LOCK_WAIT_TIMEOUT = 1205;

    private const int DEADLOCK = 1213;

    /** MySQL: a JSON document nested deeper than 100 levels. */
    private const int JSON_TOO_DEEP = 3157;

    /** MariaDB: a JSON document nested 32 levels deep or more, which its JSON functions reject. */
    private const int JSON_DEPTH = 4040;

    /**
     * MariaDB: the check of a column failed. Flora writes valid JSON, so the data column fails JSON_VALID() only for a
     * document nested too deep.
     */
    private const int CONSTRAINT_FAILED = 4025;

    /**
     * What SELECT VERSION() returns.
     */
    protected function serverVersion(): string
    {
        return Row::toString(($this->pdo->query('SELECT VERSION()') ?: null)?->fetchColumn(), 'version');
    }

    protected function pdoDriver(): string
    {
        return 'mysql';
    }

    protected function createTable(string $table): string
    {
        return sprintf('CREATE TABLE %s (id BIGINT PRIMARY KEY, ts BIGINT NOT NULL, data JSON NOT NULL)', $table);
    }

    protected function indexStatements(string $table, array $columns, string $index, array $keys, bool $unique): array
    {
        $changes = array_map(static fn (array $column): string => sprintf('ADD COLUMN %s %s', $column['name'], $column['definition']), $columns);
        $changes[] = sprintf('ADD %sINDEX %s (%s)', $unique ? 'UNIQUE ' : '', $index, implode(', ', $keys));

        return [sprintf('ALTER TABLE %s %s', $table, implode(', ', $changes))];
    }

    protected function dropIndexStatements(string $table, string $index, array $columns): array
    {
        $changes = [sprintf('DROP INDEX %s', $index), ...array_map(static fn (string $column): string => 'DROP COLUMN ' . $column, $columns)];

        return [sprintf('ALTER TABLE %s %s', $table, implode(', ', $changes))];
    }

    protected function lockForUpdate(): string
    {
        return 'FOR UPDATE';
    }

    protected function translate(PDOException $e, ?string $collection): ?Throwable
    {
        return match ($e->errorInfo[1] ?? null) {
            self::NO_SUCH_TABLE => new NotFound(sprintf('Collection "%s" does not exist.', $collection ?? '?'), 0, $e),
            self::TABLE_EXISTS => new AlreadyExists('A table with this name already exists in the database.', 0, $e),
            self::LOCK_WAIT_TIMEOUT, self::DEADLOCK => new Contended('The query ran into other transactions (a lock wait timeout or a deadlock): it wrote nothing, and can be run again.', 0, $e),
            self::DUPLICATE_ENTRY => new NotUnique('The document breaks the unique constraint of an index.', 0, $e),
            self::JSON_TOO_DEEP, self::JSON_DEPTH, self::CONSTRAINT_FAILED => new InvalidArgument('The document nests lists and objects deeper than the database accepts (MySQL: 100 levels, MariaDB: 31).', 0, $e),
            default => null,
        };
    }

    protected function isPrimaryKeyConflict(PDOException $e): bool
    {
        return str_contains($e->getMessage(), "PRIMARY'");
    }

    protected function extract(string $path): string
    {
        return sprintf('JSON_EXTRACT(data, %s)', $path);
    }

    protected function refJson(string $collection): string
    {
        return sprintf("JSON_OBJECT('@ref', JSON_OBJECT('collection', %s, 'id', CAST(id AS CHAR)))", $collection);
    }

    protected function unroll(string $json, string $alias): array
    {
        return [
            sprintf(<<<'SQL'
                CROSS JOIN JSON_TABLE(
                    CASE WHEN JSON_TYPE(%1$s) = 'ARRAY' THEN %1$s ELSE JSON_ARRAY(%1$s) END,
                    '$[*]' COLUMNS (value JSON PATH '$')
                ) AS %2$s
                SQL, $json, $alias),
            // MySQL gives SQL NULL for a JSON null item.
            sprintf("COALESCE(%s.value, JSON_EXTRACT('null', '$'))", $alias),
        ];
    }

    protected function typeKey(string $json): string
    {
        return sprintf(<<<'SQL'
            CASE
                WHEN JSON_TYPE(%1$s) = 'STRING' THEN 1
                WHEN JSON_TYPE(%1$s) IN (%2$s) THEN 2
                WHEN JSON_TYPE(%1$s) = 'BOOLEAN' THEN 3
                WHEN JSON_TYPE(%1$s) = 'ARRAY' THEN 4
                WHEN JSON_TYPE(%1$s) = 'OBJECT' THEN 5
                ELSE 0
            END
            SQL, $json, self::NUMBER_TYPES);
    }

    protected function columnType(string $kind): string
    {
        return match ($kind) {
            'type' => 'TINYINT',
            'number' => 'DOUBLE',
            'term', 'string' => 'VARCHAR(' . self::KEY_LENGTH . ') CHARACTER SET utf8mb4 COLLATE utf8mb4_bin',
            'json' => 'JSON',
            'text' => 'LONGTEXT',
        };
    }
}
