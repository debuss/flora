<?php declare(strict_types=1);

namespace Flora\Tests\Integration;

use Flora\Exception\InvalidArgument;
use Flora\Value\Ref as RefValue;

use function Flora\{
    At,
    Between,
    Collection,
    Create,
    CreateCollection,
    CreateIndex,
    Difference,
    Documents,
    Equals,
    Events,
    Filter,
    Get,
    Index,
    Intersection,
    Join,
    Lambda,
    MatchIndex,
    Paginate,
    Select,
    Size,
    Timestamp,
    Union,
    Update,
    Variable,
};

abstract class BetweenTestCase extends IntegrationTestCase
{

    /** @var array<string, RefValue> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->client->query(CreateCollection(['name' => 'users']));
        foreach ([
            'ages' => [[], [['field' => ['data', 'age']], ['field' => ['ref']]]],
            'ages_desc' => [[], [['field' => ['data', 'age'], 'reverse' => true], ['field' => ['ref']]]],
            'ages_and_names' => [[], [['field' => ['data', 'age']], ['field' => ['data', 'name']], ['field' => ['ref']]]],
            'ages_by_role' => [[['field' => ['data', 'role']]], [['field' => ['data', 'age']], ['field' => ['ref']]]],
            'ages_by_city' => [[['field' => ['data', 'city']]], [['field' => ['data', 'age']], ['field' => ['ref']]]],
            'ages_by_name' => [[['field' => ['data', 'name']]], [['field' => ['data', 'age']], ['field' => ['ref']]]],
            'names' => [[], [['field' => ['data', 'name']]]],
            'births' => [[], [['field' => ['data', 'born']], ['field' => ['ref']]]],
            'tags' => [[], [['field' => ['data', 'tags'], 'unroll' => true], ['field' => ['ref']]]],
        ] as $name => [$terms, $values]) {
            $this->client->query(CreateIndex(['name' => $name, 'source' => Collection('users'), 'terms' => $terms, 'values' => $values]));
        }

        // Created in one query, so their refs sort in this order. By age: Margaret, Ada, Barbara, Alan, Linus, Edsger,
        // Grace.
        $users = $this->client->query(array_map(
            static fn (array $user): mixed => Create(Collection('users'), ['data' => [
                'name' => $user[0],
                'role' => $user[1],
                'city' => $user[2],
                'age' => $user[3],
                'born' => Timestamp($user[4] . 'T00:00:00Z'),
                'tags' => $user[5],
            ]]),
            [
                ['Ada', 'admin', 'Paris', 36, '1815-12-10', ['math', 'poetry']],
                ['Grace', 'admin', 'London', 85, '1906-12-09', ['navy', 'compilers']],
                ['Alan', 'editor', 'Paris', 41, '1912-06-23', ['math', 'crypto']],
                ['Linus', 'editor', 'Helsinki', 54, '1969-12-28', ['kernel']],
                ['Margaret', 'viewer', 'Paris', 33, '1936-08-17', ['apollo']],
                ['Edsger', 'admin', 'Paris', 72, '1930-05-11', ['compilers', 'math']],
                ['Barbara', 'viewer', 'London', 36, '1939-11-03', ['compilers']],
            ],
        ));
        foreach ($users as $user) {
            $this->users[$user['data']['name']] = $user['ref'];
        }
    }

    public function testBetween(): void
    {
        self::assertSame(['Ada', 'Barbara', 'Alan', 'Linus'], $this->names(Between(MatchIndex(Index('ages')), 36, 54)));
    }

    public function testBoundsAreIncludedPrefixes(): void
    {
        $ages = MatchIndex(Index('ages'));

        self::assertSame(['Ada', 'Barbara'], $this->names(Between($ages, [36], [36])));
        self::assertSame(['Barbara', 'Alan', 'Linus'], $this->names(Between($ages, [36, $this->users['Barbara']], [54])));
        self::assertSame(['Margaret', 'Ada'], $this->names(Between($ages, [33], [36, $this->users['Ada']])));
    }

    public function testEmptyBoundsAreOpen(): void
    {
        $ages = MatchIndex(Index('ages'));

        self::assertSame(['Margaret', 'Ada', 'Barbara'], $this->names(Between($ages, [], 36)));
        self::assertSame(['Edsger', 'Grace'], $this->names(Between($ages, 72, [])));
        self::assertCount(7, $this->names(Between($ages, [], [])));
        self::assertSame([], $this->names(Between($ages, 60, 40)));
    }

    public function testBoundsFollowTheOrderOfTheIndex(): void
    {
        $ages = MatchIndex(Index('ages_desc'));

        self::assertSame(['Linus', 'Alan', 'Ada', 'Barbara'], $this->names(Between($ages, 54, 36)));
        self::assertSame([], $this->names(Between($ages, 36, 54)));
    }

    public function testBoundsOnSeveralValues(): void
    {
        $set = MatchIndex(Index('ages_and_names'));

        self::assertSame(['Barbara', 'Alan', 'Linus'], $this->names(Between($set, [36, 'B'], [54])));
        self::assertSame(['Ada'], $this->names(Between($set, [36, 'Ada'], [36, 'Ada'])));
    }

    public function testBoundsOnRefs(): void
    {
        self::assertSame(['Alan', 'Linus', 'Margaret'], $this->names(Between(Documents(Collection('users')), $this->users['Alan'], $this->users['Margaret'])));
    }

    public function testBoundsOnInstants(): void
    {
        $births = Between(MatchIndex(Index('births')), Timestamp('1900-01-01T00:00:00Z'), Timestamp('1940-01-01T00:00:00Z'));

        self::assertSame(['Grace', 'Alan', 'Edsger', 'Margaret', 'Barbara'], $this->names($births));
    }

    public function testPaginatingAndSizing(): void
    {
        $set = Between(MatchIndex(Index('ages')), 36, 54);

        $names = array_map(fn (array $entry): string => $this->userOf($entry), $this->collectAll($set, 2));
        self::assertSame(['Ada', 'Barbara', 'Alan', 'Linus'], $names);
        self::assertSame(4, $this->client->query(Size($set)));
    }

    public function testBetweenWithTerms(): void
    {
        self::assertSame(['Edsger'], $this->names(Between(MatchIndex(Index('ages_by_role'), 'admin'), 40, 80)));
    }

    public function testCombiningSets(): void
    {
        $byRole = static fn (string $role): mixed => MatchIndex(Index('ages_by_role'), $role);
        $ages = MatchIndex(Index('ages'));
        $paris = MatchIndex(Index('ages_by_city'), 'Paris');

        self::assertSame(['Ada', 'Barbara'], $this->names(Union(Between($byRole('admin'), [], 40), Between($byRole('viewer'), 35, []))));
        self::assertSame(['Alan', 'Linus'], $this->names(Between(Union($byRole('admin'), $byRole('editor')), 40, 60)));
        self::assertSame(['Ada', 'Alan'], $this->names(Intersection(Between($ages, 35, 60), $paris)));
        self::assertSame(['Barbara'], $this->names(Difference(Between($ages, 30, 50), $paris)));
        self::assertSame(['Margaret', 'Edsger'], $this->names(Difference($paris, Between($ages, 35, 45))));
        self::assertSame(['Alan', 'Linus'], $this->names(Between(Between($ages, 30, 60), 40, 100)));
        self::assertSame(3, $this->client->query(Size(Union(Between($byRole('admin'), [], 40), Between($ages, 50, 60), Between($byRole('viewer'), 35, [])))));
    }

    public function testJoins(): void
    {
        $join = static fn (mixed $source, mixed $body): mixed => Join($source, Lambda('name', $body(MatchIndex(Index('ages_by_name'), Variable('name')))));
        $same = static fn (mixed $set): mixed => $set;

        // Names from A to B: Barbara comes after B.
        self::assertSame(['Ada', 'Alan'], $this->names($join(Between(MatchIndex(Index('names')), 'A', 'B'), $same)));
        self::assertSame(['Alan', 'Linus', 'Edsger', 'Grace'], $this->names(Between($join(MatchIndex(Index('names')), $same), 40, [])));
        self::assertSame(['Alan'], $this->names($join(Between(MatchIndex(Index('names')), 'A', 'B'), static fn (mixed $set): mixed => Between($set, 40, []))));
    }

    public function testUnrolledIndexes(): void
    {
        $tags = Between(MatchIndex(Index('tags')), 'c', 'm');

        // "math" comes after "m".
        self::assertSame(['Grace', 'Edsger', 'Barbara', 'Alan', 'Linus'], $this->names($tags));
        self::assertSame(5, $this->client->query(Size($tags)));
    }

    public function testFilters(): void
    {
        $paris = Lambda(['age', 'ref'], Equals(Select(['data', 'city'], Get(Variable('ref'))), 'Paris'));
        $ages = MatchIndex(Index('ages'));

        self::assertSame(['Ada', 'Alan'], $this->names(Between(Filter($ages, $paris), 35, 60)));
        self::assertSame(['Ada', 'Alan'], $this->names(Filter(Between($ages, 35, 60), $paris)));
    }

    public function testInThePast(): void
    {
        $then = $this->client->query(Get($this->users['Ada']))['ts'];
        $this->client->query(Update($this->users['Ada'], ['data' => ['age' => 50, 'tags' => ['compilers']]]));

        self::assertSame(['Alan', 'Linus'], $this->names(Between(MatchIndex(Index('ages')), 40, 60), $then));
        self::assertSame(['Alan', 'Ada', 'Linus'], $this->names(Between(MatchIndex(Index('ages')), 40, 60)));
        self::assertSame(['Grace', 'Edsger', 'Barbara', 'Alan', 'Linus'], $this->names(Between(MatchIndex(Index('tags')), 'c', 'm'), $then));
        self::assertSame(5, $this->client->query(At($then, Size(Between(MatchIndex(Index('tags')), 'c', 'm')))));
        self::assertSame(['Ada', 'Grace', 'Edsger', 'Barbara', 'Alan', 'Linus'], $this->names(Between(MatchIndex(Index('tags')), 'c', 'm')));
    }

    public function testEvents(): void
    {
        $created = $this->client->query(Get($this->users['Ada']))['ts'];
        $older = $this->client->query(Update($this->users['Ada'], ['data' => ['age' => 50]]));
        $oldest = $this->client->query(Update($this->users['Ada'], ['data' => ['age' => 70]]));

        $events = $this->client->query(Paginate(Events(Between(MatchIndex(Index('ages')), 40, 60))))->data;

        self::assertSame(
            [['add', $created, 'Alan'], ['add', $created, 'Linus'], ['add', $older['ts'], 'Ada'], ['remove', $oldest['ts'], 'Ada']],
            array_map(fn (array $event): array => [$event['action'], $event['ts'], $this->userOf($event['data'])], $events),
        );
    }

    public function testBoundsHoldValuesThenARef(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('hold 2 value(s) and a ref: Between() from holds 4');

        $this->client->query(Paginate(Between(MatchIndex(Index('ages')), [36, $this->users['Ada'], $this->users['Ada'], 1], [])));
    }

    public function testBoundsHoldRefsWhereEntriesDo(): void
    {
        $this->expectException(InvalidArgument::class);

        $this->client->query(Paginate(Between(MatchIndex(Index('ages')), [36, 'Ada'], [])));
    }

    public function testBetweenTakesASet(): void
    {
        $this->expectException(InvalidArgument::class);

        $this->client->query(Between([1, 2, 3], 1, 2));
    }

    /**
     * The names of the users of the entries of a set, whose last value is a ref, or which are refs.
     *
     * @param int|null $at the time to read the set at
     * @return list<string>
     */
    private function names(mixed $set, ?int $at = null): array
    {
        $page = Paginate($set, ['size' => 100]);

        return array_values(array_map(fn (mixed $item): string => $this->userOf($item), $this->client->query($at === null ? $page : At($at, $page))->data));
    }

    private function userOf(mixed $item): string
    {
        $ref = $item instanceof RefValue ? $item : $item[array_key_last($item)];

        $name = array_search($ref, $this->users);
        self::assertIsString($name, 'Not a user: ' . var_export($item, true));

        return $name;
    }
}
