<?php declare(strict_types=1);

namespace Flora\Tests\Integration;

use Flora\Exception\{InvalidArgument, NotFound};
use Flora\Expr;
use Flora\Value\Page;

use function Flora\{
    Add,
    Collection,
    Create,
    CreateCollection,
    CreateIndex,
    Delete,
    Documents,
    Each,
    Index,
    Lambda,
    MatchIndex,
    Paginate,
    Ref,
    Size,
    Update,
    Variable,
};

abstract class EachTestCase extends IntegrationTestCase
{

    protected function setUp(): void
    {
        parent::setUp();

        $this->client->query(CreateCollection(['name' => 'users']));
        $this->client->query(CreateIndex([
            'name' => 'ages_by_role',
            'source' => Collection('users'),
            'terms' => [['field' => ['data', 'role']]],
            'values' => [['field' => ['data', 'age']], ['field' => ['ref']]],
        ]));
    }

    public function testEachItemOfAList(): void
    {
        $names = ['Ada', 'Grace', 'Alan'];

        $result = $this->client->query(Each($names, Lambda('name', Create(Collection('users'), ['data' => ['name' => Variable('name')]]))));

        self::assertSame($names, $result);
        self::assertSame(3, $this->client->query(Size(Documents(Collection('users')))));
    }

    public function testEachItemOfAPage(): void
    {
        $this->createUsers(['admin', 36], ['admin', 85], ['editor', 41]);
        $admins = Paginate(MatchIndex(Index('ages_by_role'), 'admin'));

        // A year older each: the items of the page are its values, the age and the ref.
        $result = $this->client->query(Each($admins, Lambda(['age', 'ref'], Update(Variable('ref'), ['data' => ['age' => Add(Variable('age'), 1)]]))));

        self::assertInstanceOf(Page::class, $result);
        self::assertSame([37, 86], array_column($this->client->query($admins)->data, 0));
    }

    public function testDeletingAPage(): void
    {
        $this->createUsers(['guest', 20], ['guest', 30], ['admin', 40]);

        $this->client->query(Each(Paginate(MatchIndex(Index('ages_by_role'), 'guest')), Lambda(['_', 'ref'], Delete(Variable('ref')))));

        self::assertSame(1, $this->client->query(Size(Documents(Collection('users')))));
    }

    public function testAFailingItemWritesNothing(): void
    {
        $this->createUsers(['guest', 20], ['guest', 30]);
        $refs = $this->client->query(Paginate(Documents(Collection('users'))))->data;

        try {
            // The first two deletes succeed, the third refers to a document that does not exist.
            $this->client->query(Each([...$refs, Ref(Collection('users'), '1')], Lambda('ref', Delete(Variable('ref')))));
            self::fail('The query should have failed.');
        } catch (NotFound) {
        }

        self::assertSame(2, $this->client->query(Size(Documents(Collection('users')))));
    }

    public function testEachTakesAListOrAPage(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('paginate the set');

        $this->client->query(Each(Documents(Collection('users')), Lambda('ref', Delete(Variable('ref')))));
    }

    /**
     * @param array{string, int} ...$users the role and the age of each user
     */
    private function createUsers(array ...$users): void
    {
        $this->client->query(array_map(
            static fn (array $user): Expr\Create => Create(Collection('users'), ['data' => ['role' => $user[0], 'age' => $user[1]]]),
            array_values($users),
        ));
    }
}
