<?php declare(strict_types=1);

/**
 * The query language, modelled on FaunaDB's FQL:
 *
 *     use function Flora\{Collection, Get, Ref};
 *
 *     $client->query(Get(Ref(Collection('users'), '9203420348023942')));
 *
 * FQL functions whose name is a reserved word in PHP are renamed: Match is MatchIndex, Var is Variable, If is IfElse,
 * And is All, Or is Any, Foreach is Each. So are those named like a function of PHP, which they would take the place
 * of where they are imported: Count is Size, Max is Greatest, Min is Least, Abs is Absolute, Time is Timestamp, Date is
 * ToDate, Range is Between, Trim, LTrim and RTrim are TrimStr, LTrimStr and RTrimStr, and IsSet, Function and Do, words
 * of PHP, are IsSetRef, FunctionRef and Sequence.
 */

namespace Flora;

// References

function Collection(mixed $name): Expr\Collection
{
    return new Expr\Collection($name);
}

function Index(mixed $name): Expr\Index
{
    return new Expr\Index($name);
}

function Ref(mixed $collection, mixed $id): Expr\Ref
{
    return new Expr\Ref($collection, $id);
}

// Sets

function Documents(mixed $collection): Expr\Documents
{
    return new Expr\Documents($collection);
}

function MatchIndex(mixed $index, mixed ...$terms): Expr\MatchIndex
{
    return new Expr\MatchIndex($index, ...$terms);
}

/**
 * The entries of the detail index whose terms equal an entry of the source set: its ref when the source index has no
 * values, else its values, one per term.
 */
function Join(mixed $source, mixed $detail): Expr\Join
{
    return new Expr\Join($source, $detail);
}

/**
 * The entries of any of the sets (or the items of any of the lists), each once.
 */
function Union(mixed ...$sets): Expr\Union
{
    return new Expr\Union(...$sets);
}

/**
 * The entries found in all the sets (or the items of the first list found in all the others).
 */
function Intersection(mixed ...$sets): Expr\Intersection
{
    return new Expr\Intersection(...$sets);
}

/**
 * The entries of the first set found in none of the others (or the items of the first list found in none of the others).
 */
function Difference(mixed ...$sets): Expr\Difference
{
    return new Expr\Difference(...$sets);
}

/**
 * The set of one document, while it exists.
 */
function Singleton(mixed $ref): Expr\Singleton
{
    return new Expr\Singleton($ref);
}

/**
 * The entries of a set between two bounds, both included (FQL's Range, which would take the place of PHP's range()
 * where it is imported): a value, or a list of values then a ref, compared in the order of the index. An empty list
 * does not bound the entries.
 */
function Between(mixed $set, mixed $from, mixed $to): Expr\Between
{
    return new Expr\Between($set, $from, $to);
}

/**
 * @param array{size?: int, after?: list<mixed>|null, before?: list<mixed>|null}|Expr\Expr $options
 */
function Paginate(mixed $set, mixed $options = []): Expr\Paginate
{
    return new Expr\Paginate($set, $options);
}

// Reads

/**
 * @param mixed $ts the time to read the document at, like At() does
 */
function Get(mixed $ref, mixed $ts = null): Expr\Get
{
    return new Expr\Get($ref, $ts);
}

function Exists(mixed $ref): Expr\Exists
{
    return new Expr\Exists($ref);
}

// Time

/**
 * The time of the query, the same everywhere in it, as an instant in UTC.
 */
function Now(): Expr\Now
{
    return new Expr\Now();
}

/**
 * The instant an ISO 8601 date and time stands for, in UTC (FQL's Time, which would take the place of PHP's time()
 * where it is imported): Timestamp('2026-10-11T12:00:00Z').
 */
function Timestamp(mixed $time): Expr\Timestamp
{
    return new Expr\Timestamp($time);
}

/**
 * The microseconds since 1970-01-01T00:00:00Z of an instant: what the ts of documents count.
 */
function ToMicros(mixed $time): Expr\ToMicros
{
    return new Expr\ToMicros($time);
}

/**
 * The instant a number of units of time (days, hours, minutes, seconds, milliseconds, microseconds...) after
 * 1970-01-01T00:00:00Z.
 */
