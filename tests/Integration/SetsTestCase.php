<?php declare(strict_types=1);

namespace Flora\Tests\Integration;

use Flora\Exception\{InvalidArgument, NotFound};
use Flora\Value\{Page, Ref as RefValue};

use function Flora\{
    Collection,
    Create,
    CreateCollection,
    CreateIndex,
    Delete,
    Difference,
    Documents,
    Events,
    Exists,
    Get,
    Index,
    Intersection,
    Lambda,
    Map,
    MatchIndex,
    Paginate,
    Select,
    Singleton,
    Size,
    Union,
    Update,
    Variable,
};

abstract class SetsTestCase extends IntegrationTestCase
{

    /** @var array<string, RefValue> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->client->query(CreateCollection(['name' => 'users']));
        foreach ([
            'users_by_role' => [['field' => ['data', 'role']]],
            'users_by_city' => [['field' => ['data', 'city']]],
        ] as $name => $terms) {
            $this->client->query(CreateIndex(['name' => $name, 'source' => Collection('users'), 'terms' => $terms]));
        }
        foreach ([
            'ages_by_role' => [['data', 'role'], ['data', 'age']],
            'ages_by_city' => [['data', 'city'], ['data', 'age']],
            'names_by_role' => [['data', 'role'], ['data', 'name']],
            'names_by_city' => [['data', 'city'], ['data', 'name']],
        ] as $name => [$term, $value]) {
            $this->client->query(CreateIndex([
                'name' => $name,
                'source' => Collection('users'),
                'terms' => [['field' => $term]],
                'values' => [['field' => $value]],
            ]));
        }

        // Created in one query, so their refs sort in this order.
        $users = $this->client->query(array_map(
            static fn (array $user): mixed => Create(Collection('users'), ['data' => ['name' => $user[0], 'role' => $user[1], 'city' => $user[2], 'age' => $user[3]]]),
            [
                ['Ada', 'admin', 'Paris', 36],
                ['Grace', 'admin', 'London', 85],
                ['Alan', 'editor', 'Paris', 41],
                ['Linus', 'editor', 'Helsinki', 54],
                ['Margaret', 'viewer', 'Paris', 33],
                ['Edsger', 'admin', 'Paris', 72],
            ],
        ));
        foreach ($users as $user) {
            $this->users[$user['data']['name']] = $user['ref'];
        }
    }

    public function testUnion(): void
    {
        $set = Union(MatchIndex(Index('users_by_role'), 'admin'), MatchIndex(Index('users_by_city'), 'Paris'));

        // Ada and Edsger are in both sets, and come once.
        self::assertEquals($this->refs('Ada', 'Grace', 'Alan', 'Margaret', 'Edsger'), $this->collectAll($set, 2));
    }

    public function testUnionSortsByValues(): void
    {
        $set = Union(MatchIndex(Index('names_by_role'), 'admin'), MatchIndex(Index('names_by_role'), 'editor'));

        self::assertSame(['Ada', 'Alan', 'Edsger', 'Grace', 'Linus'], $this->collectAll($set, 2));
    }

    public function testUnionKeepsEqualEntriesOnce(): void
    {
        $set = Union(MatchIndex(Index('ages_by_role'), 'admin'), MatchIndex(Index('ages_by_city'), 'Paris'));

        self::assertSame([33, 36, 41, 72, 85], $this->collectAll($set, 2));
    }

    public function testIntersection(): void
    {
        $set = Intersection(MatchIndex(Index('users_by_role'), 'admin'), MatchIndex(Index('users_by_city'), 'Paris'));

        self::assertEquals($this->refs('Ada', 'Edsger'), $this->collectAll($set, 1));
    }

    public function testIntersectionComparesValues(): void
    {
        // Same values (the age): the entries of the documents in both sets.
        $ages = Intersection(MatchIndex(Index('ages_by_role'), 'admin'), MatchIndex(Index('ages_by_city'), 'Paris'));
        self::assertSame([36, 72], $this->collectAll($ages, 1));

        // Different values (an age, a name): no entry is in both sets.
        $mixed = Intersection(MatchIndex(Index('ages_by_role'), 'admin'), MatchIndex(Index('names_by_city'), 'Paris'));
        self::assertSame([], $this->client->query(Paginate($mixed))->data);
    }

    public function testNestedSets(): void
    {
        $staffInParis = Intersection(
            Union(MatchIndex(Index('users_by_role'), 'admin'), MatchIndex(Index('users_by_role'), 'editor')),
            MatchIndex(Index('users_by_city'), 'Paris'),
        );
        self::assertEquals($this->refs('Ada', 'Alan', 'Edsger'), $this->collectAll($staffInParis, 2));

        $set = Union(
            Intersection(MatchIndex(Index('users_by_role'), 'admin'), MatchIndex(Index('users_by_city'), 'Paris')),
            MatchIndex(Index('users_by_role'), 'viewer'),
            MatchIndex(Index('users_by_city'), 'Helsinki'),
        );
        self::assertEquals($this->refs('Ada', 'Linus', 'Margaret', 'Edsger'), $this->collectAll($set, 3));
    }

    public function testUnionWithAllDocuments(): void
    {
        $set = Union(Documents(Collection('users')), MatchIndex(Index('users_by_role'), 'admin'));

        self::assertEquals(array_values($this->users), $this->collectAll($set, 4));
    }

    public function testSingleton(): void
    {
        $admins = MatchIndex(Index('users_by_role'), 'admin');
        $ada = $this->users['Ada'];

        self::assertEquals([$ada], $this->client->query(Paginate(Singleton($ada)))->data);
        self::assertEquals([$ada, $this->users['Alan']], $this->client->query(Paginate(Union(Singleton($this->users['Alan']), Singleton($ada))))->data);
        self::assertEquals([$ada], $this->client->query(Paginate(Intersection($admins, Singleton($ada))))->data);
        self::assertEquals([], $this->client->query(Paginate(Intersection($admins, Singleton($this->users['Alan']))))->data);
        self::assertEquals([$this->users['Grace'], $this->users['Edsger']], $this->client->query(Paginate(Difference($admins, Singleton($ada))))->data);
        self::assertSame(['Ada', true, 1], $this->client->query([Select(['data', 'name'], Get(Singleton($ada))), Exists(Singleton($ada)), Size(Singleton($ada))]));
    }

    public function testSingletonHoldsADocumentWhileItExists(): void
    {
        $ada = $this->users['Ada'];
        $this->client->query(Update($ada, ['data' => ['city' => 'London']]));
        $this->client->query(Delete($ada));

        self::assertSame(0, $this->client->query(Size(Singleton($ada))));
        // Updates leave the document in the set: it enters it when created and leaves it when deleted.
        self::assertSame(['add', 'remove'], array_column($this->client->query(Paginate(Events(Singleton($ada))))->data, 'action'));
    }

    public function testGetAndExists(): void
    {
        $admins = MatchIndex(Index('users_by_role'), 'admin');

        // The first entry, in ref order.
        self::assertSame('Ada', $this->client->query(Get(Intersection($admins, MatchIndex(Index('users_by_city'), 'Paris'))))['data']['name']);
        self::assertTrue($this->client->query(Exists(Union($admins, MatchIndex(Index('users_by_city'), 'Nowhere')))));
        self::assertFalse($this->client->query(Exists(Intersection($admins, MatchIndex(Index('users_by_city'), 'Helsinki')))));

        $this->expectException(NotFound::class);
        $this->client->query(Get(Intersection($admins, MatchIndex(Index('users_by_city'), 'Helsinki'))));
    }

    public function testMapOverAPageOfASet(): void
    {
        $page = $this->client->query(Map(
            Paginate(Intersection(MatchIndex(Index('users_by_role'), 'admin'), MatchIndex(Index('users_by_city'), 'Paris'))),
            Lambda('ref', Select(['data', 'name'], Get(Variable('ref')))),
        ));

        self::assertInstanceOf(Page::class, $page);
        self::assertSame(['Ada', 'Edsger'], $page->data);
    }

    public function testDifference(): void
    {
        $admins = MatchIndex(Index('users_by_role'), 'admin');
        $parisians = MatchIndex(Index('users_by_city'), 'Paris');

        self::assertEquals($this->refs('Grace'), $this->collectAll(Difference($admins, $parisians), 1));
        self::assertEquals($this->refs('Linus'), $this->collectAll(Difference(Documents(Collection('users')), $admins, $parisians), 1));
    }

    public function testDifferenceKeepsDocumentsMissingTheTermsOfTheOthers(): void
    {
        $barbara = $this->client->query(Create(Collection('users'), ['data' => ['name' => 'Barbara', 'role' => 'admin']]))['ref'];

        $set = Difference(MatchIndex(Index('users_by_role'), 'admin'), MatchIndex(Index('users_by_city'), 'Paris'));

        // Barbara has no city: she does not live in Paris.
        self::assertEquals([...$this->refs('Grace'), $barbara], $this->collectAll($set, 1));
    }

    public function testDifferenceComparesValues(): void
    {
        // Same values (the age): the entries of the admins not living in Paris.
        $ages = Difference(MatchIndex(Index('ages_by_role'), 'admin'), MatchIndex(Index('ages_by_city'), 'Paris'));
        self::assertSame([85], $this->collectAll($ages, 1));

        // Different values (an age, a name): no entry of the first set is in the second.
        $mixed = Difference(MatchIndex(Index('ages_by_role'), 'admin'), MatchIndex(Index('names_by_city'), 'Paris'));
        self::assertSame([36, 72, 85], $this->collectAll($mixed, 2));
    }

    public function testNestedDifferences(): void
    {
        $admins = MatchIndex(Index('users_by_role'), 'admin');
        $editors = MatchIndex(Index('users_by_role'), 'editor');
        $viewers = MatchIndex(Index('users_by_role'), 'viewer');
        $parisians = MatchIndex(Index('users_by_city'), 'Paris');

        // Parisians who are not admins are Alan and Margaret: removing them from the admins removes nobody.
        self::assertEquals($this->refs('Ada', 'Grace', 'Edsger'), $this->collectAll(Difference($admins, Difference($parisians, $admins)), 2));
        self::assertEquals($this->refs('Grace'), $this->collectAll(Difference($admins, Difference($parisians, $editors)), 2));

        self::assertEquals($this->refs('Grace', 'Margaret'), $this->collectAll(Union(Difference($admins, $parisians), $viewers), 1));
        self::assertEquals(
            $this->refs('Grace', 'Alan', 'Linus'),
            $this->collectAll(Difference(Union($admins, $editors), Intersection($parisians, Union($admins, $viewers))), 2),
        );
        self::assertEquals(
            $this->refs('Ada', 'Alan', 'Edsger'),
            $this->collectAll(Intersection(Difference(Documents(Collection('users')), $viewers), $parisians), 2),
        );
    }

    public function testGetAndExistsOnADifference(): void
    {
        $admins = MatchIndex(Index('users_by_role'), 'admin');

        self::assertSame('Grace', $this->client->query(Get(Difference($admins, MatchIndex(Index('users_by_city'), 'Paris'))))['data']['name']);
        self::assertFalse($this->client->query(Exists(Difference($admins, $admins))));
    }

    public function testListsAreCombinedToo(): void
    {
        self::assertSame([1, 2, 3], $this->client->query(Union([1, 2], [2, 3])));
        self::assertSame([3, 2], $this->client->query(Intersection([3, 2, 1, 2], [2, 3, 4])));
        self::assertEquals([$this->users['Ada']], $this->client->query(Intersection([$this->users['Ada'], $this->users['Grace']], [$this->users['Ada']])));
        self::assertSame([1, 3], $this->client->query(Difference([1, 2, 3, 2, 1], [2], [4])));
        self::assertEquals([$this->users['Grace']], $this->client->query(Difference([$this->users['Ada'], $this->users['Grace']], [$this->users['Ada']])));
    }

    public function testDifferencesNeedComparableEntries(): void
    {
        $this->expectException(InvalidArgument::class);

        $this->client->query(Difference(MatchIndex(Index('users_by_role'), 'admin'), MatchIndex(Index('ages_by_city'), 'Paris')));
    }

    public function testSetsMustHoldComparableEntries(): void
    {
        $this->expectException(InvalidArgument::class);

        $this->client->query(Union(MatchIndex(Index('users_by_role'), 'admin'), MatchIndex(Index('ages_by_role'), 'admin')));
    }

    public function testSetsAndListsDoNotMix(): void
    {
        $this->expectException(InvalidArgument::class);

        $this->client->query(Union(MatchIndex(Index('users_by_role'), 'admin'), [1]));
    }

    /**
     * @return list<RefValue>
     */
    private function refs(string ...$names): array
    {
        return array_map(fn (string $name): RefValue => $this->users[$name], array_values($names));
    }
}
