<?php declare(strict_types=1);

namespace Flora\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Flora\Exception\InvalidArgument;
use Flora\Value\Date;
use PHPUnit\Framework\Attributes\DataProvider;

use function Flora\{
    At,
    Between,
    Collection,
    Create,
    CreateCollection,
    CreateIndex,
    DayOfMonth,
    DayOfWeek,
    DayOfYear,
    Documents,
    Epoch,
    Equals,
    Filter,
    Format,
    Get,
    GT,
    Hour,
    Index,
    Insert,
    IsDate,
    IsTimestamp,
    Lambda,
    LT,
    MatchIndex,
    Minute,
    Month,
    Now,
    Paginate,
    Ref,
    Second,
    Select,
    Size,
    TimeAdd,
    TimeDiff,
    Timestamp,
    TimeSubtract,
    ToDate,
    ToMicros,
    ToString,
    ToTime,
    Update,
    Variable,
    Year,
};

abstract class TimeTestCase extends IntegrationTestCase
{

    protected function setUp(): void
    {
        parent::setUp();

        $this->client->query(CreateCollection(['name' => 'people']));
    }

    public function testNowIsTheTimeOfTheQuery(): void
    {
        [$now, $again, $created] = $this->client->query([Now(), Now(), Create(Collection('people'))]);

        self::assertInstanceOf(DateTimeImmutable::class, $now);
        self::assertSame('UTC', $now->getTimezone()->getName());
        self::assertEquals($now, $again);
        self::assertSame($created['ts'], (int) $now->format('U') * 1_000_000 + (int) $now->format('u'));
    }

    public function testTimestampReadsIsoDates(): void
    {
        $time = $this->client->query(Timestamp('2026-10-11T14:30:00.123456+02:00'));

        self::assertSame('2026-10-11T12:30:00.123456+00:00', $time->format('Y-m-d\\TH:i:s.uP'));
        self::assertEquals($this->client->query(Timestamp('2026-10-11T12:30:00.123456789Z')), $time);
        self::assertTrue($this->client->query(Equals(Timestamp('2026-10-11T12:00:00Z'), Timestamp('2026-10-11T13:00:00+01:00'))));
    }