function Epoch(mixed $amount, mixed $unit): Expr\Epoch
{
    return new Expr\Epoch($amount, $unit);
}

/**
 * An instant moved by a number of units of time (days, hours, minutes, seconds...), which can be negative.
 */
function TimeAdd(mixed $time, mixed $offset, mixed $unit): Expr\TimeAdd
{
    return new Expr\TimeAdd($time, $offset, $unit);
}

/**
 * An instant moved back by a number of units of time (days, hours, minutes, seconds...).
 */
function TimeSubtract(mixed $time, mixed $offset, mixed $unit): Expr\TimeSubtract
{
    return new Expr\TimeSubtract($time, $offset, $unit);
}

/**
 * How many whole units of time (days, hours, minutes, seconds...) go from an instant to another, truncated toward
 * zero.
 */
function TimeDiff(mixed $start, mixed $finish, mixed $unit): Expr\TimeDiff
{
    return new Expr\TimeDiff($start, $finish, $unit);
}

/**
 * A date (FQL's Date, which would take the place of PHP's date() where it is imported): of a string like 2026-10-11, or
 * the day of an instant in UTC.
 */
function ToDate(mixed $value): Expr\Conversion
{
    return new Expr\Conversion('Date', $value);
}

/**
 * An instant: the start of a date in UTC, or what Timestamp() reads in a string.
 */
function ToTime(mixed $value): Expr\Conversion
{
    return new Expr\Conversion('Time', $value);
}

/**
 * The day of the month of an instant or a date, in UTC: 1 to 31.
 */
function DayOfMonth(mixed $time): Expr\DatePart
{
    return new Expr\DatePart('DayOfMonth', $time);
}

/**
 * The day of the week of an instant or a date, in UTC: 1 is Monday, 7 Sunday.
 */
function DayOfWeek(mixed $time): Expr\DatePart
{
    return new Expr\DatePart('DayOfWeek', $time);
}

/**
 * The day of the year of an instant or a date, in UTC: 1 to 366.
 */
function DayOfYear(mixed $time): Expr\DatePart
{
    return new Expr\DatePart('DayOfYear', $time);
}

/**
 * The month of an instant or a date, in UTC: 1 to 12.
 */
function Month(mixed $time): Expr\DatePart
{
    return new Expr\DatePart('Month', $time);
}

/**
 * The year of an instant or a date, in UTC.
 */
function Year(mixed $time): Expr\DatePart
{
    return new Expr\DatePart('Year', $time);
}

/**
 * The hour of an instant, in UTC: 0 to 23, and 0 for a date.
 */
function Hour(mixed $time): Expr\DatePart
{
    return new Expr\DatePart('Hour', $time);
}

/**
 * The minute of an instant: 0 to 59, and 0 for a date.
 */
function Minute(mixed $time): Expr\DatePart
{
    return new Expr\DatePart('Minute', $time);
}

/**
 * The second of an instant: 0 to 59, and 0 for a date.
 */
function Second(mixed $time): Expr\DatePart
{
    return new Expr\DatePart('Second', $time);
}

// History

/**
 * Evaluates the expression as the database was at a time, in microseconds like the ts of documents.
 */
function At(mixed $ts, mixed $expr): Expr\At
{
    return new Expr\At($ts, $expr);
}

/**
 * The history of a document (an event per write) or of a set (an event per entry added or removed), read like a set,
 * by time.
 */
function Events(mixed $subject): Expr\Events
{
    return new Expr\Events($subject);
}

/**
 * Adds an event to the history of a document (an action and the whole document after it), replacing the one at that
 * time: the document becomes what its last event says.
 *
 * @param array{data?: array<string, mixed>}|Expr\Expr $params
 */
function Insert(mixed $ref, mixed $ts, mixed $action, mixed $params = []): Expr\Insert
{
    return new Expr\Insert($ref, $ts, $action, $params);
}

/**
 * Removes an event from the history of a document: the document becomes what its last event says.
 */
function Remove(mixed $ref, mixed $ts, mixed $action): Expr\Remove
{
    return new Expr\Remove($ref, $ts, $action);
}

// Writes

