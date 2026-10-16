<?php declare(strict_types=1);

namespace Flora\Tests\Integration\Postgres;

use PDO;
use Flora\Tests\Integration\{Database, LockWaitsTestCase};

final class LockWaitsTest extends LockWaitsTestCase
{

    use UsesPostgres;

    protected static function waitBriefly(PDO $pdo): void
    {
        $pdo->exec("SET lock_timeout = '500ms'");
    }

    protected static function database(): Database
    {
        return Database::Postgres;
    }
}
