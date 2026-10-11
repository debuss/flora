<?php declare(strict_types=1);

namespace Flora\Tests\Integration;

use Flora\Exception\{InvalidArgument, NotFound};
use Flora\Expr\Expr;
use Flora\Value\Ref as RefValue;
use PHPUnit\Framework\Attributes\DataProvider;

use function Flora\{
    Collection,
    Create,
    CreateCollection,
    CreateIndex,
    Difference,
    Documents,
    Equals,
    Exists,
    Get,
    IfElse,
    Index,
    Intersection,
    Join,
    Lambda,
    MatchIndex,
    Select,
    Union,
    Variable,
};

abstract class JoinsTestCase extends IntegrationTestCase
{

    /** @var array<string, RefValue> */
    private array $refs = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['users', 'posts', 'comments', 'projects', 'grants'] as $collection) {
            $this->client->query(CreateCollection(['name' => $collection]));
        }

        foreach ([
            'users_by_role' => ['users', ['role'], []],
            'users_by_team' => ['users', ['team'], []],
            'users_by_mentor' => ['users', ['mentor'], []],
            'teams_by_role' => ['users', ['role'], [['team']]],
            'teams_and_roles_by_name' => ['users', ['name'], [['team'], ['role']]],
            'posts_by_author' => ['posts', ['author'], []],
            'posts_by_reviewer' => ['posts', ['reviewer'], []],
            'posts_by_name' => ['posts', ['name'], []],
            'titles_by_author' => ['posts', ['author'], [['name']]],
            'comments_by_post' => ['comments', ['post'], []],
            'projects_by_team' => ['projects', ['team'], [['name']]],
            'grants_by_team_and_role' => ['grants', ['team', 'role'], [['right']]],
        ] as $name => [$collection, $terms, $values]) {
            $this->client->query(CreateIndex([
                'name' => $name,
                'source' => Collection($collection),
                'terms' => array_map(static fn (string $term): array => ['field' => ['data', $term]], $terms),
                'values' => array_map(static fn (array $path): array => ['field' => ['data', ...$path]], $values),
            ]));
        }

        // Each collection is filled in one query, so that refs sort in this order.
        $this->create('users', [
            'Ada' => ['role' => 'admin', 'team' => 'core'],
            'Grace' => ['role' => 'admin', 'team' => 'web'],
        ]);
        $this->create('users', [
            'Alan' => ['role' => 'editor', 'team' => 'core', 'mentor' => $this->refs['Ada']],
            'Linus' => ['role' => 'viewer', 'mentor' => $this->refs['Grace']],
        ]);
        $this->create('posts', [
            'Notes' => ['author' => $this->refs['Ada'], 'reviewer' => $this->refs['Grace']],
            'Compilers' => ['author' => $this->refs['Grace'], 'reviewer' => $this->refs['Grace']],
            'Machines' => ['author' => $this->refs['Alan'], 'reviewer' => $this->refs['Ada']],
            'Engines' => ['author' => $this->refs['Ada']],
            'Orphan' => [],
        ]);
        $this->create('comments', [
            'On notes' => ['post' => $this->refs['Notes']],
            'On compilers' => ['post' => $this->refs['Compilers']],
            'On machines' => ['post' => $this->refs['Machines']],
            'On notes again' => ['post' => $this->refs['Notes']],
        ]);
        $this->create('projects', [
            'Flora' => ['team' => 'core'],
            'Site' => ['team' => 'web'],
            'Docs' => [],
        ]);
        $this->create('grants', [
            'deploy' => ['team' => 'core', 'role' => 'admin', 'right' => 'deploy'],
            'review' => ['team' => 'core', 'role' => 'editor', 'right' => 'review'],
            'publish' => ['team' => 'web', 'role' => 'admin', 'right' => 'publish'],
        ]);
    }

    public function testJoinOnRefs(): void
    {
        $posts = Join(MatchIndex(Index('users_by_role'), 'admin'), Index('posts_by_author'));

        self::assertEquals($this->refs('Notes', 'Compilers', 'Engines'), $this->collectAll($posts, 2));
    }

    public function testJoinSortsByTheValuesOfTheDetailIndex(): void
    {
        $titles = Join(MatchIndex(Index('users_by_role'), 'admin'), Index('titles_by_author'));

        self::assertSame(['Compilers', 'Engines', 'Notes'], $this->collectAll($titles, 2));
    }

    public function testJoinOnValues(): void
    {
        self::assertSame(['Flora', 'Site'], $this->collectAll(Join(MatchIndex(Index('teams_by_role'), 'admin'), Index('projects_by_team')), 1));
        self::assertSame(['Flora'], $this->collectAll(Join(MatchIndex(Index('teams_by_role'), 'editor'), Index('projects_by_team')), 1));
    }

    public function testJoinOnSeveralValues(): void
    {
        // Ada's entry holds her team and her role, matched with both terms of the grants.
        $grants = Join(MatchIndex(Index('teams_and_roles_by_name'), 'Ada'), Index('grants_by_team_and_role'));

        self::assertSame(['deploy'], $this->collectAll($grants, 1));
    }

    public function testJoinWithinACollection(): void
    {
        $mentees = Join(MatchIndex(Index('users_by_role'), 'admin'), Index('users_by_mentor'));

        self::assertEquals($this->refs('Alan', 'Linus'), $this->collectAll($mentees, 1));
    }

    public function testNestedJoins(): void
    {
        $comments = Join(Join(MatchIndex(Index('users_by_role'), 'admin'), Index('posts_by_author')), Index('comments_by_post'));

        self::assertEquals($this->refs('On notes', 'On compilers', 'On notes again'), $this->collectAll($comments, 2));
    }

    public function testJoinOfCombinedSets(): void
    {
        $admins = MatchIndex(Index('users_by_role'), 'admin');
        $editors = MatchIndex(Index('users_by_role'), 'editor');
        $web = MatchIndex(Index('users_by_team'), 'web');

        self::assertEquals($this->refs('Notes', 'Compilers', 'Machines', 'Engines'), $this->collectAll(Join(Union($admins, $editors), Index('posts_by_author')), 3));
        self::assertEquals($this->refs('Notes', 'Engines'), $this->collectAll(Join(Difference($admins, $web), Index('posts_by_author')), 1));
        self::assertEquals($this->refs('Compilers'), $this->collectAll(Join(Intersection($admins, $web), Index('posts_by_author')), 1));
    }

    public function testCombiningJoins(): void
    {
        $byAdmins = Join(MatchIndex(Index('users_by_role'), 'admin'), Index('posts_by_author'));
        $byWeb = Join(MatchIndex(Index('users_by_team'), 'web'), Index('posts_by_author'));

        self::assertEquals($this->refs('Notes', 'Engines'), $this->collectAll(Difference($byAdmins, $byWeb), 1));
        self::assertEquals($this->refs('Compilers'), $this->collectAll(Intersection($byAdmins, $byWeb), 1));
        self::assertEquals(
            $this->refs('Notes', 'Compilers', 'Machines', 'Engines'),
            $this->collectAll(Union($byAdmins, MatchIndex(Index('posts_by_author'), $this->refs['Alan'])), 2),
        );
    }

    public function testGetAndExists(): void
    {
        self::assertEquals($this->refs['Notes'], $this->client->query(Get(Join(MatchIndex(Index('users_by_role'), 'admin'), Index('posts_by_author'))))['ref']);
        self::assertFalse($this->client->query(Exists(Join(MatchIndex(Index('users_by_role'), 'viewer'), Index('posts_by_author')))));

        $this->expectException(NotFound::class);
        $this->client->query(Get(Join(MatchIndex(Index('users_by_role'), 'viewer'), Index('posts_by_author'))));
    }

    public function testTheDetailIndexHasOneTermPerValue(): void
    {
        $this->expectException(InvalidArgument::class);

        $this->client->query(Join(MatchIndex(Index('users_by_role'), 'admin'), Index('grants_by_team_and_role')));
    }

    public function testJoinWithALambda(): void
    {
        $posts = Join(MatchIndex(Index('users_by_role'), 'admin'), Lambda('ref', MatchIndex(Index('posts_by_author'), Variable('ref'))));

        self::assertEquals($this->refs('Notes', 'Compilers', 'Engines'), $this->collectAll($posts, 2));
    }

    public function testLambdaDestructuresTheValuesOfTheSource(): void
    {
        $ada = MatchIndex(Index('teams_and_roles_by_name'), 'Ada');

        self::assertSame(['deploy'], $this->collectAll(Join($ada, Lambda(['team', 'role'], MatchIndex(Index('grants_by_team_and_role'), Variable('team'), Variable('role')))), 1));

        // Items can be left out, and mixed with constants.
        self::assertSame(['Flora'], $this->collectAll(Join($ada, Lambda(['team', '_'], MatchIndex(Index('projects_by_team'), Variable('team')))), 1));
        self::assertSame(['review'], $this->collectAll(Join($ada, Lambda(['team', '_'], MatchIndex(Index('grants_by_team_and_role'), Variable('team'), 'editor'))), 1));
    }

    public function testLambdaReturningCombinedSets(): void
    {
        $admins = MatchIndex(Index('users_by_role'), 'admin');
        $byAuthor = MatchIndex(Index('posts_by_author'), Variable('ref'));

        // The terms of the result refer to the same admin: Notes, written by Ada and reviewed by Grace, is not there.
        $selfReviewed = Join($admins, Lambda('ref', Intersection($byAuthor, MatchIndex(Index('posts_by_reviewer'), Variable('ref')))));
        self::assertEquals($this->refs('Compilers'), $this->collectAll($selfReviewed, 1));

        $writtenOrReviewed = Join($admins, Lambda('ref', Union($byAuthor, MatchIndex(Index('posts_by_reviewer'), Variable('ref')))));
        self::assertEquals($this->refs('Notes', 'Compilers', 'Machines', 'Engines'), $this->collectAll($writtenOrReviewed, 3));

        $withOrphan = Join($admins, Lambda('ref', Union($byAuthor, MatchIndex(Index('posts_by_name'), 'Orphan'))));
        self::assertEquals($this->refs('Notes', 'Compilers', 'Engines', 'Orphan'), $this->collectAll($withOrphan, 2));

        $exceptEngines = Join($admins, Lambda('ref', Difference($byAuthor, MatchIndex(Index('posts_by_name'), 'Engines'))));
        self::assertEquals($this->refs('Notes', 'Compilers'), $this->collectAll($exceptEngines, 1));
    }

    public function testNestedLambdaJoins(): void
    {
        $admins = MatchIndex(Index('users_by_role'), 'admin');

        $comments = Join(
            Join($admins, Lambda('ref', MatchIndex(Index('posts_by_author'), Variable('ref')))),
            Lambda('post', MatchIndex(Index('comments_by_post'), Variable('post'))),
        );
        self::assertEquals($this->refs('On notes', 'On compilers', 'On notes again'), $this->collectAll($comments, 2));

        // Posts of admins reviewed by someone of the web team: a join in the result of the lambda, with its own lambda.
        $reviewedByWeb = Join($admins, Lambda('ref', Intersection(
            MatchIndex(Index('posts_by_author'), Variable('ref')),
            Join(MatchIndex(Index('users_by_team'), 'web'), Lambda('member', MatchIndex(Index('posts_by_reviewer'), Variable('member')))),
        )));
        self::assertEquals($this->refs('Notes', 'Compilers'), $this->collectAll($reviewedByWeb, 1));
    }

    /**
     * @return iterable<string, array{Expr, string}>
     */
    public static function misusedLambdas(): iterable
    {
        $termsOnly = 'can only be terms of MatchIndex()';

        yield 'not a set' => [Lambda('ref', Variable('ref')), 'The result of a Join() lambda must be a set'];
        yield 'compared' => [Lambda('ref', IfElse(Equals(Variable('ref'), 1), Documents(Collection('posts')), Documents(Collection('posts')))), $termsOnly];
        yield 'read' => [Lambda('ref', MatchIndex(Index('posts_by_author'), Select('id', Variable('ref')))), $termsOnly];
        yield 'excluded' => [Lambda('ref', Difference(Documents(Collection('posts')), MatchIndex(Index('posts_by_author'), Variable('ref')))), 'the sets a Difference() excludes'];
        yield 'in a nested source' => [Lambda('ref', Join(MatchIndex(Index('posts_by_author'), Variable('ref')), Index('comments_by_post'))), 'not in a nested Join()'];
        yield 'destructured without values' => [Lambda(['a', 'b'], MatchIndex(Index('posts_by_author'), Variable('a'))), 'list of 2 values to destructure'];
    }

    /**
     * The parameters stand for all the entries of the source at once: they can only be terms of MatchIndex().
     */
    #[DataProvider('misusedLambdas')]
    public function testLambdaParametersAreOnlyTerms(Expr $lambda, string $message): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage($message);

        $this->client->query(Join(MatchIndex(Index('users_by_role'), 'admin'), $lambda));
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
