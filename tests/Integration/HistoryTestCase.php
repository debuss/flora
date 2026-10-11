<?php declare(strict_types=1);

namespace Flora\Tests\Integration;

use Flora\Exception\{InvalidArgument, NotFound};
use Flora\Value\Ref as RefValue;

use function Flora\{
    At,
    Collection,
    Create,
    CreateCollection,
    CreateIndex,
    Delete,
    Difference,
    Documents,
    Equals,
    Events,
    Exists,
    Filter,
    Get,
    Index,
    Insert,
    Intersection,
    Join,
    Lambda,
    MatchIndex,
    Paginate,
    Ref,
    Remove,
    Replace,
    Select,
    Size,
    Union,
    Update,
    Variable,
};

abstract class HistoryTestCase extends IntegrationTestCase
{

    protected function setUp(): void
    {
        parent::setUp();

        $this->client->query(CreateCollection(['name' => 'users']));
        $this->client->query(CreateIndex(['name' => 'users_by_role', 'source' => Collection('users'), 'terms' => [['field' => ['data', 'role']]]]));
        $this->client->query(CreateIndex(['name' => 'users_by_city', 'source' => Collection('users'), 'terms' => [['field' => ['data', 'city']]]]));
    }

    public function testEventsOfADocument(): void
    {
        $created = $this->client->query(Create(Collection('users'), ['data' => ['name' => 'Ada', 'role' => 'guest']]));
        $ref = $created['ref'];
        $updated = $this->client->query(Update($ref, ['data' => ['role' => 'admin']]));
        $replaced = $this->client->query(Replace($ref, ['data' => ['name' => 'Ada Lovelace']]));
        $this->client->query(Delete($ref));

        $events = $this->collectAll(Events($ref), 1);

        self::assertSame(['create', 'update', 'update', 'delete'], array_column($events, 'action'));
        self::assertSame([$created['ts'], $updated['ts'], $replaced['ts']], array_slice(array_column($events, 'ts'), 0, 3));
        self::assertGreaterThan($replaced['ts'], $events[3]['ts']);
        self::assertEquals(['name' => 'Ada', 'role' => 'admin'], $events[1]['data']);
        self::assertEquals(['name' => 'Ada Lovelace'], $events[3]['data']);
        self::assertEquals($ref, $events[0]['document']);
        self::assertSame(4, $this->client->query(Size(Events($ref))));
    }

    public function testTheWritesOfAQueryAreOneEvent(): void
    {
        [$ada] = $this->client->query([
            Create(Ref(Collection('users'), '1'), ['data' => ['name' => 'Ada']]),
            Update(Ref(Collection('users'), '1'), ['data' => ['role' => 'admin']]),
        ]);
        $this->client->query([
            Update($ada['ref'], ['data' => ['city' => 'Paris']]),
            Update($ada['ref'], ['data' => ['city' => 'London']]),
        ]);

        // Created and updated: created, as it was at the end of the query; updated twice: updated once.
        $events = $this->client->query(Paginate(Events($ada['ref'])))->data;
        self::assertSame(['create', 'update'], array_column($events, 'action'));
        self::assertEquals(['name' => 'Ada', 'role' => 'admin'], $events[0]['data']);
        self::assertEquals(['name' => 'Ada', 'role' => 'admin', 'city' => 'London'], $events[1]['data']);

        // Created and deleted: it never was.
        $this->client->query([Create(Ref(Collection('users'), '2')), Delete(Ref(Collection('users'), '2'))]);
        self::assertSame(0, $this->client->query(Size(Events(Ref(Collection('users'), '2')))));
    }

