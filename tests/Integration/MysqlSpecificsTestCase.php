<?php declare(strict_types=1);

namespace Flora\Tests\Integration;

use Flora\Driver\{MariadbDriver, MysqlDriver};
use Flora\Exception\{InvalidArgument, NotFound};

use function Flora\{Collection, Create, CreateCollection, CreateIndex, Delete, Exists, Get, Index, MatchIndex, Paginate, Ref, Size};

/**
 * What is specific to the MySQL and MariaDB drivers.
 */
abstract class MysqlSpecificsTestCase extends IntegrationTestCase
{

    /**
     * Both databases commit the current transaction before a schema change: what a query wrote before it stays, what
     * it writes after it is still rolled back on failure.
     */
    public function testASchemaChangeCommitsTheWritesBeforeIt(): void
    {
        $this->client->query(CreateCollection(['name' => 'users']));

        try {
            $this->client->query([
                Create(Ref(Collection('users'), '1')),
                CreateCollection(['name' => 'posts']),
                Create(Ref(Collection('users'), '2')),
                Get(Ref(Collection('users'), '3')),
            ]);
            self::fail('The query should have failed.');
        } catch (NotFound) {
        }

        self::assertTrue($this->client->query(Exists(Ref(Collection('users'), '1'))));
        self::assertFalse($this->client->query(Exists(Ref(Collection('users'), '2'))));

        // The collection was created: reading from it does not throw NotFound.
        self::assertFalse($this->client->query(Exists(Ref(Collection('posts'), '1'))));
    }

    /**
     * Deleting an index commits its deletion with the schema change: a query failing after it does not bring back an
     * index whose columns are gone.
     */
    public function testADeletedIndexStaysDeleted(): void
    {
        $this->client->query(CreateCollection(['name' => 'users']));
        $this->client->query(CreateIndex(['name' => 'users_by_role', 'source' => Collection('users'), 'terms' => [['field' => ['data', 'role']]]]));

        try {
            $this->client->query([Delete(Index('users_by_role')), Get(Ref(Collection('users'), '1'))]);
            self::fail('The query should have failed.');
        } catch (NotFound) {
        }

        try {
            $this->client->query(Paginate(MatchIndex(Index('users_by_role'), 'admin')));
            self::fail('The index should be deleted.');
        } catch (NotFound) {
        }

        // Its columns are gone too: the name can be given again.
        $this->client->query(CreateIndex(['name' => 'users_by_role', 'source' => Collection('users'), 'terms' => [['field' => ['data', 'role']]]]));
        self::assertSame(0, $this->client->query(Size(MatchIndex(Index('users_by_role'), 'admin'))));
    }

    /**
     * MySQL stores JSON nested 100 levels deep, MariaDB 31: deeper documents are refused with an error of Flora.
     */
    public function testDocumentsNestedTooDeep(): void
    {
        $this->client->query(CreateCollection(['name' => 'users']));
        $deep = json_decode(str_repeat('[', 120) . str_repeat(']', 120), true, 200);

        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('deeper than the database accepts');

        $this->client->query(Create(Collection('users'), ['data' => ['x' => $deep]]));
    }

    public function testRefusesTheOtherServer(): void
    {
        $other = static::driver($this->pdo) instanceof MysqlDriver ? new MariadbDriver($this->pdo) : new MysqlDriver($this->pdo);

        $this->expectException(InvalidArgument::class);
        $other->install();
    }
}
