<?php declare(strict_types=1);

namespace Flora\Tests\Integration;

use DateTimeImmutable;
use Flora\Cursor;
use Flora\Exception\{AlreadyExists, InvalidArgument, NotFound, NotUnique};
use Flora\Value\{CollectionRef, Page, Ref as RefValue};

use function Flora\{
    Collection,
    Collections,
    Create,
    CreateCollection,
    CreateIndex,
    Documents,
    Exists,
    Get,
    Index,
    Lambda,
    Map,
    MatchIndex,
    Paginate,
    Select,
    Update,
    Variable,
};

abstract class IndexesTestCase extends IntegrationTestCase
{

    protected function setUp(): void
    {
        parent::setUp();

        $this->client->query(CreateCollection(['name' => 'users']));
    }

    public function testMatchOnATerm(): void
    {
        $this->client->query(CreateIndex([
            'name' => 'users_by_email',
            'source' => Collection('users'),
            'terms' => [['field' => ['data', 'email']]],
        ]));
        $this->createUsers(['email' => 'ada@example.com', 'name' => 'Ada'], ['email' => 'grace@example.com', 'name' => 'Grace']);

        $user = $this->client->query(Get(MatchIndex(Index('users_by_email'), 'grace@example.com')));

        self::assertSame('Grace', $user['data']['name']);
        self::assertTrue($this->client->query(Exists(MatchIndex(Index('users_by_email'), 'ada@example.com'))));
        self::assertFalse($this->client->query(Exists(MatchIndex(Index('users_by_email'), 'nobody@example.com'))));
    }

    public function testIndexesReadFieldsStartingWithAt(): void
    {
        $this->createUsers(['@type' => 'Person', 'value' => ['@ts' => 'oops'], '@tags' => ['a', 'b']]);
        foreach ([
            'users_by_type' => ['terms' => [['field' => ['data', '@type']]], 'values' => [['field' => ['data', 'value']], ['field' => ['data', 'value', '@ts']]]],
            'users_by_value' => ['terms' => [['field' => ['data', 'value']]]],
            'users_by_tag' => ['terms' => [['field' => ['data', '@tags'], 'unroll' => true]], 'values' => [['field' => ['data', 'value']]]],
        ] as $name => $params) {
            $this->client->query(CreateIndex(['name' => $name, 'source' => Collection('users'), ...$params]));
        }
        $this->createUsers(['@type' => 'Person', 'value' => ['@ts' => 'again'], '@tags' => ['b']]);

        self::assertSame(
            [[['@ts' => 'again'], 'again'], [['@ts' => 'oops'], 'oops']],
            $this->client->query(Paginate(MatchIndex(Index('users_by_type'), 'Person')))->data,
        );
        self::assertTrue($this->client->query(Exists(MatchIndex(Index('users_by_value'), ['@ts' => 'oops']))));
        self::assertSame([['@ts' => 'again'], ['@ts' => 'oops']], $this->client->query(Paginate(MatchIndex(Index('users_by_tag'), 'b')))->data);
    }

    public function testCursorsTravelAsStrings(): void
    {
        $this->client->query(CreateIndex([
            'name' => 'users_by_creation',
            'source' => Collection('users'),
            'values' => [['field' => ['data', 'created'], 'reverse' => true], ['field' => ['data', 'profile']], ['field' => ['ref']]],
        ]));
        foreach (range(1, 5) as $i) {
            $this->createUsers(['created' => new DateTimeImmutable(sprintf('2026-10-0%dT12:00:00.5Z', $i)), 'profile' => ['@type' => 'Person', 'n' => $i]]);
        }
        $set = MatchIndex(Index('users_by_creation'));

        // What a client would send back, page after page, then backwards.
        $pages = [];
        $after = null;
        do {
            $page = $this->client->query(Paginate($set, ['size' => 2, ...($after === null ? [] : ['after' => Cursor::decode($after)])]));
            $pages[] = array_map(static fn (array $item): int => $item[1]['n'], $page->data);
            $after = Cursor::encode($page->after);
        } while ($after !== null);
        $back = $this->client->query(Paginate($set, ['size' => 2, 'before' => Cursor::decode(Cursor::encode($page->before))]));

        self::assertSame([[5, 4], [3, 2], [1]], $pages);
        self::assertSame([3, 2], array_map(static fn (array $item): int => $item[1]['n'], $back->data));
    }