    public function testReadingADocumentInThePast(): void
    {
        $created = $this->client->query(Create(Collection('users'), ['data' => ['name' => 'Ada']]));
        $ref = $created['ref'];
        $updated = $this->client->query(Update($ref, ['data' => ['name' => 'Ada Lovelace']]));
        $this->client->query(Delete($ref));

        self::assertSame('Ada', $this->client->query(At($created['ts'], Get($ref)))['data']['name']);
        self::assertSame('Ada', $this->client->query(Get($ref, $updated['ts'] - 1))['data']['name']);
        self::assertSame($created['ts'], $this->client->query(Get($ref, $updated['ts'] - 1))['ts']);
        self::assertSame('Ada Lovelace', $this->client->query(Get($ref, $updated['ts']))['data']['name']);
        self::assertTrue($this->client->query(At($updated['ts'], Exists($ref))));
        self::assertFalse($this->client->query(Exists($ref)));
        self::assertFalse($this->client->query(At($created['ts'] - 1, Exists($ref))));

        $this->expectException(NotFound::class);
        $this->client->query(At($created['ts'] - 1, Get($ref)));
    }

    public function testReadingSetsInThePast(): void
    {
        $users = $this->client->query([
            Create(Collection('users'), ['data' => ['name' => 'Ada', 'role' => 'admin', 'city' => 'Paris']]),
            Create(Collection('users'), ['data' => ['name' => 'Grace', 'role' => 'guest', 'city' => 'Paris']]),
        ]);
        [$ada, $grace] = array_map(static fn (array $user): RefValue => $user['ref'], $users);
        $then = $users[0]['ts'];

        $this->client->query([Update($grace, ['data' => ['role' => 'admin']]), Delete($ada)]);
        $this->client->query(Create(Collection('users'), ['data' => ['name' => 'Alan', 'role' => 'admin', 'city' => 'Paris']]));

        $admins = MatchIndex(Index('users_by_role'), 'admin');
        self::assertEquals([$ada], $this->client->query(At($then, Paginate($admins)))->data);
        self::assertSame(1, $this->client->query(At($then, Size($admins))));
        self::assertSame(2, $this->client->query(At($then, Size(Documents(Collection('users'))))));
        self::assertEquals([$ada], $this->client->query(At($then, Paginate(Intersection($admins, MatchIndex(Index('users_by_city'), 'Paris')))))->data);
        self::assertSame('Ada', $this->client->query(At($then, Get($admins)))['data']['name']);

        // Now, Grace is an admin, Ada is gone, Alan came.
        self::assertSame(2, $this->client->query(Size($admins)));
    }

    public function testJoinsInThePast(): void
    {
        $this->client->query(CreateCollection(['name' => 'posts']));
        $this->client->query(CreateIndex(['name' => 'posts_by_author', 'source' => Collection('posts'), 'terms' => [['field' => ['data', 'author']]]]));
        $ada = $this->client->query(Create(Collection('users'), ['data' => ['role' => 'admin']]));
        $post = $this->client->query(Create(Collection('posts'), ['data' => ['author' => $ada['ref']]]));
        $this->client->query(Update($ada['ref'], ['data' => ['role' => 'guest']]));

        $posts = Join(MatchIndex(Index('users_by_role'), 'admin'), Lambda('ref', MatchIndex(Index('posts_by_author'), Variable('ref'))));
        self::assertEquals([$post['ref']], $this->client->query(At($post['ts'], Paginate($posts)))->data);
        self::assertSame([], $this->client->query(Paginate($posts))->data);
    }

    public function testEventsUntilATime(): void
    {
        $created = $this->client->query(Create(Collection('users'), ['data' => ['name' => 'Ada']]));
        $this->client->query(Update($created['ref'], ['data' => ['name' => 'Ada Lovelace']]));

        self::assertSame(['create'], array_column($this->client->query(At($created['ts'], Paginate(Events($created['ref']))))->data, 'action'));
    }

