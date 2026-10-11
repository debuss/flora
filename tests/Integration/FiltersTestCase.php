<?php declare(strict_types=1);

namespace Flora\Tests\Integration;

use Flora\Exception\InvalidArgument;
use Flora\Value\{Page, Ref as RefValue};

use function Flora\{
    All,
    Any,
    Collection,
    Create,
    CreateCollection,
    CreateIndex,
    Equals,
    Exists,
    Filter,
    Get,
    GT,
    GTE,
    Index,
    Intersection,
    Join,
    Lambda,
    LT,
    LTE,
    MatchIndex,
    Not,
    Paginate,
    Select,
    Union,
    Variable,
};

abstract class FiltersTestCase extends IntegrationTestCase
{

    /** @var array<string, RefValue> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->client->query(CreateCollection(['name' => 'users']));
        $this->client->query(CreateIndex(['name' => 'users_by_role', 'source' => Collection('users'), 'terms' => [['field' => ['data', 'role']]]]));
        $this->client->query(CreateIndex([
            'name' => 'ages_by_role',
            'source' => Collection('users'),
            'terms' => [['field' => ['data', 'role']]],
            'values' => [['field' => ['data', 'age']], ['field' => ['ref']]],
        ]));

        // Created in one query, so their refs sort in this order.
        $users = $this->client->query(array_map(
            static fn (array $user): mixed => Create(Collection('users'), ['data' => ['name' => $user[0], 'role' => $user[1], 'city' => $user[2], 'age' => $user[3]]]),
            [
                ['Ada', 'admin', 'Paris', 36],
                ['Grace', 'admin', 'London', 85],
                ['Alan', 'editor', 'Paris', 41],
                ['Linus', 'admin', 'Helsinki', 54],
                ['Margaret', 'admin', 'Paris', 33],
            ],
        ));
        foreach ($users as $user) {
            $this->users[$user['data']['name']] = $user['ref'];
        }
    }

    public function testFilteringAList(): void
    {
        self::assertSame([3, 4], $this->client->query(Filter([1, 2, 3, 4], Lambda('n', GT(Variable('n'), 2)))));
    }

    public function testFilteringAPageKeepsItsCursors(): void
    {
        $page = $this->client->query(Paginate(MatchIndex(Index('users_by_role'), 'admin'), ['size' => 2]));
        $filtered = $this->client->query(Filter($page, Lambda('ref', Equals(Select(['data', 'city'], Get(Variable('ref'))), 'Paris'))));

        self::assertInstanceOf(Page::class, $filtered);
        self::assertEquals([$this->users['Ada']], $filtered->data);
        self::assertEquals($page->after, $filtered->after);
    }

    public function testFilteringASet(): void
    {
        $parisAdmins = Filter(
            MatchIndex(Index('users_by_role'), 'admin'),
            Lambda('ref', Equals(Select(['data', 'city'], Get(Variable('ref'))), 'Paris')),
        );

        self::assertEquals($this->refs('Ada', 'Margaret'), $this->collectAll($parisAdmins, 1));
    }

    public function testFilteringTheValuesOfASet(): void
    {
        $set = Filter(MatchIndex(Index('ages_by_role'), 'admin'), Lambda(['age', '_'], GTE(Variable('age'), 40)));

        self::assertEquals([[54, $this->users['Linus']], [85, $this->users['Grace']]], $this->collectAll($set, 1));
    }

    public function testASelectiveFilterReadsTheSetInChunks(): void
    {
        // 300 users, of which those aged 7 pass: they are spread across the set.
        $this->client->query(array_map(
            static fn (int $i): mixed => Create(Collection('users'), ['data' => ['role' => 'user', 'age' => $i % 50]]),
            range(1, 300),
        ));
        $set = Filter(MatchIndex(Index('ages_by_role'), 'user'), Lambda(['age', '_'], Equals(Variable('age'), 7)));

        self::assertCount(6, $this->collectAll($set, 4));
        self::assertCount(6, $this->collectAll($set, 1));
    }

    public function testFiltersNest(): void
    {
        $set = Filter(
            Filter(MatchIndex(Index('ages_by_role'), 'admin'), Lambda(['age', '_'], LT(Variable('age'), 60))),
            Lambda(['_', 'ref'], Not(Equals(Select(['data', 'city'], Get(Variable('ref'))), 'Paris'))),
        );

        self::assertEquals([[54, $this->users['Linus']]], $this->collectAll($set, 1));
    }

    public function testFilteringCombinedSets(): void
    {
        $everyone = Union(MatchIndex(Index('users_by_role'), 'admin'), MatchIndex(Index('users_by_role'), 'editor'));
        $inParis = Lambda('ref', Equals(Select(['data', 'city'], Get(Variable('ref'))), 'Paris'));

        self::assertEquals($this->refs('Ada', 'Alan', 'Margaret'), $this->collectAll(Filter($everyone, $inParis), 2));
        self::assertEquals(
            $this->refs('Ada', 'Margaret'),
            $this->collectAll(Filter(Join(MatchIndex(Index('users_by_role'), 'admin'), Lambda('ref', MatchIndex(Index('users_by_role'), 'admin'))), $inParis), 1),
        );
    }

    public function testGetAndExists(): void
    {
        $admins = MatchIndex(Index('users_by_role'), 'admin');

        self::assertSame('Linus', $this->client->query(Get(Filter($admins, Lambda('ref', Equals(Select(['data', 'city'], Get(Variable('ref'))), 'Helsinki')))))['data']['name']);
        self::assertFalse($this->client->query(Exists(Filter($admins, Lambda('ref', Equals(Select(['data', 'city'], Get(Variable('ref'))), 'Rome'))))));
    }

    public function testFilteredSetsCannotBeCombined(): void
    {
        $filtered = Filter(MatchIndex(Index('users_by_role'), 'admin'), Lambda('ref', true));

        try {
            $this->client->query(Intersection($filtered, MatchIndex(Index('users_by_role'), 'admin')));
            self::fail('Intersection() should refuse a Filter().');
        } catch (InvalidArgument $e) {
            self::assertStringContainsString('apply it to the result instead', $e->getMessage());
        }

        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('apply it to the result instead');
        $this->client->query(Join($filtered, Lambda('ref', MatchIndex(Index('users_by_role'), 'admin'))));
    }

    public function testTheLambdaReturnsABoolean(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('The result of a Filter() lambda must be a boolean');

        $this->client->query(Paginate(Filter(MatchIndex(Index('users_by_role'), 'admin'), Lambda('ref', 1))));
    }

    public function testComparisons(): void
    {
        self::assertTrue($this->client->query(LT(1, 2, 3.5)));
        self::assertFalse($this->client->query(LT(1, 1)));
        self::assertTrue($this->client->query(LTE(1, 1, 2)));
        self::assertTrue($this->client->query(GT('b', 'a')));
        self::assertTrue($this->client->query(GTE(3, 3.0)));
        self::assertTrue($this->client->query(All(true, Not(false))));
        self::assertFalse($this->client->query(Any(false, false)));

        // Conditions are evaluated until the result is known: the missing value is never read.
        self::assertTrue($this->client->query(Any(true, Select('missing', []))));
        self::assertFalse($this->client->query(All(false, Select('missing', []))));

        $this->expectException(InvalidArgument::class);
        $this->client->query(LT(1, 'a'));
    }

    /**
     * @return list<RefValue>
     */
    private function refs(string ...$names): array
    {
        return array_map(fn (string $name): RefValue => $this->users[$name], array_values($names));
    }
}