    public function testCursorsOfTheSchemaTravelAsStrings(): void
    {
        $this->client->query([CreateCollection(['name' => 'posts']), CreateCollection(['name' => 'tags'])]);

        $page = $this->client->query(Paginate(Collections(), ['size' => 1]));
        $next = $this->client->query(Paginate(Collections(), ['size' => 1, 'after' => Cursor::decode(Cursor::encode($page->after))]));

        self::assertEquals([new CollectionRef('posts')], $page->data);
        self::assertEquals([new CollectionRef('tags')], $next->data);
    }

    public function testIndexesAreBuiltFromExistingDocuments(): void
    {
        $this->createUsers(['email' => 'ada@example.com']);

        $this->client->query(CreateIndex([
            'name' => 'users_by_email',
            'source' => Collection('users'),
            'terms' => [['field' => ['data', 'email']]],
        ]));

        self::assertTrue($this->client->query(Exists(MatchIndex(Index('users_by_email'), 'ada@example.com'))));
    }

    public function testPagesOfLargeNumbers(): void
    {
        $this->client->query(CreateIndex(['name' => 'scores', 'source' => Collection('users'), 'values' => [['field' => ['data', 'score']]]]));
        $this->client->query(array_map(
            static fn (int|float $score): mixed => Create(Collection('users'), ['data' => ['score' => $score]]),
            [1e300, 1, -1e300, 1.5e20, PHP_INT_MAX],
        ));

        $scores = [];
        $page = $this->client->query(Paginate(MatchIndex(Index('scores')), ['size' => 1]));
        $scores[] = $page->data;
        while ($page->after !== null && count($scores) < 10) {
            $page = $this->client->query(Paginate(MatchIndex(Index('scores')), ['size' => 1, 'after' => $page->after]));
            $scores[] = $page->data;
        }

        self::assertSame([-1e300, 1, PHP_INT_MAX, 1.5e20, 1e300], array_merge(...$scores));
    }

    public function testTermsKeepTheirJsonType(): void
    {
        $this->client->query(CreateIndex(['name' => 'users_by_age', 'source' => Collection('users'), 'terms' => [['field' => ['data', 'age']]]]));
        $this->createUsers(['age' => 36]);

        self::assertTrue($this->client->query(Exists(MatchIndex(Index('users_by_age'), 36))));
        self::assertTrue($this->client->query(Exists(MatchIndex(Index('users_by_age'), 36.0))));
        self::assertFalse($this->client->query(Exists(MatchIndex(Index('users_by_age'), '36'))));
    }

    public function testTermsMatchExactly(): void
    {
        $this->client->query(CreateIndex(['name' => 'users_by_email', 'source' => Collection('users'), 'terms' => [['field' => ['data', 'email']]]]));
        $prefix = str_repeat('x', 200);
        $this->createUsers(['email' => $prefix . 'a', 'name' => 'A'], ['email' => $prefix . 'b', 'name' => 'B'], ['email' => 'Ada@example.com', 'name' => 'Ada']);

        // Long values share their first characters, which some drivers index alone.
        self::assertSame('B', $this->client->query(Get(MatchIndex(Index('users_by_email'), $prefix . 'b')))['data']['name']);
        self::assertFalse($this->client->query(Exists(MatchIndex(Index('users_by_email'), $prefix))));
        self::assertFalse($this->client->query(Exists(MatchIndex(Index('users_by_email'), 'ada@example.com'))));
    }

    public function testSeveralTermsAndNestedPaths(): void
    {
        $this->client->query(CreateIndex([
            'name' => 'users_by_city_and_role',
            'source' => Collection('users'),
            'terms' => [['field' => ['data', 'address', 'city']], ['field' => ['data', 'role']]],
        ]));
        $this->createUsers(
            ['name' => 'Ada', 'address' => ['city' => 'London'], 'role' => 'admin'],
            ['name' => 'Grace', 'address' => ['city' => 'London'], 'role' => 'user'],
        );

        $user = $this->client->query(Get(MatchIndex(Index('users_by_city_and_role'), 'London', 'user')));
        self::assertSame('Grace', $user['data']['name']);

        $this->expectException(InvalidArgument::class);
        $this->client->query(MatchIndex(Index('users_by_city_and_role'), 'London'));
    }

