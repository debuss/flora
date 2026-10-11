<?php declare(strict_types=1);

namespace Flora\Tests\Integration;

use Flora\Exception\{Aborted, AlreadyExists, InvalidArgument, NotFound};
use Flora\Value\{CollectionRef, FunctionRef as FunctionRefValue, IndexRef, Page, Query as QueryValue, Ref as RefValue};

use function Flora\{
    Abort,
    Add,
    At,
    Between,
    Call,
    Collection,
    Collections,
    Create,
    CreateCollection,
    CreateFunction,
    CreateIndex,
    Delete,
    Exists,
    Filter,
    FunctionRef,
    Functions,
    Get,
    IfElse,
    Index,
    Indexes,
    IsFunction,
    IsRef,
    Lambda,
    Let,
    LT,
    LTE,
    Map,
    MatchIndex,
    Multiply,
    Paginate,
    Query,
    Select,
    Size,
    StartsWith,
    Subtract,
    Update,
    Variable,
};

abstract class FunctionsTestCase extends IntegrationTestCase
{

    protected function setUp(): void
    {
        parent::setUp();

        $this->client->query(CreateCollection(['name' => 'users']));
        $this->client->query(CreateCollection(['name' => 'accounts']));
        $this->client->query(CreateIndex(['name' => 'ages', 'source' => Collection('users'), 'values' => [['field' => ['data', 'age']]]]));
        $this->client->query([
            Create(Collection('users'), ['data' => ['name' => 'Ada', 'age' => 36]]),
            Create(Collection('users'), ['data' => ['name' => 'Grace', 'age' => 85]]),
            Create(Collection('users'), ['data' => ['name' => 'Alan', 'age' => 41]]),
        ]);
        $this->client->query(CreateFunction(['name' => 'double', 'body' => Query(Lambda('x', Multiply(Variable('x'), 2)))]));
    }

    public function testCallingAFunction(): void
    {
        $created = $this->client->query(CreateFunction(['name' => 'minus', 'body' => Lambda(['a', 'b'], Subtract(Variable('a'), Variable('b')))]));

        self::assertEquals(new FunctionRefValue('minus'), $created['ref']);
        self::assertInstanceOf(QueryValue::class, $created['body']);
        self::assertSame(42, $this->client->query(Call(FunctionRef('double'), 21)));
        self::assertSame(42, $this->client->query(Call('double', 21)));
        self::assertSame(7, $this->client->query(Call('minus', 10, 3)));
        self::assertSame([2, 4, 6], $this->client->query(Map([1, 2, 3], Lambda('x', Call('double', Variable('x'))))));
    }

    public function testFunctionsReadAndWrite(): void
    {
        [$from, $to] = array_map(static fn (array $account): RefValue => $account['ref'], $this->client->query([
            Create(Collection('accounts'), ['data' => ['balance' => 100]]),
            Create(Collection('accounts'), ['data' => ['balance' => 0]]),
        ]));
        $balance = static fn (string $account): mixed => Select(['data', 'balance'], Get(Variable($account)));
        $this->client->query(CreateFunction(['name' => 'transfer', 'body' => Lambda(['from', 'to', 'amount'], IfElse(
            LT(Subtract($balance('from'), Variable('amount')), 0),
            Abort('Insufficient funds.'),
            [
                Update(Variable('from'), ['data' => ['balance' => Subtract($balance('from'), Variable('amount'))]]),
                Update(Variable('to'), ['data' => ['balance' => Add($balance('to'), Variable('amount'))]]),
            ],
        ))]));

        $this->client->query(Call('transfer', $from, $to, 70));
        self::assertSame([30, 70], $this->balances($from, $to));

        try {
            $this->client->query(Call('transfer', $from, $to, 50));
            self::fail('The account holds 30.');
        } catch (Aborted $e) {
            self::assertSame('Insufficient funds.', $e->getMessage());
        }
        self::assertSame([30, 70], $this->balances($from, $to));
    }

    public function testFunctionsReturnSets(): void
    {
        $this->client->query(CreateFunction(['name' => 'adults', 'body' => Lambda('age', Between(MatchIndex(Index('ages')), Variable('age'), []))]));

        self::assertSame([41, 85], $this->client->query(Paginate(Call('adults', 40)))->data);
        self::assertSame(2, $this->client->query(Size(Call('adults', 40))));
    }

    public function testFunctionsSeeNoVariablesOfTheQuery(): void
    {
        $this->client->query(CreateFunction(['name' => 'leaky', 'body' => Lambda('_', Variable('secret'))]));

        $this->expectException(NotFound::class);
        $this->expectExceptionMessage('Variable "secret"');

        $this->client->query(Let(['secret' => 1], Call('leaky', null)));
    }

    public function testFunctionsCallFunctions(): void
    {
        $this->client->query(CreateFunction(['name' => 'factorial', 'body' => Lambda('n', IfElse(
            LTE(Variable('n'), 1),
            1,
            Multiply(Variable('n'), Call('factorial', Subtract(Variable('n'), 1))),
        ))]));
        $this->client->query(CreateFunction(['name' => 'quadruple', 'body' => Lambda('x', Call('double', Call('double', Variable('x'))))]));

        self::assertSame(3_628_800, $this->client->query(Call('factorial', 10)));
        self::assertSame(20, $this->client->query(Call('quadruple', 5)));
    }