/**
 * @param array{data?: array<string, mixed>}|Expr\Expr $params
 */
function Create(mixed $collectionOrRef, mixed $params = []): Expr\Create
{
    return new Expr\Create($collectionOrRef, $params);
}

/**
 * Merges data into a document; changes the settings of a collection (history_days), renames an index or makes it unique
 * or not (name, unique), or renames a function or changes its body (name, body).
 *
 * @param array{data?: array<string, mixed>}|array{history_days?: int|null}|array{name?: string, unique?: bool}|Expr\Expr $params
 */
function Update(mixed $ref, mixed $params): Expr\Update
{
    return new Expr\Update($ref, $params);
}

/**
 * @param array{data?: array<string, mixed>}|Expr\Expr $params
 */
function Replace(mixed $ref, mixed $params): Expr\Replace
{
    return new Expr\Replace($ref, $params);
}

/**
 * Deletes a document, an index, a function, or a collection with its documents, their history and its indexes.
 */
function Delete(mixed $ref): Expr\Delete
{
    return new Expr\Delete($ref);
}

// Schema

/**
 * @param array{name: string}|Expr\Expr $params
 */
function CreateCollection(mixed $params): Expr\CreateCollection
{
    return new Expr\CreateCollection($params);
}

/**
 * @param array{name: string, source: mixed, terms?: list<array{field: string|list<string|int>, unroll?: bool}>, values?: list<array{field: string|list<string|int>, reverse?: bool, unroll?: bool}>, unique?: bool}|Expr\Expr $params
 */
function CreateIndex(mixed $params): Expr\CreateIndex
{
    return new Expr\CreateIndex($params);
}

// Functional

/**
 * @param string|list<string> $params
 */
function Lambda(string|array $params, mixed $body): Expr\Lambda
{
    return new Expr\Lambda($params, $body);
}

function Variable(string $name): Expr\Variable
{
    return new Expr\Variable($name);
}

/**
 * @param array<string, mixed> $bindings
 */
function Let(array $bindings, mixed $in): Expr\Let
{
    return new Expr\Let($bindings, $in);
}

function Map(mixed $collection, mixed $lambda): Expr\Map
{
    return new Expr\Map($collection, $lambda);
}

/**
 * @param string|int|list<string|int>|Expr\Expr $path
 */
function Select(mixed $path, mixed $from, mixed ...$default): Expr\Select
{
    return new Expr\Select($path, $from, array_values($default)[0] ?? null, $default !== []);
}

/**
 * All the values at a path, through lists: a field is read in each item of a list it meets.
 */
function SelectAll(mixed $path, mixed $from): Expr\SelectAll
{
    return new Expr\SelectAll($path, $from);
}

function IfElse(mixed $condition, mixed $then, mixed $else): Expr\IfElse
{
    return new Expr\IfElse($condition, $then, $else);
}

function Equals(mixed ...$values): Expr\Equals
{
    return new Expr\Equals(...$values);
}

function LT(mixed ...$values): Expr\Comparison
{
    return new Expr\Comparison('<', ...$values);
}

function LTE(mixed ...$values): Expr\Comparison
{
    return new Expr\Comparison('<=', ...$values);
}

function GT(mixed ...$values): Expr\Comparison
{
    return new Expr\Comparison('>', ...$values);
}

function GTE(mixed ...$values): Expr\Comparison
{
    return new Expr\Comparison('>=', ...$values);
}

function Not(mixed $value): Expr\Not
{
    return new Expr\Not($value);
}

/**
 * Whether all the conditions are true (FQL's And, a reserved word in PHP).
 */
function All(mixed ...$conditions): Expr\Logical
{
    return new Expr\Logical(true, ...$conditions);
}

/**
 * Whether any of the conditions is true (FQL's Or, a reserved word in PHP).
 */
function Any(mixed ...$conditions): Expr\Logical
{
    return new Expr\Logical(false, ...$conditions);
}

/**
 * Runs the lambda on each item of a list or a page, for what it does, and returns the list or the page (FQL's Foreach).
 */
function Each(mixed $collection, mixed $lambda): Expr\Each
{
    return new Expr\Each($collection, $lambda);
}

/**
 * Folds a list, a page or a set into a value: the reducer receives the value so far and an item.
 */