    public function testEventsOfASet(): void
    {
        $users = $this->client->query([
            Create(Collection('users'), ['data' => ['name' => 'Ada', 'role' => 'admin']]),
            Create(Collection('users'), ['data' => ['name' => 'Grace', 'role' => 'guest']]),
        ]);
        [$ada, $grace] = array_map(static fn (array $user): RefValue => $user['ref'], $users);
        $promoted = $this->client->query(Update($grace, ['data' => ['role' => 'admin']]));
        $demoted = $this->client->query(Update($ada, ['data' => ['role' => 'guest']]));
        $this->client->query(Delete($grace));

        $admins = MatchIndex(Index('users_by_role'), 'admin');
        $events = $this->collectAll(Events($admins), 1);

        self::assertSame(['add', 'add', 'remove', 'remove'], array_column($events, 'action'));
        self::assertEquals([$ada, $grace, $ada, $grace], array_column($events, 'document'));
        self::assertSame([$users[0]['ts'], $promoted['ts'], $demoted['ts']], array_slice(array_column($events, 'ts'), 0, 3));
        self::assertSame([], $events[0]['data']);
        self::assertSame(4, $this->client->query(Size(Events($admins))));

        $added = Filter(Events($admins), Lambda('event', Equals(Select('action', Variable('event')), 'add')));
        self::assertSame(2, $this->client->query(Size($added)));

        // Until the promotion of Grace.
        self::assertSame(['add', 'add'], array_column($this->client->query(At($promoted['ts'], Paginate(Events($admins))))->data, 'action'));
    }

    public function testAValueChangeRemovesAnEntryAndAddsAnother(): void
    {
        $this->client->query(CreateIndex([
            'name' => 'ages_by_role',
            'source' => Collection('users'),
            'terms' => [['field' => ['data', 'role']]],
            'values' => [['field' => ['data', 'age']]],
        ]));
        $ada = $this->client->query(Create(Collection('users'), ['data' => ['role' => 'admin', 'age' => 36]]));
        $this->client->query(Update($ada['ref'], ['data' => ['age' => 37]]));

        $events = $this->client->query(Paginate(Events(MatchIndex(Index('ages_by_role'), 'admin'))))->data;

        self::assertSame([['add', [36]], ['remove', [36]], ['add', [37]]], array_map(static fn (array $event): array => [$event['action'], $event['data']], $events));
        self::assertSame($events[1]['ts'], $events[2]['ts']);
    }

    public function testEventsOfCombinedSets(): void
    {
        $ada = $this->client->query(Create(Collection('users'), ['data' => ['role' => 'admin', 'city' => 'Paris']]));
        $this->client->query(Update($ada['ref'], ['data' => ['role' => 'guest']]));
        $this->client->query(Update($ada['ref'], ['data' => ['city' => 'London']]));
        $this->client->query(Delete($ada['ref']));

        $admins = MatchIndex(Index('users_by_role'), 'admin');
        $parisians = MatchIndex(Index('users_by_city'), 'Paris');
        $actions = fn (mixed $set): array => array_column($this->client->query(Paginate(Events($set)))->data, 'action');

        // An admin, then a Parisian, then neither: in the union from the first to the third version.
        self::assertSame(['add', 'remove'], $actions(Union($admins, $parisians)));
        self::assertSame(['add', 'remove'], $actions(Intersection($admins, $parisians)));
        self::assertSame(['add', 'remove'], $actions(Difference($parisians, $admins)));
        self::assertSame(['add', 'remove'], $actions(Documents(Collection('users'))));
    }

