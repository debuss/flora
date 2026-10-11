<?php declare(strict_types=1);

namespace Flora\Tests\Integration;

use Flora\Exception\{InvalidArgument, NotFound};

use function Flora\{
    Collection,
    Create,
    CreateCollection,
    CreateIndex,
    Equals,
    Get,
    IfElse,
    Index,
    Lambda,
    Let,
    Map,
    MatchIndex,
    Paginate,
    Select,
    Update,
    Variable,
};

abstract class FunctionalTestCase extends IntegrationTestCase
{

    public function testLambdaDestructuresIndexValues(): void
    {
        $this->client->query(CreateCollection(['name' => 'users']));
        $this->client->query(CreateIndex([
            'name' => 'users_by_role',
            'source' => Collection('users'),
            'terms' => [['field' => ['data', 'role']]],
            'values' => [['field' => ['data', 'age'], 'reverse' => true], ['field' => ['data', 'name']], ['field' => ['ref']]],
        ]));
        $this->client->query([
            Create(Collection('users'), ['data' => ['name' => 'Ada', 'role' => 'admin', 'age' => 36, 'email' => 'ada@example.com']]),
            Create(Collection('users'), ['data' => ['name' => 'Grace', 'role' => 'admin', 'age' => 85]]),
        ]);

        $page = $this->client->query(Map(
            Paginate(MatchIndex(Index('users_by_role'), 'admin')),
            Lambda(['_', 'name', 'ref'], [Variable('name'), Select(['data', 'email'], Get(Variable('ref')), 'none')]),
        ));

        self::assertSame([['Grace', 'none'], ['Ada', 'ada@example.com']], $page->data);
    }

    public function testLetBindsInOrderAndIfElseBranches(): void
    {
        $this->client->query(CreateCollection(['name' => 'users']));
        $this->client->query(CreateIndex(['name' => 'users_by_email', 'source' => Collection('users'), 'terms' => [['field' => ['data', 'email']]]]));
        $this->client->query(Create(Collection('users'), ['data' => ['email' => 'ada@example.com', 'role' => 'admin']]));

        $result = $this->client->query(Let(
            ['user' => Get(MatchIndex(Index('users_by_email'), 'ada@example.com')), 'role' => Select(['data', 'role'], Variable('user'))],
            IfElse(
                Equals(Variable('role'), 'admin'),
                Update(Select('ref', Variable('user')), ['data' => ['promoted' => true]]),
                null,
            ),
        ));

        self::assertTrue($result['data']['promoted']);
    }

    public function testSelect(): void
    {
        self::assertSame(2, $this->client->query(Select(['a', 'b', 1], ['a' => ['b' => [1, 2]]])));
        self::assertSame('x', $this->client->query(Select(['a', 'c'], ['a' => []], 'x')));
        self::assertNull($this->client->query(Select('a', [], null)));

        $this->expectException(NotFound::class);
        $this->client->query(Select(['a', 'c'], ['a' => []]));
    }

    public function testMapOverAList(): void
    {
        self::assertSame([[1, 'a'], [2, 'b']], $this->client->query(Map([['a', 1], ['b', 2]], Lambda(['letter', 'n'], [Variable('n'), Variable('letter')]))));
    }

    public function testLambdaChecksTheShapeOfItsArgument(): void
    {
        $this->expectException(InvalidArgument::class);

        $this->client->query(Map([['a']], Lambda(['x', 'y'], Variable('x'))));
    }

    public function testVariablesAreScoped(): void
    {
        $this->expectException(NotFound::class);

        $this->client->query([Let(['x' => 1], Variable('x')), Variable('x')]);
    }

    public function testEqualsComparesRefsByValue(): void
    {
        $this->client->query(CreateCollection(['name' => 'users']));
        $ref = $this->client->query(Create(Collection('users')))['ref'];

        self::assertTrue($this->client->query(Equals([$ref], [Select('ref', Get($ref))])));
        self::assertFalse($this->client->query(Equals(1, '1')));
    }
}
