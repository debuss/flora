<?php declare(strict_types=1);

namespace Flora\Tests\Integration\Mysql;

use PDO;
use Flora\Tests\Integration\{Database, LockWaitsTestCase};

final class LockWaitsTest extends LockWaitsTestCase
{

    use UsesMysql;

    protected static function waitBriefly(PDO $pdo): void
    {
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');
    }

    protected static function database(): Database
    {
        return Database::Mysql;
    }
}