function Reduce(mixed $reducer, mixed $initial, mixed $collection): Expr\Reduce
{
    return new Expr\Reduce($reducer, $initial, $collection);
}

// Lists and strings

/**
 * The base list followed by the elements: Append([4, 5], [1, 2, 3]) is [1, 2, 3, 4, 5].
 */
function Append(mixed $elements, mixed $base): Expr\Append
{
    return new Expr\Append($elements, $base);
}

/**
 * The elements followed by the base list: Prepend([1, 2], [3, 4]) is [1, 2, 3, 4].
 */
function Prepend(mixed $elements, mixed $base): Expr\Prepend
{
    return new Expr\Prepend($elements, $base);
}

/**
 * The strings of a list joined into one, with a separator between them.
 */
function Concat(mixed $strings, mixed $separator = ''): Expr\Concat
{
    return new Expr\Concat($strings, $separator);
}

/**
 * The first items of a list or a page: Take(2, [1, 2, 3]) is [1, 2].
 */
function Take(mixed $count, mixed $collection): Expr\Take
{
    return new Expr\Take($count, $collection);
}

/**
 * The items of a list or a page but the first ones: Drop(2, [1, 2, 3]) is [3].
 */
function Drop(mixed $count, mixed $collection): Expr\Drop
{
    return new Expr\Drop($count, $collection);
}

/**
 * The items of a list or a page in reverse order, or the entries of a set, read backward through its index.
 */
function Reverse(mixed $collection): Expr\Reverse
{
    return new Expr\Reverse($collection);
}

/**
 * The items of a list or a page without the repeated ones, or one entry per distinct values of a set.
 */
function Distinct(mixed $collection): Expr\Distinct
{
    return new Expr\Distinct($collection);
}

/**
 * Whether a list, a page or a set is empty.
 */
function IsEmpty(mixed $collection): Expr\IsEmpty
{
    return new Expr\IsEmpty($collection);
}

/**
 * Whether a list, a page or a set has items.
 */
function IsNonEmpty(mixed $collection): Expr\IsEmpty
{
    return new Expr\IsEmpty($collection, empty: false);
}

/**
 * The sum of the numbers of a list, a page or a set.
 */
function Sum(mixed $collection): Expr\Sum
{
    return new Expr\Sum($collection);
}

/**
 * The mean of the numbers of a list, a page or a set.
 */
function Mean(mixed $collection): Expr\Sum
{
    return new Expr\Sum($collection, mean: true);
}

/**
 * Whether a value is an item of a list, a page or a set, or a value of an object.
 */
function ContainsValue(mixed $value, mixed $in): Expr\ContainsValue
{
    return new Expr\ContainsValue($value, $in);
}

// Objects

/**
 * Whether there is a value at a path, as Select() reads it.
 */
function ContainsPath(mixed $path, mixed $in): Expr\ContainsPath
{
    return new Expr\ContainsPath($path, $in);
}

/**
 * Whether an object has a field.
 */
function ContainsField(mixed $field, mixed $in): Expr\ContainsPath
{
    return new Expr\ContainsPath($field, $in, field: true);
}

/**
 * An object with the fields of others, one level deep; a null value removes the field, and a resolver,
 * Lambda(['field', 'left', 'right'], ...), gives the value of a field both have.
 */
function Merge(mixed $object, mixed $values, mixed $resolver = null): Expr\Merge
{
    return new Expr\Merge($object, $values, $resolver);
}

/**
 * The object of a list of [field, value] pairs.
 */
function ToObject(mixed $pairs): Expr\ToObject
{
    return new Expr\ToObject($pairs);
}

/**
 * The [field, value] pairs of an object.
 */
function ToArray(mixed $object): Expr\ToArray
{
    return new Expr\ToArray($object);
}

// Strings

/**
 * The string with its case folded, for comparisons, then normalized: NFKCCaseFold (the default), NFC, NFD, NFKC or
 * NFKD.
 */
function Casefold(mixed $value, mixed $normalizer = null): Expr\StringFunction
{
    return new Expr\StringFunction('Casefold', $value, $normalizer);
}

/**
 * Whether a string contains another.
 */