    public function testEventsOfASetWithAJoin(): void
    {
        $this->client->query(CreateCollection(['name' => 'posts']));
        $this->client->query(CreateIndex(['name' => 'posts_by_author', 'source' => Collection('posts'), 'terms' => [['field' => ['data', 'author']]]]));
        $this->client->query(CreateIndex([
            'name' => 'titles_by_author',
            'source' => Collection('posts'),
            'terms' => [['field' => ['data', 'author']]],
            'values' => [['field' => ['data', 'title']]],
        ]));

        $ada = $this->client->query(Create(Collection('users'), ['data' => ['role' => 'admin']]))['ref'];
        $post = $this->client->query(Create(Collection('posts'), ['data' => ['author' => $ada, 'title' => 'Notes']]));
        $demoted = $this->client->query(Update($ada, ['data' => ['role' => 'guest']]));
        $promoted = $this->client->query(Update($ada, ['data' => ['role' => 'admin']]));
        $this->client->query(Delete($post['ref']));

        // The post leaves the set when its author is no longer an admin, without changing itself.
        $posts = Join(MatchIndex(Index('users_by_role'), 'admin'), Index('posts_by_author'));
        $events = $this->collectAll(Events($posts), 1);
        self::assertSame(['add', 'remove', 'add', 'remove'], array_column($events, 'action'));
        self::assertSame([$post['ts'], $demoted['ts'], $promoted['ts']], array_slice(array_column($events, 'ts'), 0, 3));
        self::assertEquals(array_fill(0, 4, $post['ref']), array_column($events, 'document'));

        // With values, and until a time.
        $titles = Join(MatchIndex(Index('users_by_role'), 'admin'), Index('titles_by_author'));
        $until = $this->client->query(At($demoted['ts'], Paginate(Events($titles))))->data;
        self::assertSame([['add', ['Notes']], ['remove', ['Notes']]], array_map(static fn (array $event): array => [$event['action'], $event['data']], $until));
    }

    public function testInsertingAnEventInThePast(): void
    {
        $created = $this->client->query(Create(Collection('users'), ['data' => ['name' => 'Ada']]));
        $ref = $created['ref'];
        $updated = $this->client->query(Update($ref, ['data' => ['name' => 'Ada Lovelace']]));
        $between = $created['ts'] + 1;

        $event = $this->client->query(Insert($ref, $between, 'update', ['data' => ['name' => 'Ada Byron']]));

        self::assertEquals(['ts' => $between, 'action' => 'update', 'document' => $ref, 'data' => ['name' => 'Ada Byron']], $event);
        self::assertSame(['create', 'update', 'update'], array_column($this->client->query(Paginate(Events($ref)))->data, 'action'));
        self::assertSame('Ada Byron', $this->client->query(Get($ref, $between))['data']['name']);

        // The last event is still the same: the document did not change.
        self::assertSame('Ada Lovelace', $this->client->query(Get($ref))['data']['name']);
        self::assertSame($updated['ts'], $this->client->query(Get($ref))['ts']);
    }

    public function testInsertingTheLastEventChangesTheDocument(): void
    {
        $created = $this->client->query(Create(Collection('users'), ['data' => ['name' => 'Ada', 'role' => 'guest']]));
        $ref = $created['ref'];
        $later = $created['ts'] + 1_000_000;

        $this->client->query(Insert($ref, $later, 'update', ['data' => ['name' => 'Ada', 'role' => 'admin']]));

        self::assertSame($later, $this->client->query(Get($ref))['ts']);
        self::assertEquals([$ref], $this->client->query(Paginate(MatchIndex(Index('users_by_role'), 'admin')))->data);
        self::assertSame([], $this->client->query(Paginate(MatchIndex(Index('users_by_role'), 'guest')))->data);

        // A later deletion deletes it.
        $this->client->query(Insert($ref, $later + 1, 'delete'));
        self::assertFalse($this->client->query(Exists($ref)));
        self::assertSame(0, $this->client->query(Size(MatchIndex(Index('users_by_role'), 'admin'))));
    }

    public function testInsertingReplacesTheEventAtTheSameTime(): void
    {
        $created = $this->client->query(Create(Collection('users'), ['data' => ['name' => 'Ada']]));

        $this->client->query(Insert($created['ref'], $created['ts'], 'create', ['data' => ['name' => 'Ada Lovelace']]));

        self::assertSame(1, $this->client->query(Size(Events($created['ref']))));
        self::assertSame('Ada Lovelace', $this->client->query(Get($created['ref']))['data']['name']);
    }

