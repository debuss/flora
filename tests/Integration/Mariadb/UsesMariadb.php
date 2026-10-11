<?php declare(strict_types=1);

namespace Flora\Tests\Integration\Mariadb;

use PDO;
use Flora\Driver\Driver;
use Flora\Tests\Integration\Database;

/**
 * Works in a database of its own, on the server given by the FLORA_MARIADB_* environment variables.
 */
trait UsesMariadb
{

    protected static function connect(): PDO
    {
        return Database::Mariadb->connect();
    }

    protected static function reset(PDO $pdo): void
    {
        Database::Mariadb->reset($pdo);
    }

    protected static function driver(PDO $pdo): Driver
    {
        return Database::Mariadb->driver($pdo);
    }
}
