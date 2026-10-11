<?php declare(strict_types=1);

namespace Flora\Tests\Integration\Sqlite;

use PDO;
use Flora\Driver\Driver;
use Flora\Tests\Integration\Database;

/**
 * Works in the database given by FLORA_SQLITE_DSN, in memory by default.
 */
trait UsesSqlite
{

    protected static function connect(): PDO
    {
        return Database::Sqlite->connect();
    }

    protected static function reset(PDO $pdo): void
    {
        Database::Sqlite->reset($pdo);
    }

    protected static function driver(PDO $pdo): Driver
    {
        return Database::Sqlite->driver($pdo);
    }
}