    public function testValuesAreReturnedAndSorted(): void
    {
        $this->client->query(CreateIndex([
            'name' => 'users_by_role',
            'source' => Collection('users'),
            'terms' => [['field' => ['data', 'role']]],
            'values' => [['field' => ['data', 'age'], 'reverse' => true], ['field' => ['data', 'name']], ['field' => ['ref']]],
        ]));
        $this->createUsers(
            ['name' => 'Ada', 'role' => 'admin', 'age' => 36],
            ['name' => 'Grace', 'role' => 'admin', 'age' => 85],
            ['name' => 'Alan', 'role' => 'admin', 'age' => 9],
            ['name' => 'Linus', 'role' => 'user', 'age' => 50],
        );

        $page = $this->client->query(Paginate(MatchIndex(Index('users_by_role'), 'admin')));

        // Numbers sort as numbers (85 > 36 > 9), not as strings.
        self::assertSame([[85, 'Grace'], [36, 'Ada'], [9, 'Alan']], array_map(static fn (array $item): array => array_slice($item, 0, 2), $page->data));
        self::assertInstanceOf(RefValue::class, $page->data[0][2]);
    }

    public function testASingleValueIsReturnedAsIs(): void
    {
        $this->client->query(CreateIndex(['name' => 'user_names', 'source' => Collection('users'), 'values' => [['field' => ['data', 'name']]]]));
        $this->createUsers(['name' => 'Grace'], ['name' => 'Ada'], []);

        // A missing value is null, and null sorts first.
        self::assertSame([null, 'Ada', 'Grace'], $this->client->query(Paginate(MatchIndex(Index('user_names'))))->data);
    }

    public function testPaginateForwardAndBackward(): void
    {
        $this->client->query(CreateIndex(['name' => 'users_by_rank', 'source' => Collection('users'), 'values' => [['field' => ['data', 'rank']]]]));
        $this->createUsers(...array_map(static fn (int $rank): array => ['rank' => $rank], range(1, 7)));
        $set = MatchIndex(Index('users_by_rank'));

        $first = $this->client->query(Paginate($set, ['size' => 3]));
        self::assertSame([1, 2, 3], $first->data);
        self::assertNull($first->before);

        $second = $this->client->query(Paginate($set, ['size' => 3, 'after' => $first->after]));
        self::assertSame([4, 5, 6], $second->data);

        $third = $this->client->query(Paginate($set, ['size' => 3, 'after' => $second->after]));
        self::assertSame([7], $third->data);
        self::assertNull($third->after);

        $back = $this->client->query(Paginate($set, ['size' => 3, 'before' => $third->before]));
        self::assertSame([4, 5, 6], $back->data);
        self::assertEquals($third->before, $back->after);

        $start = $this->client->query(Paginate($set, ['size' => 3, 'before' => $back->before]));
        self::assertSame([1, 2, 3], $start->data);
        self::assertNull($start->before);
    }

    public function testPaginateWithMixedDirections(): void
    {
        $this->client->query(CreateIndex([
            'name' => 'users_by_team',
            'source' => Collection('users'),
            'values' => [['field' => ['data', 'team']], ['field' => ['data', 'score'], 'reverse' => true]],
        ]));
        $this->createUsers(
            ['team' => 'a', 'score' => 1],
            ['team' => 'b', 'score' => 3],
            ['team' => 'a', 'score' => 2],
            ['team' => 'b', 'score' => 1],
            ['team' => 'a', 'score' => 3],
        );
        $expected = [['a', 3], ['a', 2], ['a', 1], ['b', 3], ['b', 1]];

        self::assertSame($expected, $this->collectAll(MatchIndex(Index('users_by_team')), 2));
    }

    public function testDocumentsOfACollection(): void
    {
        $refs = array_map(static fn (array $user): RefValue => $user['ref'], $this->createUsers(['n' => 1], ['n' => 2], ['n' => 3]));

        self::assertEquals($refs, $this->collectAll(Documents(Collection('users')), 2));
    }

    public function testMapGetOverAPage(): void
    {
        $this->client->query(CreateIndex(['name' => 'users_by_role', 'source' => Collection('users'), 'terms' => [['field' => ['data', 'role']]]]));
        $this->createUsers(['name' => 'Ada', 'role' => 'admin'], ['name' => 'Linus', 'role' => 'user']);

        $page = $this->client->query(Map(
            Paginate(MatchIndex(Index('users_by_role'), 'admin')),
            Lambda('ref', Select(['data', 'name'], Get(Variable('ref')))),
        ));

        self::assertInstanceOf(Page::class, $page);
        self::assertSame(['Ada'], $page->data);
    }

