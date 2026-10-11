<?php declare(strict_types=1);

namespace Flora\Tests\Integration;

use Flora\Exception\InvalidArgument;

use function Flora\{
    Collection,
    Create,
    CreateCollection,
    CreateIndex,
    Delete,
    Difference,
    Documents,
    Equals,
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
    Union,
    Variable,
};

abstract class SizesTestCase extends IntegrationTestCase
{

    protected function setUp(): void
    {
        parent::setUp();

        $this->client->query(CreateCollection(['name' => 'users']));
        $this->client->query(CreateCollection(['name' => 'posts']));
        foreach ([
            'users_by_role' => ['users', ['role'], [], false],
            'users_by_city' => ['users', ['city'], [], false],
            'posts_by_author' => ['posts', ['authors'], [], true],
            'tags' => ['posts', [], ['tags'], true],
        ] as $name => [$collection, $terms, $values, $unroll]) {
            $this->client->query(CreateIndex([
                'name' => $name,
                'source' => Collection($collection),
                'terms' => array_map(static fn (string $term): array => ['field' => ['data', $term], 'unroll' => $unroll], $terms),
                'values' => array_map(static fn (string $value): array => ['field' => ['data', $value], 'unroll' => $unroll], $values),
            ]));
        }

        $users = $this->client->query(array_map(
            static fn (array $user): mixed => Create(Collection('users'), ['data' => ['name' => $user[0], 'role' => $user[1], 'city' => $user[2]]]),
            [['Ada', 'admin', 'Paris'], ['Grace', 'admin', 'London'], ['Alan', 'editor', 'Paris'], ['Linus', 'viewer', 'Helsinki']],
        ));
        [$ada, $grace, $alan] = array_map(static fn (array $user): mixed => $user['ref'], $users);

        $this->client->query([
            Create(Collection('posts'), ['data' => ['authors' => [$ada], 'tags' => ['php', 'sql']]]),
            Create(Collection('posts'), ['data' => ['authors' => [$grace, $ada], 'tags' => ['php']]]),
            Create(Collection('posts'), ['data' => ['authors' => [$alan], 'tags' => ['go', 'go']]]),
        ]);
    }

    public function testCountingListsAndPages(): void
    {
        self::assertSame(3, $this->client->query(Size([1, 2, 2])));
        self::assertSame(2, $this->client->query(Size(Paginate(Documents(Collection('users')), ['size' => 2]))));
    }

    public function testCountingSets(): void
    {
        self::assertSame(4, $this->client->query(Size(Documents(Collection('users')))));
        self::assertSame(2, $this->client->query(Size(MatchIndex(Index('users_by_role'), 'admin'))));
        self::assertSame(0, $this->client->query(Size(MatchIndex(Index('users_by_role'), 'nobody'))));
    }

    public function testCountingCombinedSets(): void
    {
        $admins = MatchIndex(Index('users_by_role'), 'admin');
        $parisians = MatchIndex(Index('users_by_city'), 'Paris');

        // Ada is in both sets, and counts once.
        self::assertSame(3, $this->client->query(Size(Union($admins, $parisians))));
        self::assertSame(1, $this->client->query(Size(Intersection($admins, $parisians))));
        self::assertSame(1, $this->client->query(Size(Difference($admins, $parisians))));
    }

    public function testCountingUnrolledIndexes(): void
    {
        // One entry per tag of each post: php twice, sql and go once (the repeated go of a post counts once).
        self::assertSame(4, $this->client->query(Size(MatchIndex(Index('tags')))));

        // The post written by two admins is found twice by the join, and counts once.
        self::assertSame(2, $this->client->query(Size(Join(MatchIndex(Index('users_by_role'), 'admin'), Index('posts_by_author')))));
    }

    public function testCountingFilteredSets(): void
    {
        $inParis = Lambda('ref', Equals(Select(['data', 'city'], Get(Variable('ref'))), 'Paris'));

        self::assertSame(2, $this->client->query(Size(Filter(Documents(Collection('users')), $inParis))));

        // More users than a chunk read at once: counting goes on from the last one read.
        $this->client->query(array_map(
            static fn (int $i): mixed => Create(Collection('users'), ['data' => ['role' => 'user', 'city' => $i % 2 === 0 ? 'Paris' : 'Rome']]),
            range(1, 1100),
        ));
        self::assertSame(552, $this->client->query(Size(Filter(Documents(Collection('users')), $inParis))));
    }

    public function testCountsFollowWrites(): void
    {
        $users = Documents(Collection('users'));
        $first = $this->client->query(Paginate($users, ['size' => 1]))->data[0];

        $this->client->query(Delete($first));

        self::assertSame(3, $this->client->query(Size($users)));
    }

    public function testCountingAnythingElse(): void
    {
        $this->expectException(InvalidArgument::class);

        $this->client->query(Size('three'));
    }
}