    public function testInsertingTheHistoryOfANewDocument(): void
    {
        $ref = Ref(Collection('users'), '42');

        $this->client->query(Insert($ref, 1_000, 'create', ['data' => ['role' => 'admin']]));

        self::assertSame(1_000, $this->client->query(Get($ref))['ts']);
        self::assertSame(1, $this->client->query(Size(MatchIndex(Index('users_by_role'), 'admin'))));
    }

    public function testRemovingTheLastEventBringsBackThePreviousVersion(): void
    {
        $created = $this->client->query(Create(Collection('users'), ['data' => ['name' => 'Ada', 'role' => 'guest']]));
        $ref = $created['ref'];
        $updated = $this->client->query(Update($ref, ['data' => ['role' => 'admin']]));

        self::assertNull($this->client->query(Remove($ref, $updated['ts'], 'update')));

        self::assertEquals(['name' => 'Ada', 'role' => 'guest'], $this->client->query(Get($ref))['data']);
        self::assertSame($created['ts'], $this->client->query(Get($ref))['ts']);
        self::assertSame(0, $this->client->query(Size(MatchIndex(Index('users_by_role'), 'admin'))));

        // Without its creation, the document never was.
        $this->client->query(Remove($ref, $created['ts'], 'create'));
        self::assertFalse($this->client->query(Exists($ref)));
        self::assertSame(0, $this->client->query(Size(Events($ref))));
    }

    public function testRemovingADeletionBringsTheDocumentBack(): void
    {
        $created = $this->client->query(Create(Collection('users'), ['data' => ['name' => 'Ada', 'role' => 'admin']]));
        $this->client->query(Delete($created['ref']));
        $deletion = $this->client->query(Paginate(Events($created['ref'])))->data[1];

        $this->client->query(Remove($created['ref'], $deletion['ts'], 'delete'));

        self::assertSame('Ada', $this->client->query(Get($created['ref']))['data']['name']);
        self::assertSame(1, $this->client->query(Size(MatchIndex(Index('users_by_role'), 'admin'))));
    }

    public function testRewritingTheHistoryOfIndexesUnrollingFields(): void
    {
        $this->client->query(CreateIndex(['name' => 'users_by_tag', 'source' => Collection('users'), 'terms' => [['field' => ['data', 'tags'], 'unroll' => true]]]));
        $created = $this->client->query(Create(Collection('users'), ['data' => ['tags' => ['php']]]));

        $this->client->query(Insert($created['ref'], $created['ts'] + 1, 'update', ['data' => ['tags' => ['go', 'sql']]]));

        self::assertSame(0, $this->client->query(Size(MatchIndex(Index('users_by_tag'), 'php'))));
        self::assertSame(1, $this->client->query(Size(MatchIndex(Index('users_by_tag'), 'sql'))));
    }

    public function testRemovingAnEventThatIsNotThere(): void
    {
        $created = $this->client->query(Create(Collection('users'), ['data' => ['name' => 'Ada']]));

        $this->expectException(NotFound::class);
        $this->expectExceptionMessage('There is no update event');

        // The event at that time is the creation.
        $this->client->query(Remove($created['ref'], $created['ts'], 'update'));
    }

    public function testInsertTakesAnAction(): void
    {
        $this->expectException(InvalidArgument::class);

        $this->client->query(Insert(Ref(Collection('users'), '1'), 1_000, 'rename'));
    }

    public function testRewritingHistoryIsAWrite(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('At() only reads the past');

        $this->client->query(At(1, Remove(Ref(Collection('users'), '1'), 1, 'create')));
    }

    public function testAtOnlyReads(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('At() only reads the past');

        $this->client->query(At(1, Create(Collection('users'))));
    }

