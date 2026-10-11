<?php declare(strict_types=1);

namespace Flora\Tests\Integration;

use Flora\Exception\InvalidArgument;
use PHPUnit\Framework\Attributes\{DataProvider, RequiresPhpExtension};

use function Flora\{
    Casefold,
    Collection,
    ContainsStr,
    ContainsStrRegex,
    Create,
    CreateCollection,
    CreateIndex,
    Documents,
    EndsWith,
    Filter,
    FindStr,
    FindStrRegex,
    Format,
    Get,
    Index,
    IsArray,
    IsBoolean,
    IsCollection,
    IsDoc,
    IsDouble,
    IsIndex,
    IsInteger,
    IsLambda,
    IsNull,
    IsNumber,
    IsObject,
    IsRef,
    IsSetRef,
    IsString,
    IsTimestamp,
    Lambda,
    Length,
    LowerCase,
    LTrimStr,
    MatchIndex,
    Ref,
    RegexEscape,
    Repeat,
    ReplaceStr,
    ReplaceStrRegex,
    RTrimStr,
    Select,
    Size,
    Space,
    SplitStr,
    SplitStrRegex,
    StartsWith,
    SubString,
    Timestamp,
    TitleCase,
    ToDouble,
    ToInteger,
    ToNumber,
    ToString,
    TrimStr,
    UpperCase,
    Variable,
};

abstract class StringsTestCase extends IntegrationTestCase
{

    protected function setUp(): void
    {
        parent::setUp();

        $this->client->query(CreateCollection(['name' => 'users']));
        $this->client->query(CreateIndex(['name' => 'users_by_name', 'source' => Collection('users'), 'terms' => [['field' => ['data', 'name']]]]));
    }

    public function testCase(): void
    {
        self::assertSame('école été', $this->client->query(LowerCase('ÉCOLE Été')));
        self::assertSame('STRASSE', $this->client->query(UpperCase('straße')));
        self::assertSame('Ada Lovelace', $this->client->query(TitleCase('ada lovelace')));
    }

    #[RequiresPhpExtension('intl')]
    public function testCasefold(): void
    {
        self::assertSame('strasse', $this->client->query(Casefold('Straße')));
        // Compatibility characters fold like those they stand for, unless only composed.
        self::assertSame('abc', $this->client->query(Casefold('ＡＢＣ')));
        self::assertSame('ａｂｃ', $this->client->query(Casefold('ＡＢＣ', 'NFC')));
    }

    public function testLengthAndSubString(): void
    {
        self::assertSame(3, $this->client->query(Length('été')));
        self::assertSame('Lovelace', $this->client->query(SubString('Ada Lovelace', 4)));
        self::assertSame('Ada', $this->client->query(SubString('Ada Lovelace', 0, 3)));
        self::assertSame('Love', $this->client->query(SubString('Ada Lovelace', -8, 4)));
        self::assertSame('t', $this->client->query(SubString('été', 1, 1)));
        self::assertSame('été', $this->client->query(SubString('été', PHP_INT_MIN)));
    }

    public function testSearching(): void
    {
        self::assertTrue($this->client->query(ContainsStr('Ada Lovelace', 'Love')));
        self::assertFalse($this->client->query(ContainsStr('Ada Lovelace', 'love')));
        self::assertTrue($this->client->query(StartsWith('Ada Lovelace', 'Ada')));
        self::assertTrue($this->client->query(EndsWith('Ada Lovelace', 'lace')));

        self::assertSame(4, $this->client->query(FindStr('One Fish Two Fish', 'Fish')));
        self::assertSame(13, $this->client->query(FindStr('One Fish Two Fish', 'Fish', 5)));
        self::assertSame(-1, $this->client->query(FindStr('One Fish Two Fish', 'Cat')));
        self::assertSame(-1, $this->client->query(FindStr('One Fish', 'Fish', 20)));
        self::assertSame(1, $this->client->query(FindStr('été', 't')));
    }