    public function testMatchingARefStoredInData(): void
    {
        $this->client->query(CreateCollection(['name' => 'posts']));
        $this->client->query(CreateIndex(['name' => 'posts_by_author', 'source' => Collection('posts'), 'terms' => [['field' => ['data', 'author']]]]));
        [$ada, $grace] = $this->createUsers(['name' => 'Ada'], ['name' => 'Grace']);
        $this->client->query(Create(Collection('posts'), ['data' => ['title' => 'Notes', 'author' => $ada['ref']]]));

        self::assertSame('Notes', $this->client->query(Get(MatchIndex(Index('posts_by_author'), $ada['ref'])))['data']['title']);
        self::assertFalse($this->client->query(Exists(MatchIndex(Index('posts_by_author'), $grace['ref']))));
    }

    public function testUniqueIndexes(): void
    {
        $this->client->query(CreateIndex([
            'name' => 'users_by_email',
            'source' => Collection('users'),
            'terms' => [['field' => ['data', 'email']]],
            'unique' => true,
        ]));
        [$ada] = $this->createUsers(['email' => 'ada@example.com'], ['email' => 'grace@example.com'], ['name' => 'no email'], ['name' => 'no email either']);

        // Updating a document with its own value is fine.
        $this->client->query(Update($ada['ref'], ['data' => ['email' => 'ada@example.com', 'name' => 'Ada']]));

        $this->expectException(NotUnique::class);
        $this->client->query(Update($ada['ref'], ['data' => ['email' => 'grace@example.com']]));
    }

    public function testNotUniqueNamesTheIndex(): void
    {
        foreach ([
            'users_by_username' => ['terms' => [['field' => ['data', 'username']]]],
            'users_by_email' => ['terms' => [['field' => ['data', 'email']]]],
            'users_by_city_age' => ['terms' => [['field' => ['data', 'city']]], 'values' => [['field' => ['data', 'age']]]],
            'users_by_code' => ['values' => [['field' => ['data', 'code']]]],
            'users_by_handle' => ['terms' => [['field' => ['data', 'handles'], 'unroll' => true]]],
        ] as $name => $params) {
            $this->client->query(CreateIndex(['name' => $name, 'source' => Collection('users'), 'unique' => true, ...$params]));
        }
        $this->createUsers(['username' => 'ada', 'email' => 'ada@example.com', 'city' => 'London', 'age' => 36, 'code' => 'a', 'handles' => ['ada']]);

        foreach ([
            'users_by_username' => ['username' => 'ada'],
            'users_by_email' => ['username' => 'grace', 'email' => 'ada@example.com'],
            'users_by_city_age' => ['city' => 'London', 'age' => 36],
            'users_by_code' => ['code' => 'a'],
            'users_by_handle' => ['handles' => ['grace', 'ada']],
        ] as $index => $data) {
            try {
                $this->createUsers($data);
                self::fail(sprintf('The document should break %s.', $index));
            } catch (NotUnique $e) {
                self::assertSame($index, $e->index);
                self::assertSame(sprintf('The document breaks the unique index "%s".', $index), $e->getMessage());
            }
        }
    }

    public function testUniqueIndexesCheckExistingDocuments(): void
    {
        $this->createUsers(['email' => 'ada@example.com'], ['email' => 'ada@example.com']);

        try {
            $this->client->query(CreateIndex([
                'name' => 'users_by_email',
                'source' => Collection('users'),
                'terms' => [['field' => ['data', 'email']]],
                'unique' => true,
            ]));
            self::fail('The index should not have been created.');
        } catch (NotUnique) {
        }

        // The definition was rolled back with the index.
        $this->expectException(NotFound::class);
        $this->client->query(MatchIndex(Index('users_by_email'), 'ada@example.com'));
    }

    public function testIndexesAreCreatedOnce(): void
    {
        $params = ['name' => 'users_by_email', 'source' => Collection('users'), 'terms' => [['field' => ['data', 'email']]]];
        $this->client->query(CreateIndex($params));

        $this->expectException(AlreadyExists::class);
        $this->client->query(CreateIndex($params));
    }

    public function testUnknownIndex(): void
    {
        $this->expectException(NotFound::class);

        $this->client->query(MatchIndex(Index('nope')));
    }

    /**
     * @param array<string, mixed> ...$users
     * @return list<array{ref: RefValue, ts: int, data: array<string, mixed>}>
     */
    private function createUsers(array ...$users): array
    {
        return $this->client->query(array_map(static fn (array $data) => Create(Collection('users'), ['data' => $data]), array_values($users)));
    }
}