function ContainsStr(mixed $value, mixed $search): Expr\StringFunction
{
    return new Expr\StringFunction('ContainsStr', $value, $search);
}

/**
 * Whether a string matches a regular expression (PCRE, without delimiters).
 */
function ContainsStrRegex(mixed $value, mixed $pattern): Expr\StringFunction
{
    return new Expr\StringFunction('ContainsStrRegex', $value, $pattern);
}

/**
 * Whether a string ends with another.
 */
function EndsWith(mixed $value, mixed $search): Expr\StringFunction
{
    return new Expr\StringFunction('EndsWith', $value, $search);
}

/**
 * The position of the first occurrence of a string in another, from a position, or -1.
 */
function FindStr(mixed $value, mixed $find, mixed $start = null): Expr\StringFunction
{
    return new Expr\StringFunction('FindStr', $value, $find, $start);
}

/**
 * The matches of a regular expression, from a position: their start, end (included) and text, 1,024 at most unless
 * told otherwise.
 */
function FindStrRegex(mixed $value, mixed $pattern, mixed $start = null, mixed $numResults = null): Expr\StringFunction
{
    return new Expr\StringFunction('FindStrRegex', $value, $pattern, $start, $numResults);
}

/**
 * How many characters (code points) a string has.
 */
function Length(mixed $value): Expr\StringFunction
{
    return new Expr\StringFunction('Length', $value);
}

/**
 * The string in lower case.
 */
function LowerCase(mixed $value): Expr\StringFunction
{
    return new Expr\StringFunction('LowerCase', $value);
}

/**
 * The string without its leading white space (FQL's LTrim, which would take the place of PHP's ltrim() where it is
 * imported).
 */
function LTrimStr(mixed $value): Expr\StringFunction
{
    return new Expr\StringFunction('LTrimStr', $value);
}

/**
 * The regular expression matching a string as it is.
 */
function RegexEscape(mixed $value): Expr\StringFunction
{
    return new Expr\StringFunction('RegexEscape', $value);
}

/**
 * The string repeated, twice unless told otherwise.
 */
function Repeat(mixed $value, mixed $number = null): Expr\StringFunction
{
    return new Expr\StringFunction('Repeat', $value, $number);
}

/**
 * The string with every occurrence of a string replaced.
 */
function ReplaceStr(mixed $value, mixed $find, mixed $replace): Expr\StringFunction
{
    return new Expr\StringFunction('ReplaceStr', $value, $find, $replace);
}

/**
 * The string with the matches of a regular expression replaced ($1 is a group of the match), every one unless
 * first is true.
 */
function ReplaceStrRegex(mixed $value, mixed $pattern, mixed $replace, mixed $first = null): Expr\StringFunction
{
    return new Expr\StringFunction('ReplaceStrRegex', $value, $pattern, $replace, $first);
}

/**
 * The string without its trailing white space (FQL's RTrim, which would take the place of PHP's rtrim() where it is
 * imported).
 */
function RTrimStr(mixed $value): Expr\StringFunction
{
    return new Expr\StringFunction('RTrimStr', $value);
}

/**
 * A string of spaces.
 */
function Space(mixed $count): Expr\StringFunction
{
    return new Expr\StringFunction('Space', $count);
}

/**
 * The parts of a string between the occurrences of a token, count at most: the last one holds the rest.
 */
function SplitStr(mixed $value, mixed $token, mixed $count = null): Expr\StringFunction
{
    return new Expr\StringFunction('SplitStr', $value, $token, $count);
}

/**
 * The parts of a string between the matches of a regular expression, count at most: the last one holds the rest.
 */
function SplitStrRegex(mixed $value, mixed $pattern, mixed $count = null): Expr\StringFunction
{
    return new Expr\StringFunction('SplitStrRegex', $value, $pattern, $count);
}

/**
 * Whether a string starts with another.
 */
function StartsWith(mixed $value, mixed $search): Expr\StringFunction
{
    return new Expr\StringFunction('StartsWith', $value, $search);
}

/**
 * Part of a string, from a position (from the end when negative), to its end unless a length is given.
 */