    public function testTimestampNowIsNow(): void
    {
        [$now, $timestamp] = $this->client->query([Now(), Timestamp('now')]);

        self::assertEquals($now, $timestamp);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidTimes(): iterable
    {
        yield 'no time' => ['2026-10-11'];
        yield 'no offset' => ['2026-10-11T12:00:00'];
        yield 'impossible date' => ['2026-02-30T12:00:00Z'];
        yield 'words' => ['yesterday'];
    }

    #[DataProvider('invalidTimes')]
    public function testTimestampRefusesOtherTexts(string $time): void
    {
        $this->expectException(InvalidArgument::class);

        $this->client->query(Timestamp($time));
    }

    public function testDocumentsStoreInstants(): void
    {
        $born = new DateTimeImmutable('1815-12-10T08:00:00', new DateTimeZone('Europe/London'));
        $ada = $this->client->query(Create(Collection('people'), ['data' => ['born' => $born, 'seen' => Timestamp('2026-10-11T12:00:00Z')]]));

        $read = $this->client->query(Get($ada['ref']))['data'];

        self::assertInstanceOf(DateTimeImmutable::class, $read['born']);
        self::assertEquals($born, $read['born']);
        self::assertEquals(new DateTimeImmutable('2026-10-11T12:00:00Z'), $read['seen']);
    }

    public function testIndexesSortAndMatchInstants(): void
    {
        $this->client->query(CreateIndex(['name' => 'births', 'source' => Collection('people'), 'values' => [['field' => ['data', 'born']]]]));
        $this->client->query(CreateIndex(['name' => 'people_by_birth', 'source' => Collection('people'), 'terms' => [['field' => ['data', 'born']]]]));

        $this->client->query([
            Create(Collection('people'), ['data' => ['born' => Timestamp('1906-12-09T00:00:00Z')]]),
            Create(Collection('people'), ['data' => ['born' => Timestamp('1815-12-10T00:00:00Z')]]),
            Create(Collection('people'), ['data' => ['born' => Timestamp('1969-12-28T12:00:00+05:00')]]),
            Create(Collection('people'), ['data' => ['born' => Timestamp('1912-06-23T00:00:00Z')]]),
        ]);

        $births = array_map(static fn (DateTimeImmutable $time): string => $time->format('Y-m-d'), $this->client->query(Paginate(MatchIndex(Index('births'))))->data);
        self::assertSame(['1815-12-10', '1906-12-09', '1912-06-23', '1969-12-28'], $births);

        self::assertCount(1, $this->client->query(Paginate(MatchIndex(Index('people_by_birth'), Timestamp('1969-12-28T07:00:00Z'))))->data);
    }

    public function testReadingThePastAtAnInstant(): void
    {
        $ada = $this->client->query(Create(Collection('people'), ['data' => ['name' => 'Ada']]));
        $this->client->query(Update($ada['ref'], ['data' => ['name' => 'Ada Lovelace']]));
        $then = (DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%06d', intdiv($ada['ts'], 1_000_000), $ada['ts'] % 1_000_000)) ?: self::fail('Not a time.'))
            ->format('Y-m-d\\TH:i:s.u\\Z');

        self::assertSame('Ada', $this->client->query(At(Timestamp($then), Get($ada['ref'])))['data']['name']);
    }

    public function testToMicros(): void
    {
        self::assertSame(1_000_002, $this->client->query(ToMicros(Timestamp('1970-01-01T00:00:01.000002Z'))));
        self::assertSame(-1_000_000, $this->client->query(ToMicros(Timestamp('1969-12-31T23:59:59Z'))));

        [$micros, $created] = $this->client->query([ToMicros(Now()), Create(Collection('people'))]);
        self::assertSame($created['ts'], $micros);
    }

    public function testEpoch(): void
    {
        self::assertEquals(new DateTimeImmutable('1970-01-01T00:00:01.000002Z'), $this->client->query(Epoch(1_000_002, 'microseconds')));
        self::assertEquals(new DateTimeImmutable('1970-01-01T00:00:01.5Z'), $this->client->query(Epoch(1_500, 'milliseconds')));
        self::assertEquals(new DateTimeImmutable('2026-10-11T00:00:00Z'), $this->client->query(Epoch(20_737, 'days')));
        self::assertEquals(new DateTimeImmutable('1970-01-01T00:00:00.000001Z'), $this->client->query(Epoch(1_999, 'nanoseconds')));
        self::assertEquals(new DateTimeImmutable('1969-12-31T23:00:00Z'), $this->client->query(Epoch(-1, 'hour')));
    }

    public function testTimeAdd(): void
    {
        $time = Timestamp('2026-01-31T12:00:00Z');

        self::assertEquals(new DateTimeImmutable('2026-02-01T12:00:00Z'), $this->client->query(TimeAdd($time, 1, 'day')));
        self::assertEquals(new DateTimeImmutable('2026-01-31T10:30:00Z'), $this->client->query(TimeAdd($time, -90, 'minutes')));
        self::assertEquals(new DateTimeImmutable('2026-02-01T00:00:00Z'), $this->client->query(TimeAdd($time, 1, 'half day')));
        self::assertEquals(new DateTimeImmutable('2026-01-31T12:00:00.25Z'), $this->client->query(TimeAdd($time, 250, 'milliseconds')));
    }

    public function testTimeSubtract(): void
    {
        $time = Timestamp('2026-03-01T00:00:00Z');

        self::assertEquals(new DateTimeImmutable('2026-02-28T00:00:00Z'), $this->client->query(TimeSubtract($time, 1, 'day')));
        self::assertEquals(new DateTimeImmutable('2026-03-01T01:30:00Z'), $this->client->query(TimeSubtract($time, -90, 'minutes')));
    }

    public function testTimeDiff(): void
    {
        $start = Timestamp('2026-01-01T00:00:00Z');
        $finish = Timestamp('2026-01-02T12:00:00Z');

        self::assertSame(1, $this->client->query(TimeDiff($start, $finish, 'days')));
        self::assertSame(3, $this->client->query(TimeDiff($start, $finish, 'half days')));
        self::assertSame(36, $this->client->query(TimeDiff($start, $finish, 'hours')));
        self::assertSame(-1, $this->client->query(TimeDiff($finish, $start, 'days')));
        // Instants keep microseconds: 1.5 microseconds are 1, so 1,000 nanoseconds.
        self::assertSame(1_000, $this->client->query(TimeDiff($start, Timestamp('2026-01-01T00:00:00.0000015Z'), 'nanoseconds')));
    }

    public function testTimeDiffTakesInstants(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('TimeDiff() times');

        $this->client->query(TimeDiff(Now(), 1, 'days'));
    }

    public function testDocumentsWrittenInTheLastHour(): void
    {
        $this->client->query(Create(Ref(Collection('people'), '1')));
        $this->client->query(Insert(Ref(Collection('people'), '2'), ToMicros(TimeAdd(Now(), -2, 'hours')), 'create'));

        $recent = Filter(Documents(Collection('people')), Lambda('ref', GT(Select('ts', Get(Variable('ref'))), ToMicros(TimeAdd(Now(), -1, 'hour')))));

        self::assertSame(1, $this->client->query(Size($recent)));
    }

    public function testUnitsOfTime(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('TimeAdd() unit must be one of days');

        $this->client->query(TimeAdd(Now(), 1, 'month'));
    }

    public function testAmountsOfTimeAreIntegers(): void
    {
        $this->expectException(InvalidArgument::class);

        $this->client->query(Epoch(1.5, 'seconds'));
    }

    public function testToMicrosTakesAnInstant(): void
    {
        $this->expectException(InvalidArgument::class);

        $this->client->query(ToMicros('2026-10-11T12:00:00Z'));
    }

    public function testToDate(): void
    {
        $date = $this->client->query(ToDate('2026-10-11'));

        self::assertInstanceOf(Date::class, $date);
        self::assertSame('2026-10-11', $date->value);
        // The day of an instant, in UTC.
        self::assertEquals(new Date('2026-10-12'), $this->client->query(ToDate(Timestamp('2026-10-11T23:30:00-02:00'))));
        self::assertEquals(Date::of(new DateTimeImmutable('now', new DateTimeZone('UTC'))), $this->client->query(ToDate(Now())));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidDates(): iterable
    {
        yield 'impossible date' => ['2026-02-30'];
        yield 'other format' => ['11/10/2026'];
        yield 'an instant as text' => ['2026-10-11T12:00:00Z'];
        yield 'a number' => [20261011];
    }

    #[DataProvider('invalidDates')]
    public function testToDateRefusesOtherValues(mixed $value): void
    {
        $this->expectException(InvalidArgument::class);

        $this->client->query(ToDate($value));
    }

    public function testToTime(): void
    {
        self::assertEquals(new DateTimeImmutable('2026-10-11T00:00:00Z'), $this->client->query(ToTime(ToDate('2026-10-11'))));
        self::assertEquals(new DateTimeImmutable('2026-10-11T12:00:00Z'), $this->client->query(ToTime('2026-10-11T14:00:00+02:00')));
    }

    public function testPartsOfInstantsAndDates(): void
    {
        $time = Timestamp('2026-10-11T14:05:09+00:00');
        $parts = static fn (mixed $value): array => [
            DayOfMonth($value), DayOfWeek($value), DayOfYear($value), Month($value), Year($value), Hour($value), Minute($value), Second($value),
        ];

        // A Sunday.
        self::assertSame([11, 7, 284, 10, 2026, 14, 5, 9], $this->client->query($parts($time)));
        // A Tuesday, the last day of a leap year, at midnight.
        self::assertSame([31, 2, 366, 12, 2024, 0, 0, 0], $this->client->query($parts(ToDate('2024-12-31'))));
        self::assertSame(12, $this->client->query(Hour(Timestamp('2026-10-11T14:00:00+02:00'))));
    }

    public function testDatesInDocumentsAndIndexes(): void
    {
        $this->client->query(CreateIndex(['name' => 'birthdays', 'source' => Collection('people'), 'values' => [['field' => ['data', 'birthday']]]]));
        $this->client->query(CreateIndex(['name' => 'people_by_birthday', 'source' => Collection('people'), 'terms' => [['field' => ['data', 'birthday']]]]));
        $ada = $this->client->query(Create(Collection('people'), ['data' => ['birthday' => new Date('1815-12-10')]]));
        $this->client->query([
            Create(Collection('people'), ['data' => ['birthday' => ToDate('1906-12-09')]]),
            Create(Collection('people'), ['data' => ['birthday' => ToDate('1969-12-28')]]),
            Create(Collection('people'), ['data' => ['birthday' => ToDate(Timestamp('1912-06-23T10:00:00Z'))]]),
        ]);

        self::assertEquals(new Date('1815-12-10'), $this->client->query(Get($ada['ref']))['data']['birthday']);

        $birthdays = static fn (array $dates): array => array_map(static fn (Date $date): string => $date->value, $dates);
        self::assertSame(['1815-12-10', '1906-12-09', '1912-06-23', '1969-12-28'], $birthdays($this->client->query(Paginate(MatchIndex(Index('birthdays'))))->data));
        self::assertSame(['1906-12-09', '1912-06-23'], $birthdays($this->client->query(Paginate(Between(MatchIndex(Index('birthdays')), ToDate('1900-01-01'), ToDate('1950-01-01'))))->data));
        self::assertCount(1, $this->client->query(Paginate(MatchIndex(Index('people_by_birthday'), ToDate('1969-12-28'))))->data);
    }

    public function testDateArithmetic(): void
    {
        self::assertEquals(new Date('2026-03-01'), $this->client->query(TimeAdd(ToDate('2026-02-28'), 1, 'day')));
        self::assertEquals(new Date('2026-02-28'), $this->client->query(TimeSubtract(ToDate('2026-03-01'), 1, 'days')));
        self::assertSame(283, $this->client->query(TimeDiff(ToDate('2026-01-01'), ToDate('2026-10-11'), 'days')));
        self::assertSame(-283, $this->client->query(TimeDiff(ToDate('2026-10-11'), ToDate('2026-01-01'), 'days')));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidDateArithmetic(): iterable
    {
        yield 'a date moved by hours' => [TimeAdd(ToDate('2026-10-11'), 1, 'hour')];
        yield 'dates counted in hours' => [TimeDiff(ToDate('2026-10-11'), ToDate('2026-10-12'), 'hours')];
        yield 'a date and an instant' => [TimeDiff(ToDate('2026-10-11'), Timestamp('2026-10-12T00:00:00Z'), 'days')];
        yield 'a date compared to an instant' => [LT(ToDate('2026-10-11'), Now())];
    }

    #[DataProvider('invalidDateArithmetic')]
    public function testDatesAreNotInstants(mixed $query): void
    {
        $this->expectException(InvalidArgument::class);

        $this->client->query($query);
    }

    public function testComparingAndConvertingDates(): void
    {
        self::assertSame(
            [true, true, '2026-10-11', 'Born on 2026-10-11', true, false, false],
            $this->client->query([
                LT(ToDate('2026-01-01'), ToDate('2026-10-11'), ToDate('2027-01-01')),
                Equals(ToDate('2026-10-11'), ToDate(Timestamp('2026-10-11T12:00:00Z'))),
                ToString(ToDate('2026-10-11')),
                Format('Born on %s', ToDate('2026-10-11')),
                IsDate(ToDate('2026-10-11')),
                IsDate(Now()),
                IsTimestamp(ToDate('2026-10-11')),
            ]),
        );
    }

    public function testComparingInstants(): void
    {
        self::assertTrue($this->client->query(LT(Timestamp('2026-01-01T00:00:00Z'), Timestamp('2026-06-01T00:00:00Z'), Now())));
        self::assertTrue($this->client->query(GT(Now(), Timestamp('1815-12-10T00:00:00Z'))));

        $this->expectException(InvalidArgument::class);
        $this->client->query(LT(Now(), 1));
    }
}
