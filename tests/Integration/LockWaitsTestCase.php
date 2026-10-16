<?php declare(strict_types=1);

namespace Flora\Tests\Integration;

use PDO;
use Flora\Exception\Contended;

use function Flora\{Collection, Create, CreateCollection, Update};

/**
 * A query waiting for a lock held by another connection: SQLite, whose test databases are in memory, has its own
 * (Sqlite\ConcurrencyTest).
 */
abstract class LockWaitsTestCase extends IntegrationTestCase
{

    /**
     * Has the connection wait about a second at most for a lock.
     */
    abstract protected static function waitBriefly(PDO $pdo): void;

    abstract protected static function database(): Database;

    public function testAQueryWaitingTooLongForALockIsContended(): void
    {
        $this->client->query(CreateCollection(['name' => 'users']));
        $ref = $this->client->query(Create(Collection('users'), ['data' => ['name' => 'Ada']]))['ref'];

        $other = static::connect();
        static::database()->enter($other);
        $other->beginTransaction();
        $other->exec(sprintf('UPDATE users SET ts = ts WHERE id = %d', $ref->id));
        static::waitBriefly($this->pdo);

        try {
            $this->client->query(Update($ref, ['data' => ['name' => 'Grace']]));
            self::fail('The query should have waited too long.');
        } catch (Contended) {
            self::assertFalse($this->pdo->inTransaction());
        } finally {
            $other->rollBack();
        }

        self::assertSame('Grace', $this->client->query(Update($ref, ['data' => ['name' => 'Grace']]))['data']['name']);
    }
}