function SubString(mixed $value, mixed $start = null, mixed $length = null): Expr\StringFunction
{
    return new Expr\StringFunction('SubString', $value, $start, $length);
}

/**
 * The string with each word capitalized.
 */
function TitleCase(mixed $value): Expr\StringFunction
{
    return new Expr\StringFunction('TitleCase', $value);
}

/**
 * The string without its leading and trailing white space (FQL's Trim, which would take the place of PHP's trim()
 * where it is imported).
 */
function TrimStr(mixed $value): Expr\StringFunction
{
    return new Expr\StringFunction('TrimStr', $value);
}

/**
 * The string in upper case.
 */
function UpperCase(mixed $value): Expr\StringFunction
{
    return new Expr\StringFunction('UpperCase', $value);
}

/**
 * A string built from a format and values, with Java's conversions: Format('%s is %d years old', 'Ada', 36).
 */
function Format(mixed $format, mixed ...$values): Expr\Format
{
    return new Expr\Format($format, ...$values);
}

// Types

/**
 * Whether a value is a list.
 */
function IsArray(mixed $value): Expr\TypeCheck
{
    return new Expr\TypeCheck('Array', $value);
}

/**
 * Whether a value is a boolean.
 */
function IsBoolean(mixed $value): Expr\TypeCheck
{
    return new Expr\TypeCheck('Boolean', $value);
}

/**
 * Whether a value is a collection ref, Collection().
 */
function IsCollection(mixed $value): Expr\TypeCheck
{
    return new Expr\TypeCheck('Collection', $value);
}

/**
 * Whether a value is a date, ToDate().
 */
function IsDate(mixed $value): Expr\TypeCheck
{
    return new Expr\TypeCheck('Date', $value);
}

/**
 * Whether a value is a document, as Get() returns it.
 */
function IsDoc(mixed $value): Expr\TypeCheck
{
    return new Expr\TypeCheck('Doc', $value);
}

/**
 * Whether a value is a double.
 */
function IsDouble(mixed $value): Expr\TypeCheck
{
    return new Expr\TypeCheck('Double', $value);
}

/**
 * Whether a value is a function ref, FunctionRef().
 */
function IsFunction(mixed $value): Expr\TypeCheck
{
    return new Expr\TypeCheck('Function', $value);
}

/**
 * Whether a value is an index ref, Index().
 */
function IsIndex(mixed $value): Expr\TypeCheck
{
    return new Expr\TypeCheck('Index', $value);
}

/**
 * Whether a value is an integer.
 */
function IsInteger(mixed $value): Expr\TypeCheck
{
    return new Expr\TypeCheck('Integer', $value);
}

/**
 * Whether a value is a Lambda().
 */
function IsLambda(mixed $value): Expr\TypeCheck
{
    return new Expr\TypeCheck('Lambda', $value);
}

/**
 * Whether a value is null.
 */
function IsNull(mixed $value): Expr\TypeCheck
{
    return new Expr\TypeCheck('Null', $value);
}

/**
 * Whether a value is a number.
 */
function IsNumber(mixed $value): Expr\TypeCheck
{
    return new Expr\TypeCheck('Number', $value);
}

/**
 * Whether a value is an object.
 */
function IsObject(mixed $value): Expr\TypeCheck
{
    return new Expr\TypeCheck('Object', $value);
}

/**
 * Whether a value is a ref: of a document, a collection, an index or a function.
 */
function IsRef(mixed $value): Expr\TypeCheck
{
    return new Expr\TypeCheck('Ref', $value);
}

/**
 * Whether a value is a set (FQL's IsSet: isset is a word of PHP).
 */
function IsSetRef(mixed $value): Expr\TypeCheck
{
    return new Expr\TypeCheck('SetRef', $value);
}

/**
 * Whether a value is a string.
 */
function IsString(mixed $value): Expr\TypeCheck
{
    return new Expr\TypeCheck('String', $value);
}

/**
 * Whether a value is an instant.
 */
function IsTimestamp(mixed $value): Expr\TypeCheck
{
    return new Expr\TypeCheck('Timestamp', $value);
}

/**
 * A string, a number, a boolean, null or an instant as a string.
 */
function ToString(mixed $value): Expr\Conversion
{
    return new Expr\Conversion('String', $value);
}

