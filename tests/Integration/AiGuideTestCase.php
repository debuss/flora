<?php declare(strict_types=1);

namespace Flora\Tests\Integration;

use DateTimeImmutable;
use Throwable;
use Flora\Exception\{Aborted, InvalidArgument, NotFound, NotUnique};
use Flora\Value\{Date, Page, Ref as RefValue};

use function Flora\{
    Abort,
    Between,
    Call,
    Collection,
    Create,
    CreateCollection,
    CreateFunction,
    CreateIndex,
    Delete,
    Each,
    Events,
    Exists,
    Filter,
    FunctionRef,
    Get,
    GT,
    IfElse,
    Index,
    Join,
    Lambda,
    Let,
    Map,
    MatchIndex,
    Now,
    Paginate,
    Query,
    Ref,
    Select,
    Size,
    TimeAdd,
    ToDate,
    ToMicros,
    Union,
    Update,
    Variable,
};

/**
 * AI.md shows assistants how to write queries: its examples run as they are written, and do what it says.
 */
abstract class AiGuideTestCase extends IntegrationTestCase
{

    /**
     * Runs the PHP blocks of the guide in order, sharing their variables, as a reader would chain them. The block
     * opening a connection is left out: the test has its client. The guide calls the ref of a document $ref, and uses
     * $email and $data as what an application would give.
     */
    public function testTheExamplesRunAsWritten(): void
    {
        preg_match_all('/```php\n(.*?)```/s', (string) file_get_contents(__DIR__ . '/../../AI.md'), $blocks);
        $variables = ['client' => $this->client, 'email' => 'alan@example.com', 'data' => ['name' => 'Alan', 'role' => 'guest']];

        $ran = 0;
        foreach ($blocks[1] as $i => $code) {
            if (str_contains($code, 'new PDO(')) {
                continue;
            }

            if (isset($variables['ada']) && is_array($variables['ada'])) {
                $variables['ref'] ??= $variables['ada']['ref'];
            }

            try {
                $variables = self::evaluate($code, $variables);
            } catch (Throwable $e) {
                self::fail(sprintf("Example %d of AI.md fails: %s: %s\n\n%s", $i + 1, $e::class, $e->getMessage(), $code));
            }
            ++$ran;
        }

        self::assertGreaterThanOrEqual(7, $ran);
    }

    public function testDocuments(): void
    {
        $this->client->query(CreateCollection(['name' => 'users']));
        $ada = $this->client->query(Create(Collection('users'), ['data' => ['name' => 'Ada', 'nick' => 'A', 'born' => ToDate('1815-12-10')]]));

        self::assertInstanceOf(RefValue::class, $ada['ref']);
        self::assertMatchesRegularExpression('/^\d+$/', $ada['ref']->id);
        self::assertIsInt($ada['ts']);
        self::assertSame(['ref', 'ts', 'data'], array_keys($ada));

        // Update merges and null removes; data keeps refs and dates.
        $this->client->query(Update($ada['ref'], ['data' => ['nick' => null, 'friend' => $ada['ref']]]));
        $data = $this->client->query(Get($ada['ref']))['data'];
        self::assertArrayNotHasKey('nick', $data);
        self::assertEquals($ada['ref'], $data['friend']);
        self::assertEquals(new Date('1815-12-10'), $data['born']);

        $this->expectException(NotFound::class);
        $this->client->query(Get(Ref(Collection('users'), '1')));
    }

