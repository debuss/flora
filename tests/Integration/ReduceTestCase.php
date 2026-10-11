<?php declare(strict_types=1);

namespace Flora\Tests\Integration;

use Flora\Exception\InvalidArgument;

use function Flora\{
    Absolute,
    Add,
    Append,
    Collection,
    Concat,
    Create,
    CreateCollection,
    CreateIndex,
    Divide,
    Documents,
    Equals,
    Filter,
    Get,
    Greatest,
    IfElse,
    Index,
    Lambda,
    Least,
    MatchIndex,
    Modulo,
    Multiply,
    Paginate,
    Prepend,
    Reduce,
    Select,
    Subtract,
    Variable,
};

abstract class ReduceTestCase extends IntegrationTestCase
{

    protected function setUp(): void
    {
        parent::setUp();

        $this->client->query(CreateCollection(['name' => 'users']));
        $this->client->query(CreateIndex([
            'name' => 'ages_by_role',
            'source' => Collection('users'),
            'terms' => [['field' => ['data', 'role']]],
            'values' => [['field' => ['data', 'age']], ['field' => ['ref']]],
        ]));

        $this->client->query(array_map(
            static fn (array $user): mixed => Create(Collection('users'), ['data' => ['name' => $user[0], 'role' => $user[1], 'city' => $user[2], 'age' => $user[3]]]),
            [['Ada', 'admin', 'Paris', 36], ['Grace', 'admin', 'London', 85], ['Alan', 'editor', 'Paris', 41], ['Margaret', 'admin', 'Paris', 33]],
        ));
    }

    public function testReducingAList(): void
    {
        self::assertSame(6, $this->client->query(Reduce(Lambda(['sum', 'n'], Add(Variable('sum'), Variable('n'))), 0, [1, 2, 3])));
        self::assertSame('start', $this->client->query(Reduce(Lambda(['value', '_'], Variable('value')), 'start', [])));
    }

    public function testReducingAPage(): void
    {
        $page = Paginate(MatchIndex(Index('ages_by_role'), 'admin'), ['size' => 2]);

        // The two youngest admins: Margaret, 33, and Ada, 36.
        self::assertSame(69, $this->client->query(Reduce(Lambda(['sum', 'entry'], Add(Variable('sum'), Select(0, Variable('entry')))), 0, $page)));
    }

    public function testReducingASet(): void
    {
        $admins = MatchIndex(Index('ages_by_role'), 'admin');

        self::assertSame(154, $this->client->query(Reduce(Lambda(['sum', 'entry'], Add(Variable('sum'), Select(0, Variable('entry')))), 0, $admins)));
        self::assertSame(85, $this->client->query(Reduce(Lambda(['oldest', 'entry'], Greatest(Variable('oldest'), Select(0, Variable('entry')))), 0, $admins)));

        // The reducer can do anything a query does: count the admins living in Paris.
        $parisians = Reduce(
            Lambda(['count', 'entry'], IfElse(
                Equals(Select(['data', 'city'], Get(Select(1, Variable('entry')))), 'Paris'),
                Add(Variable('count'), 1),
                Variable('count'),
            )),
            0,
            $admins,
        );
        self::assertSame(2, $this->client->query($parisians));
    }

    public function testReducingASetLargerThanAChunk(): void
    {
        $this->client->query(array_map(
            static fn (int $i): mixed => Create(Collection('users'), ['data' => ['role' => 'user', 'age' => $i % 10]]),
            range(1, 1100),
        ));

        // 110 users of each age from 0 to 9.
        self::assertSame(4950, $this->client->query(Reduce(
            Lambda(['sum', 'entry'], Add(Variable('sum'), Select(0, Variable('entry')))),
            0,
            MatchIndex(Index('ages_by_role'), 'user'),
        )));
    }

    public function testReducingAFilteredSet(): void
    {
        $inParis = Filter(Documents(Collection('users')), Lambda('ref', Equals(Select(['data', 'city'], Get(Variable('ref'))), 'Paris')));

        self::assertSame(3, $this->client->query(Reduce(Lambda(['count', '_'], Add(Variable('count'), 1)), 0, $inParis)));
    }

    public function testAppend(): void
    {
        self::assertSame([1, 2, 3, 4, 5], $this->client->query(Append([4, 5], [1, 2, 3])));
        self::assertSame([], $this->client->query(Append([], [])));
    }

    public function testPrepend(): void
    {
        self::assertSame([1, 2, 3, 4], $this->client->query(Prepend([1, 2], [3, 4])));
        self::assertSame(['a'], $this->client->query(Prepend(['a'], [])));
    }

    public function testPrependTakesLists(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('Prepend() base must be a list');

        $this->client->query(Prepend(['a'], 'b'));
    }

    public function testConcat(): void
    {
        self::assertSame('abc', $this->client->query(Concat(['a', 'b', 'c'])));
        self::assertSame('Ada Lovelace', $this->client->query(Concat(['Ada', 'Lovelace'], ' ')));
        self::assertSame('', $this->client->query(Concat([], ', ')));
    }

    public function testReducingASetIntoAListAndAString(): void
    {
        // The admins by age: Margaret, 33, Ada, 36, Grace, 85.
        $names = Reduce(
            Lambda(['names', 'entry'], Append([Select(['data', 'name'], Get(Select(1, Variable('entry'))))], Variable('names'))),
            [],
            MatchIndex(Index('ages_by_role'), 'admin'),
        );

        self::assertSame(['Margaret', 'Ada', 'Grace'], $this->client->query($names));
        self::assertSame('Margaret, Ada, Grace', $this->client->query(Concat($names, ', ')));
    }

    public function testAppendTakesLists(): void
    {
        $this->expectException(InvalidArgument::class);

        $this->client->query(Append('d', ['a', 'b', 'c']));
    }

    public function testConcatTakesStrings(): void
    {
        $this->expectException(InvalidArgument::class);

        $this->client->query(Concat(['a', 1]));
    }

    public function testArithmetic(): void
    {
        self::assertSame(6, $this->client->query(Add(1, 2, 3)));
        self::assertSame(5, $this->client->query(Subtract(10, 2, 3)));
        self::assertSame(24, $this->client->query(Multiply(2, 3, 4)));
        self::assertSame(3, $this->client->query(Divide(7, 2)));
        self::assertSame(3.5, $this->client->query(Divide(7.0, 2)));
        self::assertSame(1, $this->client->query(Modulo(7, 3)));
        self::assertSame(1.5, $this->client->query(Modulo(7.5, 3)));
        self::assertSame(9, $this->client->query(Greatest(3, 9, 4)));
        self::assertSame(-2.5, $this->client->query(Least(3, -2.5, 4)));
        self::assertSame(4, $this->client->query(Absolute(-4)));
    }

    public function testDividingByZero(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('Divide() by zero');

        $this->client->query(Divide(1, 0));
    }

    public function testArithmeticOnNumbersOnly(): void
    {
        $this->expectException(InvalidArgument::class);

        $this->client->query(Add(1, '2'));
    }

    public function testTheReducerIsALambda(): void
    {
        $this->expectException(InvalidArgument::class);

        $this->client->query(Reduce(0, 0, [1]));
    }
}