/**
 * A number, or the number a string writes.
 */
function ToNumber(mixed $value): Expr\Conversion
{
    return new Expr\Conversion('Number', $value);
}

/**
 * A number truncated to an integer, or the integer a string writes.
 */
function ToInteger(mixed $value): Expr\Conversion
{
    return new Expr\Conversion('Integer', $value);
}

/**
 * A number, or the number a string writes, as a double.
 */
function ToDouble(mixed $value): Expr\Conversion
{
    return new Expr\Conversion('Double', $value);
}

// Stored functions

/**
 * A reference to a stored function (FQL's Function, a word of PHP).
 */
function FunctionRef(mixed $name): Expr\FunctionRef
{
    return new Expr\FunctionRef($name);
}

/**
 * A Lambda() kept as it is written, rather than evaluated: the body of a stored function.
 */
function Query(mixed $lambda): Expr\Query
{
    return new Expr\Query($lambda);
}

/**
 * Stores a function: CreateFunction(['name' => 'adults', 'body' => Query(Lambda('age', ...))]).
 *
 * @param array{name: string, body: mixed}|Expr\Expr $params
 */
function CreateFunction(mixed $params): Expr\CreateFunction
{
    return new Expr\CreateFunction($params);
}

/**
 * Calls a stored function, by its ref or its name, with arguments.
 */
function Call(mixed $function, mixed ...$arguments): Expr\Call
{
    return new Expr\Call($function, ...$arguments);
}

// Schema

/**
 * The refs of the collections, by name.
 */
function Collections(): Expr\Schema
{
    return new Expr\Schema('collections');
}

/**
 * The refs of the indexes, by name.
 */
function Indexes(): Expr\Schema
{
    return new Expr\Schema('indexes');
}

/**
 * The refs of the stored functions, by name.
 */
function Functions(): Expr\Schema
{
    return new Expr\Schema('functions');
}

// Control

/**
 * Evaluates expressions in order and returns the value of the last one (FQL's Do, a word of PHP).
 */
function Sequence(mixed ...$exprs): Expr\Sequence
{
    return new Expr\Sequence(...$exprs);
}

/**
 * Stops the query with a message: its writes are rolled back, and the client throws Aborted.
 */
function Abort(mixed $message): Expr\Abort
{
    return new Expr\Abort($message);
}

/**
 * A new document id, as Create() would give.
 */
function NewId(): Expr\NewId
{
    return new Expr\NewId();
}

// Numbers

function Add(mixed ...$values): Expr\Arithmetic
{
    return new Expr\Arithmetic('+', ...$values);
}

function Subtract(mixed ...$values): Expr\Arithmetic
{
    return new Expr\Arithmetic('-', ...$values);
}

function Multiply(mixed ...$values): Expr\Arithmetic
{
    return new Expr\Arithmetic('*', ...$values);
}

/**
 * Dividing integers gives an integer, truncated.
 */
function Divide(mixed ...$values): Expr\Arithmetic
{
    return new Expr\Arithmetic('/', ...$values);
}

function Modulo(mixed ...$values): Expr\Arithmetic
{
    return new Expr\Arithmetic('%', ...$values);
}

/**
 * The greatest of the numbers (FQL's Max).
 */
function Greatest(mixed ...$values): Expr\Arithmetic
{
    return new Expr\Arithmetic('greatest', ...$values);
}

/**
 * The least of the numbers (FQL's Min).
 */
function Least(mixed ...$values): Expr\Arithmetic
{
    return new Expr\Arithmetic('least', ...$values);
}

/**
 * The absolute value of a number (FQL's Abs).
 */
function Absolute(mixed $value): Expr\Absolute
{
    return new Expr\Absolute($value);
}

/**
 * How many items a list or a page has, or how many entries a set has (FQL's Count, which would take the place of PHP's
 * count() where it is imported).
 */
function Size(mixed $collection): Expr\Size
{
    return new Expr\Size($collection);
}

/**
 * The items of a list or a page, or the entries of a set, for which the lambda returns true.
 */
function Filter(mixed $collection, mixed $lambda): Expr\Filter
{
    return new Expr\Filter($collection, $lambda);
}