    public function testPagesAndEntries(): void
    {
        $admins = $this->users();

        // Values age (reverse) and ref: each item is [age, ref], the oldest first.
        $page = $this->client->query(Paginate($admins, ['size' => 2]));
        self::assertInstanceOf(Page::class, $page);
        self::assertSame([85, 72], array_column($page->data, 0));
        self::assertInstanceOf(RefValue::class, $page->data[0][1]);

        $next = $this->client->query(Paginate($admins, ['size' => 2, 'after' => $page->after]));
        self::assertSame([36], array_column($next->data, 0));
        self::assertNull($next->after);
        self::assertSame([85, 72], array_column($this->client->query(Paginate($admins, ['size' => 2, 'before' => $next->before]))->data, 0));

        // Without values, an entry is the ref; Get() of a set is its first document.
        self::assertInstanceOf(RefValue::class, $this->client->query(Paginate(MatchIndex(Index('users_by_email'), 'ada@example.com')))->data[0]);
        self::assertSame('Ada', $this->client->query(Get(MatchIndex(Index('users_by_email'), 'ada@example.com')))['data']['name']);

        // Bounds are included, prefixes of the values, in the order of the index (reverse here), [] is open.
        self::assertSame([72, 36], array_column($this->client->query(Paginate(Between($admins, [80], [])))->data, 0));
        self::assertSame([85], array_column($this->client->query(Paginate(Between($admins, [], [85])))->data, 0));

        self::assertSame([3, true, false], $this->client->query([Size($admins), Exists($admins), Exists(MatchIndex(Index('users_by_role'), 'guest'))]));
    }

    public function testLambdasOnPages(): void
    {
        $admins = $this->users();

        $documents = $this->client->query(Map(Paginate($admins), Lambda(['age', 'ref'], Get(Variable('ref')))));
        self::assertSame(['Grace', 'Edsger', 'Ada'], array_map(static fn (array $document): string => $document['data']['name'], $documents->data));
        self::assertSame('anonymous', $this->client->query(Let(['user' => Get($admins)], Select(['data', 'nickname'], Variable('user'), 'anonymous'))));
        self::assertSame(['Grace'], $this->client->query(Map(Paginate(Filter($admins, Lambda(['age', 'ref'], GT(Variable('age'), 80)))), Lambda(['age', 'ref'], Select(['data', 'name'], Get(Variable('ref'))))))->data);

        // Map takes pages and lists, not sets; filtered sets are not combined.
        try {
            $this->client->query(Map($admins, Lambda('x', Variable('x'))));
            self::fail('Map() of a set.');
        } catch (InvalidArgument) {
        }

        $this->expectException(InvalidArgument::class);
        $this->client->query(Paginate(Union(Filter($admins, Lambda(['age', 'ref'], true)), $admins)));
    }

    public function testRecipes(): void
    {
        $this->users();
        $this->client->query(CreateCollection(['name' => 'posts']));
        $this->client->query(CreateIndex(['name' => 'posts_by_author', 'source' => Collection('posts'), 'terms' => [['field' => ['data', 'author']]]]));
        $this->client->query(CreateIndex(['name' => 'users_by_city', 'source' => Collection('users'), 'terms' => [['field' => ['data', 'city']]]]));

        // Create or update by a unique field.
        $upsert = fn (string $email, array $data): mixed => $this->client->query(IfElse(
            Exists(MatchIndex(Index('users_by_email'), $email)),
            Update(Select('ref', Get(MatchIndex(Index('users_by_email'), $email))), ['data' => $data]),
            Create(Collection('users'), ['data' => [...$data, 'email' => $email]]),
        ));
        $upsert('alan@example.com', ['name' => 'Alan', 'role' => 'guest', 'age' => 41, 'city' => 'Paris']);
        $upsert('alan@example.com', ['name' => 'Alan Turing']);
        self::assertSame(1, $this->client->query(Size(MatchIndex(Index('users_by_email'), 'alan@example.com'))));
        self::assertSame('Alan Turing', $this->client->query(Get(MatchIndex(Index('users_by_email'), 'alan@example.com')))['data']['name']);

        // The posts of the users of a city, joined on their refs.
        $ada = $this->client->query(Get(MatchIndex(Index('users_by_email'), 'ada@example.com')));
        $this->client->query(Create(Collection('posts'), ['data' => ['title' => 'Notes', 'author' => $ada['ref']]]));
        self::assertCount(1, $this->client->query(Paginate(Join(MatchIndex(Index('users_by_city'), 'Paris'), Index('posts_by_author'))))->data);

        // Deleting page by page.
        foreach (range(1, 5) as $age) {
            $this->client->query(Create(Collection('users'), ['data' => ['role' => 'guest', 'age' => $age]]));
        }
        $guests = MatchIndex(Index('users_by_role'), 'guest');
        $queries = 0;
        while ($this->client->query(Size($guests)) > 0) {
            $this->client->query(Each(Paginate($guests, ['size' => 2]), Lambda(['age', 'ref'], Delete(Variable('ref')))));
            ++$queries;
        }
        self::assertSame(3, $queries);

        // A unique index refuses a second document, Abort() rolls the query back.
        try {
            $this->client->query(Create(Collection('users'), ['data' => ['email' => 'ada@example.com']]));
            self::fail('The email is taken.');
        } catch (NotUnique) {
        }
        try {
            $this->client->query([Delete($ada['ref']), Abort('No.')]);
            self::fail('The query aborts.');
        } catch (Aborted) {
        }
        self::assertTrue($this->client->query(Exists($ada['ref'])));
    }

