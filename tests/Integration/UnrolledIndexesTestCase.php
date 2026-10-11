<?php declare(strict_types=1);

namespace Flora\Tests\Integration;

use Flora\Exception\NotUnique;
use Flora\Value\Ref as RefValue;

use function Flora\{
    Collection,
    Create,
    CreateCollection,
    CreateIndex,
    Delete,
    Difference,
    Documents,
    Index,
    Intersection,
    Join,
    Lambda,
    MatchIndex,
    Replace,
    Union,
    Update,
    Variable,
};

abstract class UnrolledIndexesTestCase extends IntegrationTestCase
{

    /** @var array<string, RefValue> */
    private array $refs = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['users', 'posts', 'comments'] as $collection) {
            $this->client->query(CreateCollection(['name' => $collection]));
        }

        $this->index('users_by_role', 'users', [['role']], []);
        $this->index('posts_by_name', 'posts', [['name']], []);
        $this->index('comments_by_post', 'comments', [['post']], []);

        // Each collection is filled in one query, so that refs sort in this order.
        $this->create('users', ['Ada' => ['role' => 'admin'], 'Grace' => ['role' => 'admin'], 'Alan' => ['role' => 'editor']]);
        $this->create('posts', [
            'Notes' => ['tags' => ['php', 'sql'], 'authors' => [$this->refs['Ada']]],
            'Compilers' => ['tags' => ['php'], 'authors' => [$this->refs['Grace'], $this->refs['Ada']]],
            'Machines' => ['tags' => 'sql', 'authors' => [$this->refs['Grace']]],
            'Engines' => ['tags' => [], 'authors' => [$this->refs['Alan']]],
            'Legacy' => ['tags' => ['php', 'legacy', 'php']],
            'Draft' => [],
        ]);
        $this->create('comments', ['On notes' => ['post' => $this->refs['Notes']], 'On machines' => ['post' => $this->refs['Machines']]]);

        // Created after the posts: the index is filled with the documents already there.
        $this->index('posts_by_tag', 'posts', [['tags', true]], []);
        $this->index('tags', 'posts', [], [['tags', true]]);
        $this->index('tags_by_tag', 'posts', [['tags', true]], [['tags', true]]);
        $this->index('posts_by_author', 'posts', [['authors', true]], []);
    }

    public function testMatchingAnItem(): void
    {
        // A list matches by any of its items, a single value by itself; empty lists and missing fields match nothing.
        self::assertEquals($this->refs('Notes', 'Compilers', 'Legacy'), $this->collectAll(MatchIndex(Index('posts_by_tag'), 'php'), 2));
        self::assertEquals($this->refs('Notes', 'Machines'), $this->collectAll(MatchIndex(Index('posts_by_tag'), 'sql'), 1));
    }

    public function testAnEntryPerItem(): void
    {
        // One entry per tag of each post, sorted by tag then ref: Legacy's repeated tag counts once.
        self::assertSame(['legacy', 'php', 'php', 'php', 'sql', 'sql'], $this->collectAll(MatchIndex(Index('tags')), 4));
    }

    public function testUnrolledTermsAndValues(): void
    {
        // The tags of the posts tagged php.
        self::assertSame(['legacy', 'php', 'php', 'php', 'sql'], $this->collectAll(MatchIndex(Index('tags_by_tag'), 'php'), 2));
    }

    public function testWritesKeepEntriesUpToDate(): void
    {
        $this->client->query(Update($this->refs['Draft'], ['data' => ['tags' => ['php']]]));
        $this->client->query(Replace($this->refs['Notes'], ['data' => ['name' => 'Notes', 'tags' => ['sql']]]));
        $this->client->query(Delete($this->refs['Legacy']));
        $created = $this->client->query(Create(Collection('posts'), ['data' => ['name' => 'New', 'tags' => ['php', 'go']]]));

        self::assertEquals([...$this->refs('Compilers', 'Draft'), $created['ref']], $this->collectAll(MatchIndex(Index('posts_by_tag'), 'php'), 2));
        self::assertSame(['go', 'php', 'php', 'php', 'sql', 'sql'], $this->collectAll(MatchIndex(Index('tags')), 4));
    }

    public function testCombiningSets(): void
    {
        $php = MatchIndex(Index('posts_by_tag'), 'php');
        $sql = MatchIndex(Index('posts_by_tag'), 'sql');

        // Both tags are entries of the same index, on different rows: the intersection still finds Notes.
        self::assertEquals($this->refs('Notes'), $this->collectAll(Intersection($php, $sql), 1));
        self::assertEquals($this->refs('Notes', 'Compilers', 'Machines', 'Legacy'), $this->collectAll(Union($php, $sql), 3));
        self::assertEquals($this->refs('Compilers', 'Legacy'), $this->collectAll(Difference($php, $sql), 1));
        self::assertEquals($this->refs('Engines', 'Draft'), $this->collectAll(Difference(Documents(Collection('posts')), $php, $sql), 1));

        // With an index computed from the documents.
        self::assertEquals($this->refs('Notes'), $this->collectAll(Intersection(MatchIndex(Index('posts_by_name'), 'Notes'), $php), 1));
        self::assertEquals($this->refs('Notes'), $this->collectAll(Intersection($php, MatchIndex(Index('posts_by_name'), 'Notes')), 1));
    }

    public function testJoins(): void
    {
        $admins = MatchIndex(Index('users_by_role'), 'admin');

        // Compilers has two admin authors, and comes once.
        self::assertEquals($this->refs('Notes', 'Compilers', 'Machines'), $this->collectAll(Join($admins, Index('posts_by_author')), 2));
        self::assertEquals($this->refs('Notes', 'Compilers', 'Machines'), $this->collectAll(Join($admins, Lambda('ref', MatchIndex(Index('posts_by_author'), Variable('ref')))), 2));

        // An unrolled source: the comments on the posts tagged sql.
        self::assertEquals($this->refs('On notes', 'On machines'), $this->collectAll(Join(MatchIndex(Index('posts_by_tag'), 'sql'), Index('comments_by_post')), 1));
    }

    public function testUniqueItems(): void
    {
        $this->index('posts_by_unique_tag', 'posts', [], [['labels', true]], unique: true);

        // A document can repeat its own items.
        $this->client->query(Create(Collection('posts'), ['data' => ['labels' => ['a', 'b', 'a']]]));

        $this->expectException(NotUnique::class);
        $this->client->query(Create(Collection('posts'), ['data' => ['labels' => ['c', 'b']]]));
    }

    /**
     * @param list<array{0: string, 1?: bool}> $terms the path in data of each term, and whether it is unrolled
     * @param list<array{0: string, 1?: bool}> $values
     */
    private function index(string $name, string $collection, array $terms, array $values, bool $unique = false): void
    {
        $field = static fn (array $field): array => ['field' => ['data', $field[0]], 'unroll' => $field[1] ?? false];

        $this->client->query(CreateIndex([
            'name' => $name,
            'source' => Collection($collection),
            'terms' => array_map($field, $terms),
            'values' => array_map($field, $values),
            'unique' => $unique,
        ]));
    }

    /**
     * @param array<string, array<string, mixed>> $documents the data of each document, by name
     */
    private function create(string $collection, array $documents): void
    {
        $created = $this->client->query(array_map(
            static fn (string $name, array $data): mixed => Create(Collection($collection), ['data' => ['name' => $name, ...$data]]),
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
