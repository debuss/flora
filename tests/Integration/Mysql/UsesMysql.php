<?php declare(strict_types=1);

namespace Flora\Tests\Integration\Mysql;

use PDO;
use Flora\Driver\Driver;
use Flora\Tests\Integration\Database;

/**
 * Works in a database of its own, on the server given by the FLORA_MYSQL_* environment variables.
 */
trait UsesMysql
{

    protected static function connect(): PDO
    {
        return Database::Mysql->connect();
    }

    protected static function reset(PDO $pdo): void
    {
        Database::Mysql->reset($pdo);
    }

    protected static function driver(PDO $pdo): Driver
    {
        return Database::Mysql->driver($pdo);
    }
}
