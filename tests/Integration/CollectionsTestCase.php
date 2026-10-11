<?php declare(strict_types=1);

namespace Flora\Tests\Integration;

use Flora\Value\Ref as RefValue;

use function Flora\{
    Collection,
    Create,
    CreateCollection,
    CreateIndex,
    Difference,
    Documents,
    Events,
    Filter,
    Get,
    Index,
    Intersection,
    Join,
    Lambda,
    MatchIndex,
    Paginate,
    Ref,
    Size,
    Union,
    Update,
};

/**
 * Sets whose entries point to several collections.
 */
abstract class CollectionsTestCase extends IntegrationTestCase
{

    /** @var array<string, RefValue> */
    private array $refs = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['users', 'teams', 'members'] as $collection) {
            $this->client->query(CreateCollection(['name' => $collection]));
        }
        foreach (['users', 'teams'] as $collection) {
            $this->client->query(CreateIndex(['name' => $collection . '_by_tag', 'source' => Collection($collection), 'terms' => [['field' => ['data', 'tag']]]]));
            $this->client->query(CreateIndex(['name' => $collection . '_names', 'source' => Collection($collection), 'values' => [['field' => ['data', 'name']]]]));
        }
        $this->client->query(CreateIndex(['name' => 'members_by_owner', 'source' => Collection('members'), 'terms' => [['field' => ['data', 'owner']]]]));

        // Created in this order, so that their refs sort in it.
        $this->create('users', ['Ada' => 'x', 'Grace' => 'z']);
        $this->create('teams', ['Core' => 'x', 'Web' => 'x']);
        $this->create('users', ['Alan' => 'x']);
    }

    public function testUnionOfSetsOfSeveralCollections(): void
    {
        $tagged = Union(MatchIndex(Index('users_by_tag'), 'x'), MatchIndex(Index('teams_by_tag'), 'x'));

        self::assertEquals($this->refs('Ada', 'Core', 'Web', 'Alan'), $this->collectAll($tagged, 1));
        self::assertSame(4, $this->client->query(Size($tagged)));
        self::assertSame('Ada', $this->client->query(Get($tagged))['data']['name']);
    }

    public function testEntriesSortByTheirValuesAcrossCollections(): void
    {
        $names = Union(MatchIndex(Index('users_names')), MatchIndex(Index('teams_names')));

        self::assertSame(['Ada', 'Alan', 'Core', 'Grace', 'Web'], $this->collectAll($names, 2));
    }

    public function testDocumentsWithTheSameIdInTwoCollections(): void
    {
        $this->client->query([
            Create(Ref(Collection('users'), '5'), ['data' => ['tag' => 'y']]),
            Create(Ref(Collection('teams'), '5'), ['data' => ['tag' => 'y']]),
            Create(Ref(Collection('users'), '6'), ['data' => ['tag' => 'y']]),
        ]);
        $tagged = Union(MatchIndex(Index('users_by_tag'), 'y'), MatchIndex(Index('teams_by_tag'), 'y'));

        // Two different entries, sorted by id then collection, even read one by one.
        $expected = [Ref(Collection('teams'), '5'), Ref(Collection('users'), '5'), Ref(Collection('users'), '6')];
        self::assertEquals($this->client->query($expected), $this->collectAll($tagged, 1));
        self::assertSame(3, $this->client->query(Size($tagged)));
    }

    public function testIntersectionOfSetsOfSeveralCollectionsIsEmpty(): void
    {
        $both = Intersection(MatchIndex(Index('users_by_tag'), 'x'), MatchIndex(Index('teams_by_tag'), 'x'));

        self::assertSame([], $this->client->query(Paginate($both))->data);
        self::assertSame(0, $this->client->query(Size($both)));
    }

    public function testDifferenceOfSetsOfSeveralCollections(): void
    {
        $users = MatchIndex(Index('users_by_tag'), 'x');
        $teams = MatchIndex(Index('teams_by_tag'), 'x');

        self::assertEquals($this->refs('Ada', 'Alan'), $this->collectAll(Difference(Union($users, $teams), $teams), 1));
        self::assertEquals($this->refs('Ada', 'Alan'), $this->collectAll(Difference($users, $teams), 1));
    }

    public function testJoinOfASetOfSeveralCollections(): void
    {
        $members = $this->client->query([
            Create(Collection('members'), ['data' => ['owner' => $this->refs['Ada']]]),
            Create(Collection('members'), ['data' => ['owner' => $this->refs['Web']]]),
            Create(Collection('members'), ['data' => ['owner' => $this->refs['Grace']]]),
        ]);

        $owned = Join(Union(MatchIndex(Index('users_by_tag'), 'x'), MatchIndex(Index('teams_by_tag'), 'x')), Index('members_by_owner'));

        self::assertEquals([$members[0]['ref'], $members[1]['ref']], $this->collectAll($owned, 1));
    }

    public function testFilteringASetOfSeveralCollections(): void
    {
        $tagged = Union(MatchIndex(Index('users_by_tag'), 'x'), MatchIndex(Index('teams_by_tag'), 'x'));
        $kept = Filter($tagged, Lambda('ref', true));

        self::assertSame(4, $this->client->query(Size($kept)));
        self::assertSame(5, $this->client->query(Size(Union(Documents(Collection('users')), Documents(Collection('teams'))))));
    }

    public function testEventsOfASetOfSeveralCollections(): void
    {
        $this->client->query(Update($this->refs['Ada'], ['data' => ['tag' => 'z']]));

        $events = $this->collectAll(Events(Union(MatchIndex(Index('users_by_tag'), 'x'), MatchIndex(Index('teams_by_tag'), 'x'))), 2);

        // Ada and Alan were created tagged x, then the teams, then Ada changed her tag.
        self::assertSame(['add', 'add', 'add', 'add', 'remove'], array_column($events, 'action'));
        self::assertEquals($this->refs('Ada', 'Core', 'Web', 'Alan', 'Ada'), array_column($events, 'document'));
    }

    /**
     * @param array<string, string> $documents the tag of each document, by name
     */
    private function create(string $collection, array $documents): void
    {
        $created = $this->client->query(array_map(
            static fn (string $name, string $tag): mixed => Create(Collection($collection), ['data' => ['name' => $name, 'tag' => $tag]]),
            array_keys($documents),
            $documents,
        ));

        foreach ($created as $document) {
            $this->refs[$document['data']['name']] = $document['ref'];
        }
    }

    /**
     * @return list<RefValue>
     */
    private function refs(string ...$names): array
    {
        return array_map(fn (string $name): RefValue => $this->refs[$name], array_values($names));
    }
}