    public function testReadingUnrolledIndexesInThePast(): void
    {
        $this->client->query(CreateIndex(['name' => 'users_by_tag', 'source' => Collection('users'), 'terms' => [['field' => ['data', 'tags'], 'unroll' => true]]]));
        $this->client->query(CreateIndex(['name' => 'tags', 'source' => Collection('users'), 'values' => [['field' => ['data', 'tags'], 'unroll' => true]]]));

        $ada = $this->client->query(Create(Collection('users'), ['data' => ['name' => 'Ada', 'tags' => ['php', 'sql', 'php']]]));
        $retagged = $this->client->query(Update($ada['ref'], ['data' => ['tags' => ['go']]]));
        $grace = $this->client->query(Create(Collection('users'), ['data' => ['name' => 'Grace', 'tags' => 'php']]));
        $this->client->query(Delete($ada['ref']));
        $deleted = $this->client->query(Paginate(Events($ada['ref'])))->data[2]['ts'];

        $tagged = static fn (string $tag): mixed => MatchIndex(Index('users_by_tag'), $tag);
        self::assertEquals([$ada['ref']], $this->client->query(At($ada['ts'], Paginate($tagged('php'))))->data);
        self::assertSame([], $this->client->query(At($retagged['ts'], Paginate($tagged('php'))))->data);
        self::assertEquals([$grace['ref']], $this->client->query(At($grace['ts'], Paginate($tagged('php'))))->data);
        self::assertSame(1, $this->client->query(At($retagged['ts'], Size($tagged('go')))));
        self::assertEquals([$ada['ref']], $this->client->query(At($ada['ts'], Paginate(Intersection($tagged('php'), $tagged('sql')))))->data);

        // An entry per tag, the repeated one once, and none for a deleted document.
        $tags = MatchIndex(Index('tags'));
        self::assertSame(['php', 'sql'], $this->client->query(At($ada['ts'], Paginate($tags)))->data);
        self::assertSame(['go', 'php'], $this->client->query(At($grace['ts'], Paginate($tags)))->data);
        self::assertSame(['php'], $this->client->query(At($deleted, Paginate($tags)))->data);
    }

    public function testJoiningUnrolledRefsInThePast(): void
    {
        $this->client->query(CreateCollection(['name' => 'posts']));
        $this->client->query(CreateIndex(['name' => 'posts_by_author', 'source' => Collection('posts'), 'terms' => [['field' => ['data', 'authors'], 'unroll' => true]]]));

        $ada = $this->client->query(Create(Collection('users'), ['data' => ['role' => 'admin']]))['ref'];
        $grace = $this->client->query(Create(Collection('users'), ['data' => ['role' => 'guest']]))['ref'];
        $post = $this->client->query(Create(Collection('posts'), ['data' => ['authors' => [$grace, $ada]]]));
        $rewritten = $this->client->query(Update($post['ref'], ['data' => ['authors' => [$grace]]]));

        $byAdmins = Join(MatchIndex(Index('users_by_role'), 'admin'), Index('posts_by_author'));
        self::assertEquals([$post['ref']], $this->client->query(At($post['ts'], Paginate($byAdmins)))->data);
        self::assertSame([], $this->client->query(At($rewritten['ts'], Paginate($byAdmins)))->data);
    }

    public function testEventsOfUnrolledIndexes(): void
    {
        $this->client->query(CreateIndex(['name' => 'users_by_tag', 'source' => Collection('users'), 'terms' => [['field' => ['data', 'tags'], 'unroll' => true]]]));

        $ada = $this->client->query(Create(Collection('users'), ['data' => ['tags' => ['php', 'sql']]]));
        $retagged = $this->client->query(Update($ada['ref'], ['data' => ['tags' => ['go']]]));
        $grace = $this->client->query(Create(Collection('users'), ['data' => ['tags' => 'php']]));

        $events = $this->collectAll(Events(MatchIndex(Index('users_by_tag'), 'php')), 1);

        self::assertSame([['add', $ada['ts']], ['remove', $retagged['ts']], ['add', $grace['ts']]], array_map(static fn (array $event): array => [$event['action'], $event['ts']], $events));
    }
}
