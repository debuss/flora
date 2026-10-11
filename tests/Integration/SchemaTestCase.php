<?php declare(strict_types=1);

namespace Flora\Tests\Integration;

use Flora\Driver\AbstractMysqlDriver;
use Flora\Exception\{Aborted, AlreadyExists, InvalidArgument, NotFound, NotUnique};
use Flora\Value\{CollectionRef, IndexRef};

use function Flora\{
    Abort,
    At,
    Collection,
    Create,
    CreateCollection,
    CreateIndex,
    Delete,
    Exists,
    Get,
    IfElse,
    Index,
    MatchIndex,
    NewId,
    Paginate,
    Ref,
    Size,
    Update,
};

abstract class SchemaTestCase extends IntegrationTestCase
{

    protected function setUp(): void
    {
        parent::setUp();

        $this->client->query(CreateCollection(['name' => 'users']));
        $this->client->query(CreateCollection(['name' => 'posts']));
        $this->client->query(CreateIndex([
            'name' => 'users_by_role',
            'source' => Collection('users'),
            'terms' => [['field' => ['data', 'role']]],
            'values' => [['field' => ['data', 'name']]],
        ]));
        $this->client->query(CreateIndex(['name' => 'users_by_tag', 'source' => Collection('users'), 'terms' => [['field' => ['data', 'tags'], 'unroll' => true]]]));
        $this->client->query(CreateIndex(['name' => 'users_by_email', 'source' => Collection('users'), 'terms' => [['field' => ['data', 'email']]]]));
        $this->client->query(CreateIndex(['name' => 'posts_by_title', 'source' => Collection('posts'), 'terms' => [['field' => ['data', 'title']]]]));
        $this->client->query([
            Create(Collection('users'), ['data' => ['name' => 'Ada', 'role' => 'admin', 'tags' => ['math'], 'email' => 'ada@example.com']]),
            Create(Collection('users'), ['data' => ['name' => 'Grace', 'role' => 'admin', 'tags' => ['navy', 'math'], 'email' => 'grace@example.com']]),
            Create(Collection('posts'), ['data' => ['title' => 'Notes']]),
        ]);
    }

    public function testDeletingAnIndex(): void
    {
        $created = $this->client->query(CreateIndex(['name' => 'users_by_name', 'source' => Collection('users'), 'terms' => [['field' => ['data', 'name']]]]));
        $deleted = $this->client->query(Delete(Index('users_by_name')));

        self::assertEquals(new IndexRef('users_by_name'), $deleted['ref']);
        self::assertEquals(new CollectionRef('users'), $deleted['source']);
        self::assertSame($created['ts'], $deleted['ts']);
        $this->assertNotFound(fn () => $this->client->query(Paginate(MatchIndex(Index('users_by_name'), 'Ada'))));

        // The documents stay, and the name can be given again: what the index was read from is gone.
        self::assertSame(2, $this->client->query(Size(MatchIndex(Index('users_by_role'), 'admin'))));
        $this->client->query(CreateIndex(['name' => 'users_by_name', 'source' => Collection('users'), 'terms' => [['field' => ['data', 'role']]]]));
        self::assertSame(2, $this->client->query(Size(MatchIndex(Index('users_by_name'), 'admin'))));
    }

    public function testDeletingAnIndexUnrollingFields(): void
    {
        // The writes after the deletion, in the same query, no longer keep its entries.
        $this->client->query([
            Delete(Index('users_by_tag')),
            Create(Collection('users'), ['data' => ['name' => 'Alan', 'tags' => ['crypto']]]),
        ]);

        $this->assertNotFound(fn () => $this->client->query(Paginate(MatchIndex(Index('users_by_tag'), 'math'))));
        $this->client->query(CreateIndex(['name' => 'users_by_tag', 'source' => Collection('users'), 'terms' => [['field' => ['data', 'tags'], 'unroll' => true]]]));
        self::assertSame([2, 1], $this->client->query([Size(MatchIndex(Index('users_by_tag'), 'math')), Size(MatchIndex(Index('users_by_tag'), 'crypto'))]));
    }

    public function testAQueryNoLongerReadsAnIndexItDeleted(): void
    {
        $this->assertNotFound(fn () => $this->client->query([
            Paginate(MatchIndex(Index('users_by_role'), 'admin')),
            Delete(Index('users_by_role')),
            Paginate(MatchIndex(Index('users_by_role'), 'admin')),
        ]));

        // The query was rolled back, but on databases committing before a schema change (MysqlSpecificsTestCase).
        if (!static::driver($this->pdo) instanceof AbstractMysqlDriver) {
            self::assertSame(2, $this->client->query(Size(MatchIndex(Index('users_by_role'), 'admin'))));
        }
    }