    public function testRegularExpressions(): void
    {
        self::assertTrue($this->client->query(ContainsStrRegex('Ada Lovelace', '^A\w+ L')));
        self::assertSame(
            [['start' => 0, 'end' => 2, 'data' => 'One'], ['start' => 9, 'end' => 11, 'data' => 'Two']],
            $this->client->query(FindStrRegex('One Fish Two Fish', '[oO]ne|[tT]wo')),
        );
        self::assertSame([['start' => 5, 'end' => 5, 'data' => 't']], $this->client->query(FindStrRegex('été été', 't', 2)));
        self::assertCount(1, $this->client->query(FindStrRegex('One Fish Two Fish', 'Fish', 0, 1)));

        self::assertSame('One Cat Two Cat', $this->client->query(ReplaceStrRegex('One Fish Two Fish', '(\w+) Fish', '$1 Cat')));
        self::assertSame('One Cat Two Fish', $this->client->query(ReplaceStrRegex('One Fish Two Fish', '(\w+) Fish', '$1 Cat', true)));
        self::assertSame(['a', 'b', 'c'], $this->client->query(SplitStrRegex('a1b22c', '\d+')));
        self::assertSame(['a', 'b22c'], $this->client->query(SplitStrRegex('a1b22c', '\d+', 2)));

        // Patterns need no delimiters: slashes and tildes are characters like others.
        self::assertTrue($this->client->query(ContainsStrRegex('a/b~c', '/b~')));
        self::assertTrue($this->client->query(ContainsStrRegex('is 1+1=2?', RegexEscape('1+1=2?'))));
    }

    public function testInvalidRegularExpressions(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('is not a valid regular expression');

        $this->client->query(ContainsStrRegex('a', '('));
    }

    public function testReplacingSplittingAndTrimming(): void
    {
        self::assertSame('a+b+c', $this->client->query(ReplaceStr('a-b-c', '-', '+')));
        self::assertSame(['a', 'b', 'c'], $this->client->query(SplitStr('a,b,c', ',')));
        self::assertSame(['a', 'b,c'], $this->client->query(SplitStr('a,b,c', ',', 2)));
        self::assertSame('abab', $this->client->query(Repeat('ab')));
        self::assertSame('ababab', $this->client->query(Repeat('ab', 3)));
        self::assertSame('   ', $this->client->query(Space(3)));
        self::assertSame('Ada', $this->client->query(TrimStr("  \u{00A0}Ada\t\n")));
        self::assertSame('Ada  ', $this->client->query(LTrimStr('  Ada  ')));
        self::assertSame('  Ada', $this->client->query(RTrimStr('  Ada  ')));
    }