    public function testTimeHistoryAndFunctions(): void
    {
        $this->users();
        $ada = $this->client->query(Get(MatchIndex(Index('users_by_email'), 'ada@example.com')));
        $this->client->query(Update($ada['ref'], ['data' => ['age' => 37]]));

        self::assertInstanceOf(DateTimeImmutable::class, $this->client->query(Now()));
        self::assertTrue($this->client->query(GT(Select('ts', Get($ada['ref'])), ToMicros(TimeAdd(Now(), -1, 'hour')))));

        $events = $this->client->query(Paginate(Events($ada['ref'])))->data;
        self::assertSame(['create', 'update'], array_column($events, 'action'));
        self::assertSame(['ts', 'action', 'document', 'data'], array_keys($events[0]));
        self::assertSame(36, $this->client->query(Get($ada['ref'], $ada['ts']))['data']['age']);
        self::assertSame(['add'], array_column($this->client->query(Paginate(Events(MatchIndex(Index('users_by_email'), 'ada@example.com'))))->data, 'action'));

        $this->client->query(CreateFunction(['name' => 'members', 'body' => Query(Lambda('role', Size(MatchIndex(Index('users_by_role'), Variable('role')))))]));
        self::assertSame([3, 3], $this->client->query([Call('members', 'admin'), Call(FunctionRef('members'), 'admin')]));
    }

    /**
     * The users of the guide: three admins, by age.
     */
    private function users(): mixed
    {
        $this->client->query(CreateCollection(['name' => 'users']));
        $this->client->query(CreateIndex(['name' => 'users_by_email', 'source' => Collection('users'), 'terms' => [['field' => ['data', 'email']]], 'unique' => true]));
        $this->client->query(CreateIndex([
            'name' => 'users_by_role',
            'source' => Collection('users'),
            'terms' => [['field' => ['data', 'role']]],
            'values' => [['field' => ['data', 'age'], 'reverse' => true], ['field' => ['ref']]],
        ]));
        $this->client->query([
            Create(Collection('users'), ['data' => ['name' => 'Ada', 'email' => 'ada@example.com', 'role' => 'admin', 'age' => 36, 'city' => 'Paris']]),
            Create(Collection('users'), ['data' => ['name' => 'Grace', 'email' => 'grace@example.com', 'role' => 'admin', 'age' => 85, 'city' => 'London']]),
            Create(Collection('users'), ['data' => ['name' => 'Edsger', 'email' => 'edsger@example.com', 'role' => 'admin', 'age' => 72, 'city' => 'Austin']]),
        ]);

        return MatchIndex(Index('users_by_role'), 'admin');
    }

    /**
     * Runs PHP code with variables, and returns them as the code left them.
     *
     * @param array<string, mixed> $variables
     * @return array<string, mixed>
     */
    private static function evaluate(string $code, array $variables): array
    {
        return (static function (string $__code, array $__variables): array {
            extract($__variables);
            unset($__variables);
            eval($__code);
            unset($__code);

            return get_defined_vars();
        })($code, $variables);
    }
}