    public function testCallsNestOnlySoDeep(): void
    {
        $this->client->query(CreateFunction(['name' => 'forever', 'body' => Lambda('n', Call('forever', Variable('n')))]));

        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('nest more than 64 deep');

        $this->client->query(Call('forever', 1));
    }

    public function testFunctionsReadThePastInsideAt(): void
    {
        $this->client->query(CreateFunction(['name' => 'oldest', 'body' => Lambda('_', Select(['data', 'name'], Get(Between(MatchIndex(Index('ages')), 80, []))))]));
        $ts = $this->client->query(CreateCollection(['name' => 'marker']))['ts'];
        $grace = $this->client->query(Get(MatchIndex(Index('ages'))));

        self::assertSame('Ada', $this->client->query(Select(['data', 'name'], $grace)));
        self::assertSame('Grace', $this->client->query(At($ts, Call('oldest', null))));
    }

    public function testUpdatingAFunction(): void
    {
        [$before, , $after] = $this->client->query([
            Call('double', 21),
            Update(FunctionRef('double'), ['body' => Lambda('x', Multiply(Variable('x'), 3))]),
            Call('double', 21),
        ]);
        self::assertSame([42, 63], [$before, $after]);

        $renamed = $this->client->query(Update(FunctionRef('double'), ['name' => 'triple']));
        self::assertEquals(new FunctionRefValue('triple'), $renamed['ref']);
        self::assertSame(63, $this->client->query(Call('triple', 21)));
        self::assertFalse($this->client->query(Exists(FunctionRef('double'))));

        $this->client->query(CreateFunction(['name' => 'double', 'body' => Lambda('x', Multiply(Variable('x'), 2))]));
        $this->expectException(AlreadyExists::class);
        $this->client->query(Update(FunctionRef('triple'), ['name' => 'double']));
    }

    public function testDeletingAFunction(): void
    {
        $deleted = $this->client->query(Delete(FunctionRef('double')));

        self::assertSame('double', $deleted['name']);
        self::assertFalse($this->client->query(Exists(FunctionRef('double'))));

        $this->expectException(NotFound::class);
        $this->client->query(Call('double', 1));
    }

    public function testReadingTheSchema(): void
    {
        $function = $this->client->query(Get(FunctionRef('double')));
        self::assertEquals(Lambda('x', Multiply(Variable('x'), 2)), $function['body']->lambda);

        $index = $this->client->query(Get(Index('ages')));
        self::assertEquals(new IndexRef('ages'), $index['ref']);
        self::assertSame([['field' => ['data', 'age']]], $index['values']);
        self::assertIsInt($index['ts']);

        self::assertSame([true, true, true, false, false], $this->client->query([
            Exists(Collection('users')),
            Exists(Index('ages')),
            Exists(FunctionRef('double')),
            Exists(Collection('nothing')),
            Exists(Index('nothing')),
        ]));
        self::assertSame([true, true, false], $this->client->query([IsFunction(FunctionRef('double')), IsRef(FunctionRef('double')), IsFunction(Index('ages'))]));
    }

    public function testSetsOfTheSchema(): void
    {
        $this->client->query(CreateFunction(['name' => 'adults', 'body' => Lambda('age', Between(MatchIndex(Index('ages')), Variable('age'), []))]));

        self::assertEquals([new CollectionRef('accounts'), new CollectionRef('users')], $this->client->query(Paginate(Collections()))->data);
        self::assertEquals([new IndexRef('ages')], $this->client->query(Paginate(Indexes()))->data);
        self::assertEquals([new FunctionRefValue('adults'), new FunctionRefValue('double')], $this->collectAll(Functions(), 1));
        self::assertSame(2, $this->client->query(Size(Collections())));
        self::assertSame('adults', $this->client->query(Get(Functions()))['name']);
        self::assertSame(['accounts'], $this->client->query(Map(
            Paginate(Filter(Collections(), Lambda('ref', StartsWith(Select('name', Variable('ref')), 'acc')))),
            Lambda('ref', Select('name', Get(Variable('ref')))),
        ))->data);
    }

    public function testFunctionsHaveUniqueNames(): void
    {
        $this->expectException(AlreadyExists::class);

        $this->client->query(CreateFunction(['name' => 'double', 'body' => Lambda('x', Variable('x'))]));
    }

    public function testTheBodyIsALambda(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('a Query() of a Lambda(), or a Lambda()');

        $this->client->query(CreateFunction(['name' => 'one', 'body' => 1]));
    }

    public function testTheBodyHoldsNothingMadeWhileAQueryRuns(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('Build it inside the function instead');

        $this->client->query(CreateFunction(['name' => 'paged', 'body' => Lambda('x', new Page([]))]));
    }

    /**
     * @return list<int>
     */
    private function balances(RefValue ...$accounts): array
    {
        return array_values(array_map(fn (RefValue $account): int => $this->client->query(Get($account))['data']['balance'], $accounts));
    }
}