    public function testStringsInQueries(): void
    {
        $this->client->query([
            Create(Collection('users'), ['data' => ['name' => 'Ada Lovelace']]),
            Create(Collection('users'), ['data' => ['name' => 'Grace Hopper']]),
        ]);

        $lovers = Filter(Documents(Collection('users')), Lambda('ref', ContainsStr(LowerCase(Select(['data', 'name'], Get(Variable('ref')))), 'love')));
        self::assertSame(1, $this->client->query(Size($lovers)));

        $ada = Get(MatchIndex(Index('users_by_name'), TitleCase('ada lovelace')));
        self::assertSame('Lovelace, Ada', $this->client->query(Format('%2$s, %1$s', ...array_map(
            static fn (int $i): mixed => Select($i, SplitStr(Select(['data', 'name'], $ada), ' ')),
            [0, 1],
        ))));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidStringCalls(): iterable
    {
        yield 'not a string' => [LowerCase(1)];
        yield 'negative repeat' => [Repeat('a', -1)];
        yield 'empty token' => [SplitStr('abc', '')];
        yield 'unknown normalizer' => [Casefold('a', 'NFX')];
        yield 'count of zero' => [SplitStr('a,b', ',', 0)];
        // PHP would run out of memory, a fatal error no query could catch.
        yield 'a repeat too long' => [Repeat('abc', PHP_INT_MAX)];
        yield 'too many spaces' => [Space(PHP_INT_MAX)];
    }

    #[DataProvider('invalidStringCalls')]
    public function testStringFunctionsCheckTheirArguments(mixed $query): void
    {
        $this->expectException(InvalidArgument::class);

        $this->client->query($query);
    }

    public function testFormat(): void
    {
        self::assertSame('Ada is 36 years old', $this->client->query(Format('%s is %d years old', 'Ada', 36)));
        self::assertSame('Ada Lovelace', $this->client->query(Format('%2$s %1$s', 'Lovelace', 'Ada')));
        self::assertSame('  3.1|ab   |00042|ff|%|true', $this->client->query(Format('%5.1f|%-5s|%05d|%x|%%|%b', 3.14159, 'ab', 42, 255, true)));
        self::assertSame("ADA\nLov", $this->client->query(Format('%S%n%.3s', 'ada', 'Lovelace')));
        self::assertSame('1.0 and 2026-10-11T12:00:00.000000Z', $this->client->query(Format('%s and %s', 1.0, Timestamp('2026-10-11T12:00:00Z'))));
        self::assertSame('{"@ref":{"collection":"users","id":"1"}} [1,"a"]', $this->client->query(Format('%@ %@', Ref(Collection('users'), '1'), [1, 'a'])));
        self::assertSame('  été', $this->client->query(Format('%5s', 'été')));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidFormats(): iterable
    {
        yield 'missing value' => [Format('%s and %s', 'a')];
        yield 'not an integer' => [Format('%d', '1')];
        yield 'unknown conversion' => [Format('%q', 1)];
        yield 'a list as a string' => [Format('%s', [1])];
        yield 'a width too large' => [Format('%999999999s', 'a')];
        yield 'too many digits' => [Format('%.99f', 1.5)];
    }

    #[DataProvider('invalidFormats')]
    public function testFormatChecksItsValues(mixed $query): void
    {
        $this->expectException(InvalidArgument::class);

        $this->client->query($query);
    }

    public function testTypeChecks(): void
    {
        $checks = [
            'Array' => IsArray(...),
            'Boolean' => IsBoolean(...),
            'Collection' => IsCollection(...),
            'Doc' => IsDoc(...),
            'Double' => IsDouble(...),
            'Index' => IsIndex(...),
            'Integer' => IsInteger(...),
            'Lambda' => IsLambda(...),
            'Null' => IsNull(...),
            'Number' => IsNumber(...),
            'Object' => IsObject(...),
            'Ref' => IsRef(...),
            'SetRef' => IsSetRef(...),
            'String' => IsString(...),
            'Timestamp' => IsTimestamp(...),
        ];
        $ada = $this->client->query(Create(Collection('users'), ['data' => ['name' => 'Ada']]));

        foreach ([
            'a string' => ['a', ['String']],
            'an integer' => [1, ['Integer', 'Number']],
            'a double' => [1.5, ['Double', 'Number']],
            'a boolean' => [false, ['Boolean']],
            'null' => [null, ['Null']],
            'a list' => [[1, 2], ['Array']],
            'an object' => [['a' => 1], ['Object']],
            'an empty array' => [[], ['Array', 'Object']],
            'a document ref' => [$ada['ref'], ['Ref']],
            'a collection' => [Collection('users'), ['Collection', 'Ref']],
            'an index' => [Index('users_by_name'), ['Index', 'Ref']],
            'an instant' => [Timestamp('2026-10-11T12:00:00Z'), ['Timestamp']],
            'a lambda' => [Lambda('x', Variable('x')), ['Lambda']],
            'a set' => [MatchIndex(Index('users_by_name'), 'Ada'), ['SetRef']],
            'a document' => [Get($ada['ref']), ['Doc', 'Object']],
        ] as $what => [$value, $types]) {
            $results = $this->client->query(array_map(static fn (callable $check): mixed => $check($value), $checks));

            self::assertSame($types, array_keys(array_filter($results)), $what);
        }
    }

    public function testConversions(): void
    {
        self::assertSame(['1', '1.5', '1.0', 'true', 'null', '2026-10-11T12:00:00.000000Z', 'a'], $this->client->query([
            ToString(1),
            ToString(1.5),
            ToString(1.0),
            ToString(true),
            ToString(null),
            ToString(Timestamp('2026-10-11T12:00:00Z')),
            ToString('a'),
        ]));
        self::assertSame([42, -4.5, 1000.0, 7, 0.5], $this->client->query([ToNumber('42'), ToNumber('-4.5'), ToNumber('1e3'), ToNumber(7), ToNumber('.5')]));
        self::assertSame([9.223372036854776E+18, 7, -7, 0], $this->client->query([ToNumber('9223372036854775808'), ToNumber('007'), ToNumber('-07'), ToNumber('-0')]));
        self::assertSame([4, -4, 12, 12], $this->client->query([ToInteger(4.9), ToInteger(-4.9), ToInteger('12'), ToInteger('12.7')]));
        self::assertSame([1.0, 2.0], $this->client->query([ToDouble(1), ToDouble('2')]));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidConversions(): iterable
    {
        yield 'letters' => [ToNumber('12a')];
        yield 'empty string' => [ToNumber('')];
        yield 'a boolean to a number' => [ToNumber(true)];
        yield 'a list to a string' => [ToString([1])];
        yield 'a double too large' => [ToInteger(1e30)];
        yield 'an infinite double' => [ToInteger(-INF)];
        yield 'a number beyond the doubles' => [ToNumber('1e400')];
    }

    #[DataProvider('invalidConversions')]
    public function testConversionsCheckTheirValues(mixed $query): void
    {
        $this->expectException(InvalidArgument::class);

        $this->client->query($query);
    }
}
