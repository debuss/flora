<?php declare(strict_types=1);

namespace Flora\Driver;

use PDO;
use PDOStatement;
use UnexpectedValueException;
use Flora\Support\{Json, Values};

/**
 * The columns of the rows a database returns, read with their type: drivers return numbers as integers or strings.
 *
 * @internal
 */
final class Row
{

    /**
     * @return array<string, mixed>
     */
    public static function of(mixed $row): array
    {
        if (!is_array($row)) {
            throw new UnexpectedValueException(sprintf('The database returned %s for a row.', get_debug_type($row)));
        }

        /** @var array<string, mixed> $row PDO::FETCH_ASSOC rows are keyed by column */
        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function all(PDOStatement $statement): array
    {
        return array_values(array_map(self::of(...), $statement->fetchAll(PDO::FETCH_ASSOC)));
    }

    /**
     * The values of the first column of the rows.
     *
     * @return list<mixed>
     */
    public static function column(PDOStatement $statement): array
    {
        return array_values($statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function int(array $row, string $column): int
    {
        return self::toInt($row[$column] ?? null, $column);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function nullableInt(array $row, string $column): ?int
    {
        return ($row[$column] ?? null) === null ? null : self::int($row, $column);
    }

    /**
     * A text column, or an integer one read as text, as the ids of refs are.
     *
     * @param array<string, mixed> $row
     */
    public static function string(array $row, string $column): string
    {
        return self::toString($row[$column] ?? null, $column);
    }

    /**
     * The value of a JSON column.
     *
     * @param array<string, mixed> $row
     */
    public static function json(array $row, string $column): mixed
    {
        return Json::decode(self::string($row, $column));
    }

    /**
     * The value of a JSON column holding an object: the data of a document.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function object(array $row, string $column): array
    {
        return Values::object(self::json($row, $column), sprintf('Column "%s"', $column));
    }

    public static function toInt(mixed $value, string $what = 'value'): int
    {
        return match (true) {
            is_int($value) => $value,
            is_string($value) && preg_match('/^-?\d+$/', $value) === 1 => (int) $value,
            default => throw new UnexpectedValueException(sprintf('The database returned %s for the integer %s.', get_debug_type($value), $what)),
        };
    }

    public static function toString(mixed $value, string $what = 'value'): string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value) => (string) $value,
            default => throw new UnexpectedValueException(sprintf('The database returned %s for the text %s.', get_debug_type($value), $what)),
        };
    }
}
