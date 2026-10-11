<?php declare(strict_types=1);

namespace Flora\Driver;

use Flora\Exception\InvalidArgument;

/**
 * MariaDB driver (10.11 and later). See AbstractMysqlDriver and AbstractVirtualColumnsDriver for how collections
 * and indexes are stored.
 *
 * MariaDB's JSON type is text checked by JSON_VALID(), so JSON values are compared and keyed through JSON_NORMALIZE(),
 * which sorts object keys and writes every number as a double: 36 and 36.0 have the same key. JSON_EQUALS() makes
 * term matches exact.
 */
final readonly class MariadbDriver extends AbstractMysqlDriver
{

    protected function checkServer(): void
    {
        $version = $this->serverVersion();
        if (!str_contains($version, 'MariaDB')) {
            throw new InvalidArgument(sprintf('The server is MySQL %s: use MysqlDriver.', $version));
        }
    }

    protected function recordEvent(string $history): string
    {
        return sprintf(
            'INSERT INTO %s (id, ts, action, data) VALUES (:id, :ts, :action, :data) ON DUPLICATE KEY UPDATE data = VALUES(data),'
            . " action = IF(action = 'create' AND VALUES(action) = 'update', 'create', VALUES(action))",
            $history,
        );
    }

    protected function virtualColumn(string $kind, string $expression, bool $nullable): string
    {
        // MariaDB does not accept NOT NULL on virtual columns. The expressions of non-nullable keys are never NULL.
        return sprintf('%s AS (%s) VIRTUAL', $this->columnType($kind), $expression);
    }

    protected function jsonParameter(string $placeholder): string
    {
        return $placeholder;
    }

    protected function jsonEquals(string $a, string $b): string
    {
        return sprintf('JSON_EQUALS(%s, %s)', $a, $b);
    }

    protected function termKey(string $json): string
    {
        return sprintf(<<<'SQL'
            CONVERT(LEFT(JSON_NORMALIZE(%1$s), %2$d) USING utf8mb4) COLLATE utf8mb4_bin
            SQL, $json, self::KEY_LENGTH);
    }

    protected function numberKey(string $json): string
    {
        return sprintf(<<<'SQL'
            CASE
                WHEN JSON_TYPE(%1$s) IN (%2$s) THEN CAST(%1$s AS DOUBLE)
                WHEN JSON_TYPE(%1$s) = 'BOOLEAN' THEN %1$s = 'true'
                ELSE 0
            END
            SQL, $json, self::NUMBER_TYPES);
    }

    protected function stringKey(string $json): string
    {
        return sprintf(<<<'SQL'
            CONVERT(LEFT(
                CASE
                    WHEN JSON_TYPE(%1$s) = 'STRING' THEN JSON_UNQUOTE(%1$s)
                    WHEN JSON_TYPE(%1$s) IN ('ARRAY', 'OBJECT') THEN JSON_NORMALIZE(%1$s)
                    ELSE ''
                END,
                %2$d
            ) USING utf8mb4) COLLATE utf8mb4_bin
            SQL, $json, self::KEY_LENGTH);
    }
}