    public function testDeletingACollection(): void
    {
        $ada = $this->client->query(Get(MatchIndex(Index('users_by_role'), 'admin')));
        $collection = $this->client->query(Get(Collection('users')));

        self::assertEquals($collection, $this->client->query(Delete(Collection('users'))));
        $this->assertNotFound(fn () => $this->client->query(Get(Collection('users'))));
        $this->assertNotFound(fn () => $this->client->query(Get($ada['ref'])));
        $this->assertNotFound(fn () => $this->client->query(Paginate(MatchIndex(Index('users_by_role'), 'admin'))));
        $this->assertNotFound(fn () => $this->client->query(Paginate(MatchIndex(Index('users_by_tag'), 'math'))));

        // Other collections stay.
        self::assertSame(1, $this->client->query(Size(MatchIndex(Index('posts_by_title'), 'Notes'))));

        // The names can be given again, to an empty collection without history.
        $this->client->query(CreateCollection(['name' => 'users']));
        $this->client->query(CreateIndex(['name' => 'users_by_tag', 'source' => Collection('users'), 'terms' => [['field' => ['data', 'tags'], 'unroll' => true]]]));
        self::assertFalse($this->client->query(Exists($ada['ref'])));
        $this->assertNotFound(fn () => $this->client->query(Get($ada['ref'], $ada['ts'])));
        self::assertSame(0, $this->client->query(Size(MatchIndex(Index('users_by_tag'), 'math'))));
    }

    public function testDeletingWhatDoesNotExist(): void
    {
        $this->assertNotFound(fn () => $this->client->query(Delete(Index('nothing'))));
        $this->assertNotFound(fn () => $this->client->query(Delete(Collection('nothing'))));
    }

    public function testSchemaChangesAreWrites(): void
    {
        $this->expectException(InvalidArgument::class);

        $this->client->query(At(1, Delete(Index('users_by_role'))));
    }

    public function testRenamingAnIndex(): void
    {
        $renamed = $this->client->query(Update(Index('users_by_role'), ['name' => 'by_role']));

        self::assertEquals(new IndexRef('by_role'), $renamed['ref']);
        self::assertSame([['field' => ['data', 'role']]], $renamed['terms']);
        self::assertSame(['Ada', 'Grace'], $this->client->query(Paginate(MatchIndex(Index('by_role'), 'admin')))->data);
        $this->assertNotFound(fn () => $this->client->query(Paginate(MatchIndex(Index('users_by_role'), 'admin'))));

        $this->client->query(Update(Index('users_by_tag'), ['name' => 'by_tag']));
        self::assertSame(2, $this->client->query(Size(MatchIndex(Index('by_tag'), 'math'))));
    }

    public function testRenamingToATakenName(): void
    {
        try {
            $this->client->query(Update(Index('users_by_role'), ['name' => 'users_by_email']));
            self::fail('The name is taken.');
        } catch (AlreadyExists) {
        }

        self::assertSame(2, $this->client->query(Size(MatchIndex(Index('users_by_role'), 'admin'))));
    }

    public function testMakingAnIndexUnique(): void
    {
        $this->client->query(Update(Index('users_by_email'), ['unique' => true]));

        try {
            $this->client->query(Create(Collection('users'), ['data' => ['email' => 'ada@example.com']]));
            self::fail('The index is unique.');
        } catch (NotUnique) {
        }

        $this->client->query(Update(Index('users_by_email'), ['unique' => false]));
        $this->client->query(Create(Collection('users'), ['data' => ['email' => 'ada@example.com']]));
        self::assertSame(2, $this->client->query(Size(MatchIndex(Index('users_by_email'), 'ada@example.com'))));
    }

    public function testAnIndexDocumentsBreakIsNotMadeUnique(): void
    {
        $this->client->query(CreateIndex(['name' => 'roles', 'source' => Collection('users'), 'terms' => [['field' => ['data', 'role']]]]));

        try {
            $this->client->query(Update(Index('roles'), ['unique' => true]));
            self::fail('Two users are admins.');
        } catch (NotUnique) {
        }

        // The index is still there, even on databases which cannot roll back a schema change.
        self::assertSame(2, $this->client->query(Size(MatchIndex(Index('roles'), 'admin'))));
        $this->client->query(Create(Collection('users'), ['data' => ['name' => 'Alan', 'role' => 'admin']]));
        self::assertSame(3, $this->client->query(Size(MatchIndex(Index('roles'), 'admin'))));
    }

    public function testTermsAndValuesOfAnIndexStay(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('create another index instead');

        $this->client->query(Update(Index('users_by_role'), ['terms' => [['field' => ['data', 'name']]]]));
    }

    public function testAbort(): void
    {
        try {
            $this->client->query([
                Create(Collection('users'), ['data' => ['name' => 'Alan', 'role' => 'admin']]),
                IfElse(true, Abort('Too many admins.'), null),
            ]);
            self::fail('The query aborts.');
        } catch (Aborted $e) {
            self::assertSame('Too many admins.', $e->getMessage());
        }

        // Its writes were rolled back.
        self::assertSame(2, $this->client->query(Size(MatchIndex(Index('users_by_role'), 'admin'))));
        self::assertSame('kept', $this->client->query(IfElse(false, Abort('Not evaluated.'), 'kept')));
    }

    public function testNewId(): void
    {
        [$a, $b] = $this->client->query([NewId(), NewId()]);

        self::assertMatchesRegularExpression('/^[1-9]\d*$/', $a);
        self::assertNotSame($a, $b);

        $ref = Ref(Collection('users'), $a);
        $this->client->query(Create($ref, ['data' => ['name' => 'Alan']]));
        self::assertSame('Alan', $this->client->query(Get($ref))['data']['name']);
    }

    private function assertNotFound(callable $query): void
    {
        try {
            $query();
            self::fail('Expected NotFound.');
        } catch (NotFound) {
            $this->addToAssertionCount(1);
        }
    }
}
