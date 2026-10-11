<?php declare(strict_types=1);

namespace Flora\Driver;

use Flora\Exception\InvalidArgument;

/**
 * MySQL driver (8.0.17 and later). See AbstractMysqlDriver and AbstractVirtualColumnsDriver for how collections
 * and indexes are stored.
 *
 * Term keys are the canonical JSON text MySQL produces ("x" for a string), except for numbers, which are keyed by value
 * (n:36), so that 36 and 36.0 match like they do in JSON comparisons.
 */
final readonly class MysqlDriver extends AbstractMysqlDriver
{

    protected function checkServer(): void
    {
        $version = $this->serverVersion();
        if (str_contains($version, 'MariaDB')) {
            throw new InvalidArgument(sprintf('The server is MariaDB %s: use MariadbDriver.', $version));
        }
    }

    protected function recordEvent(string $history): string
    {
        return sprintf(
            'INSERT INTO %1$s (id, ts, action, data) VALUES (:id, :ts, :action, :data) AS new ON DUPLICATE KEY UPDATE data = new.data,'
            . " action = IF(%1\$s.action = 'create' AND new.action = 'update', 'create', new.action)",
            $history,
        );
    }

    protected function virtualColumn(string $kind, string $expression, bool $nullable): string
    {
        return sprintf('%s AS (%s) VIRTUAL%s', $this->columnType($kind), $expression, $nullable ? '' : ' NOT NULL');
    }

    protected function jsonParameter(string $placeholder): string
    {
        return sprintf('CAST(%s AS JSON)', $placeholder);
    }

    protected function jsonEquals(string $a, string $b): string
    {
        return sprintf('%s = %s', $a, $b);
    }

    protected function termKey(string $json): string
    {
        return sprintf(<<<'SQL'
            CONVERT(LEFT(
                CASE WHEN JSON_TYPE(%1$s) IN (%2$s) THEN CONCAT('n:', CAST(%1$s AS DOUBLE)) ELSE CAST(%1$s AS CHAR) END,
                %3$d
            ) USING utf8mb4) COLLATE utf8mb4_bin
            SQL, $json, self::NUMBER_TYPES, self::KEY_LENGTH);
    }

    protected function numberKey(string $json): string
    {
        return sprintf(<<<'SQL'
            CASE WHEN JSON_TYPE(%1$s) IN (%2$s, 'BOOLEAN') THEN CAST(%1$s AS DOUBLE) ELSE 0 END
            SQL, $json, self::NUMBER_TYPES);
    }

    protected function stringKey(string $json): string
    {
        return sprintf(<<<'SQL'
            CONVERT(LEFT(
                CASE
                    WHEN JSON_TYPE(%1$s) = 'STRING' THEN JSON_UNQUOTE(%1$s)
                    WHEN JSON_TYPE(%1$s) IN ('ARRAY', 'OBJECT') THEN CAST(%1$s AS CHAR)
                    ELSE ''
                END,
                %2$d
            ) USING utf8mb4) COLLATE utf8mb4_bin
            SQL, $json, self::KEY_LENGTH);
    }
}
