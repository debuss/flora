<?php declare(strict_types=1);

namespace Flora\Tests\Integration;

use DateTimeImmutable;
use Flora\Exception\InvalidArgument;
use Flora\Value\{Page, Ref as RefValue};
use PHPUnit\Framework\Attributes\DataProvider;

use function Flora\{
    Add,
    Between,
    Collection,
    ContainsField,
    ContainsPath,
    ContainsValue,
    Create,
    CreateCollection,
    CreateIndex,
    Distinct,
    Documents,
    Drop,
    Filter,
    Get,
    GT,
    Index,
    IsEmpty,
    IsNonEmpty,
    Lambda,
    MatchIndex,
    Mean,
    Merge,
    Paginate,
    Reverse,
    Select,
    SelectAll,
    Sequence,
    Size,
    Sum,
    Take,
    ToArray,
    ToObject,
    Union,
    Variable,
};

abstract class ListsTestCase extends IntegrationTestCase
{

    /** @var array<string, RefValue> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->client->query(CreateCollection(['name' => 'users']));
        foreach ([
            'ages' => [[], [['field' => ['data', 'age']]]],
            'ages_and_refs' => [[], [['field' => ['data', 'age']], ['field' => ['ref']]]],
            'ages_by_role' => [[['field' => ['data', 'role']]], [['field' => ['data', 'age']]]],
            'users_by_role' => [[['field' => ['data', 'role']]], []],
            'tags' => [[], [['field' => ['data', 'tags'], 'unroll' => true]]],
        ] as $name => [$terms, $values]) {
            $this->client->query(CreateIndex(['name' => $name, 'source' => Collection('users'), 'terms' => $terms, 'values' => $values]));
        }

        // Created in one query, so their refs sort in this order. By age: Margaret, Ada, Barbara, Alan, Grace.
        $users = $this->client->query(array_map(
            static fn (array $user): mixed => Create(Collection('users'), ['data' => ['name' => $user[0], 'role' => $user[1], 'age' => $user[2], 'tags' => $user[3]]]),
            [
                ['Ada', 'admin', 36, ['math', 'poetry']],
                ['Grace', 'admin', 85, ['navy', 'compilers']],
                ['Alan', 'editor', 41, ['math', 'crypto']],
                ['Barbara', 'viewer', 36, ['compilers']],
                ['Margaret', 'viewer', 33, ['apollo', 'math']],
            ],
        ));
        foreach ($users as $user) {
            $this->users[$user['data']['name']] = $user['ref'];
        }
    }

    public function testSequence(): void
    {
        // The writes happen in order: the size counts the user just created.
        self::assertSame(3, $this->client->query(Sequence(
            Create(Collection('users'), ['data' => ['name' => 'Linus', 'role' => 'viewer', 'age' => 54]]),
            Size(MatchIndex(Index('users_by_role'), 'viewer')),
        )));
        self::assertSame(1, $this->client->query(Sequence(1)));
    }

    public function testSequenceNeedsAnExpression(): void
    {
        $this->expectException(InvalidArgument::class);

        Sequence();
    }

    public function testSelectAll(): void
    {
        self::assertSame(['bar', 'baz'], $this->client->query(SelectAll('foo', [['foo' => 'bar'], ['foo' => 'baz'], ['other' => 1]])));
        self::assertSame([0, 2], $this->client->query(SelectAll(['foo', 0], [['foo' => [0, 1]], ['foo' => [2, 3]]])));
        self::assertSame(['a', 'b'], $this->client->query(SelectAll(['tags', 'name'], ['tags' => [['name' => 'a'], ['name' => 'b']], 'more' => 1])));
        self::assertSame([['math', 'poetry']], $this->client->query(SelectAll(['data', 'tags'], Get($this->users['Ada']))));
        self::assertSame([], $this->client->query(SelectAll(['nothing', 'here'], ['a' => 1])));
        self::assertSame(['Ada', 'Grace'], $this->client->query(SelectAll(['data', 'name'], [Get($this->users['Ada']), Get($this->users['Grace'])])));
    }

    public function testTakeAndDrop(): void
    {
        self::assertSame([1, 2], $this->client->query(Take(2, [1, 2, 3])));
        self::assertSame([3], $this->client->query(Drop(2, [1, 2, 3])));
        self::assertSame([], $this->client->query(Take(0, [1, 2, 3])));
        self::assertSame([1, 2], $this->client->query(Take(10, [1, 2])));
        self::assertSame([], $this->client->query(Drop(10, [1, 2])));

        // A page keeps its cursors.
        $page = $this->client->query(Paginate(MatchIndex(Index('ages')), ['size' => 3]));
        $taken = $this->client->query(Take(2, Paginate(MatchIndex(Index('ages')), ['size' => 3])));
        self::assertSame([33, 36], $taken->data);
        self::assertEquals($page->after, $taken->after);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidSlices(): iterable
    {
        yield 'negative count' => [Take(-1, [1, 2])];
        yield 'count not an integer' => [Drop('1', [1, 2])];
        yield 'a set' => [Take(1, MatchIndex(Index('ages')))];
        yield 'an object' => [Drop(1, ['a' => 1])];
    }

    #[DataProvider('invalidSlices')]
    public function testSlicesTakeACountAndAList(mixed $query): void
    {
        $this->expectException(InvalidArgument::class);

        $this->client->query($query);
    }

    public function testReverseLists(): void
    {
        self::assertSame([3, 2, 1], $this->client->query(Reverse([1, 2, 3])));
        self::assertSame([36, 36, 33], $this->client->query(Reverse(Paginate(MatchIndex(Index('ages')), ['size' => 3])))->data);
    }

    public function testReverseSets(): void
    {
        $ages = MatchIndex(Index('ages'));

        self::assertSame([85, 41, 36, 36, 33], $this->client->query(Paginate(Reverse($ages)))->data);
        self::assertSame(['Grace', 'Alan', 'Barbara', 'Ada', 'Margaret'], $this->names($this->collectAll(Reverse(MatchIndex(Index('ages_and_refs'))), 2)));
        self::assertSame(5, $this->client->query(Size(Reverse($ages))));
        self::assertSame([33, 36, 36, 41, 85], $this->client->query(Paginate(Reverse(Reverse($ages))))->data);

        // Bounds follow the order of the reversed set.
        self::assertSame([41, 36, 36], $this->client->query(Paginate(Between(Reverse($ages), 41, 36)))->data);
        self::assertSame([41, 36, 36], $this->client->query(Paginate(Reverse(Between($ages, 36, 41))))->data);
        self::assertSame([85, 41], $this->client->query(Paginate(Reverse(Filter($ages, Lambda('age', GT(Variable('age'), 40))))))->data);

        // The oldest admin.
        self::assertSame('Grace', $this->client->query(Select(['data', 'name'], Get(Reverse(MatchIndex(Index('ages_and_refs')))))));
    }

    public function testReversedSetsAreNotCombined(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('apply it to the result instead');

        $this->client->query(Paginate(Union(Reverse(MatchIndex(Index('ages'))), MatchIndex(Index('ages')))));
    }

    public function testDistinctLists(): void
    {
        $ada = $this->users['Ada'];
        $noon = new DateTimeImmutable('2026-10-11T12:00:00Z');

        self::assertEquals(
            [1, 2, 1.0, 'a', [1], $ada, $noon, ['a' => 1]],
            $this->client->query(Distinct([1, 2, 1, 1.0, 'a', 'a', [1], [1], $ada, new RefValue($ada->collection, $ada->id), $noon, new DateTimeImmutable('2026-10-11T14:00:00+02:00'), ['a' => 1], ['a' => 1]])),
        );
        self::assertSame([33, 36], $this->client->query(Distinct(Paginate(MatchIndex(Index('ages')), ['size' => 3])))->data);
    }

    public function testDistinctSets(): void
    {
        $tags = MatchIndex(Index('tags'));

        self::assertSame([33, 36, 41, 85], $this->client->query(Paginate(Distinct(MatchIndex(Index('ages')))))->data);
        self::assertSame(4, $this->client->query(Size(Distinct(MatchIndex(Index('ages'))))));
        // Pages of one entry: the repeated values span several reads.
        self::assertSame([33, 36, 41, 85], $this->collectAll(Distinct(MatchIndex(Index('ages'))), 1));

        self::assertSame(['apollo', 'compilers', 'crypto', 'math', 'navy', 'poetry'], $this->collectAll(Distinct($tags), 2));
        self::assertSame(6, $this->client->query(Size(Distinct($tags))));
        self::assertSame(['poetry', 'navy', 'math', 'crypto', 'compilers', 'apollo'], $this->collectAll(Reverse(Distinct($tags)), 2));
        self::assertSame(['poetry', 'navy', 'math', 'crypto', 'compilers', 'apollo'], $this->client->query(Paginate(Distinct(Reverse($tags))))->data);
        self::assertSame(['compilers', 'crypto'], $this->client->query(Paginate(Between(Distinct($tags), 'c', 'm')))->data);

        // Refs are all different.
        self::assertSame(5, $this->client->query(Size(Distinct(Documents(Collection('users'))))));
    }

    public function testIsEmpty(): void
    {
        self::assertTrue($this->client->query(IsEmpty([])));
        self::assertFalse($this->client->query(IsEmpty([null])));
        self::assertTrue($this->client->query(IsNonEmpty([1])));
        self::assertTrue($this->client->query(IsEmpty(Paginate(MatchIndex(Index('users_by_role'), 'guest')))));

        self::assertTrue($this->client->query(IsEmpty(MatchIndex(Index('users_by_role'), 'guest'))));
        self::assertFalse($this->client->query(IsEmpty(MatchIndex(Index('users_by_role'), 'admin'))));
        self::assertTrue($this->client->query(IsNonEmpty(MatchIndex(Index('users_by_role'), 'admin'))));
        self::assertTrue($this->client->query(IsEmpty(Filter(MatchIndex(Index('ages')), Lambda('age', GT(Variable('age'), 90))))));
    }

    public function testSumAndMean(): void
    {
        self::assertSame(6, $this->client->query(Sum([1, 2, 3])));
        self::assertSame(3.5, $this->client->query(Sum([1, 2.5])));
        self::assertSame(0, $this->client->query(Sum([])));
        self::assertSame(2.5, $this->client->query(Mean([1, 2, 3, 4])));
        self::assertSame(2, $this->client->query(Mean([1, 2, 3])));

        self::assertSame(231, $this->client->query(Sum(MatchIndex(Index('ages')))));
        self::assertEqualsWithDelta(46.2, $this->client->query(Mean(MatchIndex(Index('ages')))), 1e-9);
        self::assertSame(195, $this->client->query(Sum(Distinct(MatchIndex(Index('ages'))))));
        self::assertSame(121, $this->client->query(Sum(MatchIndex(Index('ages_by_role'), 'admin'))));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidSums(): iterable
    {
        yield 'mean of nothing' => [Mean([])];
        yield 'a string' => [Sum([1, 'a'])];
        yield 'a set of refs' => [Sum(MatchIndex(Index('users_by_role'), 'admin'))];
        yield 'an object' => [Sum(['a' => 1])];
    }

    #[DataProvider('invalidSums')]
    public function testSumsTakeNumbers(mixed $query): void
    {
        $this->expectException(InvalidArgument::class);

        $this->client->query($query);
    }

    public function testContainsValueInListsAndObjects(): void
    {
        self::assertTrue($this->client->query(ContainsValue(2, [1, 2, 3])));
        self::assertFalse($this->client->query(ContainsValue(2.0, [1, 2, 3])));
        self::assertTrue($this->client->query(ContainsValue(1, ['a' => 1])));
        self::assertFalse($this->client->query(ContainsValue('a', ['a' => 1])));
        self::assertTrue($this->client->query(ContainsValue(36, Paginate(MatchIndex(Index('ages'))))));
    }

    public function testContainsValueInSets(): void
    {
        $ages = MatchIndex(Index('ages'));
        $admins = MatchIndex(Index('users_by_role'), 'admin');

        self::assertTrue($this->client->query(ContainsValue(41, $ages)));
        self::assertFalse($this->client->query(ContainsValue(40, $ages)));
        self::assertTrue($this->client->query(ContainsValue([36, $this->users['Barbara']], MatchIndex(Index('ages_and_refs')))));
        self::assertFalse($this->client->query(ContainsValue([36, $this->users['Alan']], MatchIndex(Index('ages_and_refs')))));
        self::assertFalse($this->client->query(ContainsValue(36, MatchIndex(Index('ages_and_refs')))));

        self::assertTrue($this->client->query(ContainsValue($this->users['Ada'], $admins)));
        self::assertFalse($this->client->query(ContainsValue($this->users['Alan'], $admins)));
        self::assertFalse($this->client->query(ContainsValue('Ada', $admins)));

        self::assertTrue($this->client->query(ContainsValue('math', MatchIndex(Index('tags')))));
        self::assertFalse($this->client->query(ContainsValue(36, Filter($ages, Lambda('age', GT(Variable('age'), 40))))));
        self::assertTrue($this->client->query(ContainsValue(85, Reverse(Filter($ages, Lambda('age', GT(Variable('age'), 40)))))));
    }

    public function testContainsPathAndField(): void
    {
        $ada = Get($this->users['Ada']);

        self::assertTrue($this->client->query(ContainsPath(['data', 'tags', 1], $ada)));
        self::assertFalse($this->client->query(ContainsPath(['data', 'tags', 2], $ada)));
        self::assertTrue($this->client->query(ContainsPath(['ref', 'id'], $ada)));
        self::assertFalse($this->client->query(ContainsPath(['data', 'name', 'first'], $ada)));
        self::assertTrue($this->client->query(ContainsField('name', ['name' => 'Ada'])));
        self::assertFalse($this->client->query(ContainsField('age', ['name' => 'Ada'])));
        self::assertTrue($this->client->query(ContainsField('data', $ada)));
    }

    public function testMerge(): void
    {
        self::assertSame(['a' => 1, 'b' => 3, 'c' => 4], $this->client->query(Merge(['a' => 1, 'b' => 2], ['b' => 3, 'c' => 4])));
        self::assertSame(['a' => 1], $this->client->query(Merge(['a' => 1, 'b' => 2], ['b' => null])));
        self::assertSame(['a' => 1, 'b' => 2, 'c' => 3], $this->client->query(Merge(['a' => 1], [['b' => 2], ['c' => 3]])));
        self::assertSame(['a' => 1], $this->client->query(Merge([], ['a' => 1])));

        // One level deep.
        self::assertSame(['a' => ['y' => 2]], $this->client->query(Merge(['a' => ['x' => 1]], ['a' => ['y' => 2]])));

        $resolver = Lambda(['field', 'left', 'right'], Add(Variable('left'), Variable('right')));
        self::assertSame(['a' => 3, 'b' => 5], $this->client->query(Merge(['a' => 1], ['a' => 2, 'b' => 5], $resolver)));
    }

    public function testMergeTakesObjects(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('Merge() object');

        $this->client->query(Merge([1, 2], ['a' => 1]));
    }

    public function testToObjectAndToArray(): void
    {
        self::assertSame(['a' => 1, 'b' => [2]], $this->client->query(ToObject([['a', 1], ['b', [2]]])));
        self::assertSame([['a', 1], ['b', [2]]], $this->client->query(ToArray(['a' => 1, 'b' => [2]])));
        self::assertSame([['1', 'one']], $this->client->query(ToArray(['1' => 'one'])));
        // Databases may store the fields of a document in another order.
        $data = Select('data', Get($this->users['Ada']));
        self::assertEquals($this->client->query($data), $this->client->query(ToObject(ToArray($data))));
        self::assertCount(4, $this->client->query(ToArray($data)));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidObjects(): iterable
    {
        yield 'a pair of three' => [ToObject([['a', 1, 2]])];
        yield 'a field not a string' => [ToObject([[1, 'a']])];
        yield 'a list to ToArray' => [ToArray([1, 2])];
    }

    #[DataProvider('invalidObjects')]
    public function testObjectsAndPairs(mixed $query): void
    {
        $this->expectException(InvalidArgument::class);

        $this->client->query($query);
    }

    /**
     * @param list<list<mixed>> $entries [age, ref] entries
     * @return list<string>
     */
    private function names(array $entries): array
    {
        return array_map(fn (array $entry): string => (string) array_search($entry[1], $this->users), $entries);
    }
}
