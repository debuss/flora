<?php declare(strict_types=1);

namespace Flora\Tests\Integration\Postgres;

use PDO;
use Flora\Driver\Driver;
use Flora\Tests\Integration\Database;

/**
 * Works in a schema of its own, in the database given by the FLORA_PG_* environment variables.
 */
trait UsesPostgres
{

    protected static function connect(): PDO
    {
        return Database::Postgres->connect();
    }

    protected static function reset(PDO $pdo): void
    {
        Database::Postgres->reset($pdo);
    }

    protected static function driver(PDO $pdo): Driver
    {
        return Database::Postgres->driver($pdo);
    }
}
