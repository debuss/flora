# Flora: guide for AI coding assistants

Flora (`debuss-a/flora`) stores JSON documents in PostgreSQL, MySQL, MariaDB or SQLite, and queries them with
functions modelled on FaunaDB's FQL v4. This guide is what you need to write correct Flora queries in a project using
it. The README of the package (`vendor/debuss-a/flora/README.md`) has the details.

## Rules that prevent most mistakes

1. **Import every function you use.** They live in the `Flora` namespace:
   `use function Flora\{Collection, Create, Get, Index, MatchIndex, Paginate};`
2. **Some FQL names are different**, because PHP reserves them or has a function of that name. Never write the FQL
   name:

   | FQL                  | Flora                            | FQL        | Flora         |
   |----------------------|----------------------------------|------------|---------------|
   | `Match`              | `MatchIndex`                     | `Count`    | `Size`        |
   | `Var`                | `Variable`                       | `Max`      | `Greatest`    |
   | `If`                 | `IfElse`                         | `Min`      | `Least`       |
   | `And` / `Or`         | `All` / `Any`                    | `Abs`      | `Absolute`    |
   | `Foreach`            | `Each`                           | `Time`     | `Timestamp`   |
   | `Do`                 | `Sequence`                       | `Date`     | `ToDate`      |
   | `Function`           | `FunctionRef`                    | `Range`    | `Between`     |
   | `IsSet`              | `IsSetRef`                       | `Trim`     | `TrimStr`     |
   | `LTrim` / `RTrim`    | `LTrimStr` / `RTrimStr`          |            |               |

   Authentication (`Login`, `Keys`, `Tokens`, roles) does not exist: the application handles it.
3. **Building an expression does nothing; `$client->query($expr)` runs it**, in one transaction: all its writes happen,
   or none. An array of expressions is evaluated item by item, in order, and returns an array of results.
4. **Searching needs an index.** There is no "where" on any field: create an index whose terms are the fields you
   search on (`MatchIndex`), and whose values are the fields you sort or range on (`Between`). `Filter` runs a lambda
   in PHP on every entry: use it only on small sets, or on top of an index search.
5. **`query()` returns `mixed`**: the shapes are below. Expressions are PHP objects, not strings: never build query
   text.
6. On MySQL and MariaDB, creating or deleting a collection, and creating, updating or deleting an index, commit the
   transaction first (the writes before them stay even if the query fails). Create the schema in migrations, not in
   requests.

## Setup

```php
use Flora\Client;
use Flora\Driver\{MariadbDriver, MysqlDriver, PostgresDriver, SqliteDriver};

$driver = new PostgresDriver(new PDO('pgsql:host=localhost;dbname=app', 'user', 'password'));
// MysqlDriver, MariadbDriver (DSN 'mysql:...;charset=utf8mb4') and SqliteDriver work the same way.
$driver->install();               // once: creates Flora's tables (safe to run again)
$client = new Client($driver);
```

## Schema

```php
use function Flora\{Collection, CreateCollection, CreateIndex};

$client->query(CreateCollection(['name' => 'users']));                    // optional: 'history_days' => 30
$client->query(CreateIndex([
    'name' => 'users_by_email',
    'source' => Collection('users'),
    'terms' => [['field' => ['data', 'email']]],                           // what MatchIndex() searches on
    'unique' => true,                                                       // optional
]));
$client->query(CreateIndex([
    'name' => 'users_by_role',
    'source' => Collection('users'),
    'terms' => [['field' => ['data', 'role']]],
    'values' => [['field' => ['data', 'age'], 'reverse' => true], ['field' => ['ref']]],   // sorted by these
]));
```

- Names: lowercase letters, digits and underscores, at most 48 characters, not starting with `flora_`.
- A field is `['data', 'path', 'to', 'field']`, `['ref']` or `['ts']`. `'unroll' => true` on a field holding a list
  gives an entry per item (tags, for instance).
- Entries are sorted by their values, then by ref. Without terms, an index covers the whole collection.
- `Documents(Collection('users'))` is every document, sorted by ref, without creating an index.
- `Update(Index('x'), ['name' => ..., 'unique' => ...])`, `Delete(Index('x'))`, `Delete(Collection('x'))` (with its
  documents), `Update(Collection('x'), ['history_days' => 7])`. Terms and values of an index cannot change: create
  another one.
- `Collections()`, `Indexes()`, `Functions()` are sets of the schema; `Get(Index('x'))` gives a definition.

## Documents

```php
use function Flora\{Collection, Create, Delete, Exists, Get, Ref, Replace, Update};

$ada = $client->query(Create(Collection('users'), ['data' => ['name' => 'Ada', 'email' => 'ada@example.com', 'role' => 'admin', 'age' => 36]]));
// $ada: ['ref' => Flora\Value\Ref, 'ts' => int (microseconds), 'data' => ['name' => 'Ada', ...]]

$client->query(Create(Ref(Collection('users'), '42'), ['data' => ['name' => 'Grace', 'role' => 'admin', 'age' => 85]]));   // at a chosen id
$client->query(Get($ada['ref']));                                              // same shape; NotFound if missing
$client->query(Update($ada['ref'], ['data' => ['city' => 'Paris', 'nickname' => null]]));   // deep merge, null removes

$alan = $client->query(Create(Collection('users'), ['data' => ['name' => 'Alan']]));
$client->query(Replace($alan['ref'], ['data' => ['name' => 'Alan Turing']]));  // replaces all data
$client->query(Exists($alan['ref']));                                          // bool
$client->query(Delete($alan['ref']));                                          // returns the deleted document
```

- A ref is `Flora\Value\Ref` (`$ref->id` is a numeric string, `$ref->collection->name`); keep refs, not ids, and store
  them in documents to link them (`['author' => $ada['ref']]`).
- Data is JSON as PHP arrays, plus refs, instants (`DateTimeInterface`) and dates (`Flora\Value\Date`), which come back
  as they went, like fields starting with `@`. `stdClass` is refused: decode JSON with `json_decode($json, true)`, and
  expect `{}` back as `[]`. Numbers must be finite, and lists and objects nest at most 31 levels deep on MariaDB, 100
  on MySQL.
- `NewId()` gives an id before creating the document.

## Sets and pages

```php
use function Flora\{Get, Index, MatchIndex, Paginate};

$admins = MatchIndex(Index('users_by_role'), 'admin');        // one argument per term; a set, nothing is read yet
$page = $client->query(Paginate($admins, ['size' => 20]));    // Flora\Value\Page
$page->data;                                                  // [[85, Ref], [36, Ref], ...]
$next = $client->query(Paginate($admins, ['size' => 20, 'after' => $page->after]));   // after/before: null at the end
$client->query(Get(MatchIndex(Index('users_by_email'), 'ada@example.com')));          // the first document of a set
```

- **What an entry gives** (`$page->data` items, lambda arguments): the ref when the index has no values, the value
  itself when it has one, a list of values when it has several. Add `['field' => ['ref']]` to the values to keep refs.
- Sets combine, read in SQL through their indexes: `Union`, `Intersection`, `Difference` (sets whose values have the
  same shape), `Between(set, from, to)` (bounds included, in the order of the index, `[]` for an open side, prefixes
  of the values: `Between($set, [80], [90])`), `Join(source, Index('detail'))` or
  `Join(source, Lambda('value', MatchIndex(...)))`, `Reverse`, `Distinct` (one entry per value), `Singleton($ref)`.
- `Size($set)` counts in SQL; `Exists($set)`, `IsEmpty($set)` and `IsNonEmpty($set)` read one entry; `Get($set)` reads
  the first document; `ContainsValue($value, $set)` searches the index.
- `Filter($set, Lambda(...))` checks each entry in PHP; `Reverse`, `Distinct` and `Filter` cannot be the operands of
  `Union`, `Intersection`, `Difference` or the source of `Join`: apply them to the result.

## Lambdas, variables and flow

```php
use function Flora\{Exists, Get, IfElse, Lambda, Let, Map, Paginate, Select, Variable};

// The documents of a page: Map works on pages and lists, not on sets.
$client->query(Map(Paginate($admins), Lambda(['age', 'ref'], Get(Variable('ref')))));
$client->query(Let(['user' => Get($ref)], Select(['data', 'name'], Variable('user'), 'anonymous')));
$client->query(IfElse(Exists($ref), Get($ref), null));
```

- `Lambda('x', ...)` takes one argument; `Lambda(['a', 'b'], ...)` destructures a list (`'_'` skips an item).
- `Select(path, value, default)` reads a path (`['data', 'tags', 0]`), throwing NotFound without a default;
  `SelectAll(path, value)` collects through lists. `ContainsPath`, `ContainsField` test a path.
- `Map`, `Filter`, `Each` (writes for each item), `Reduce(Lambda(['acc', 'item'], ...), initial, collection)` work on
  lists and pages; `Filter` and `Reduce` on sets too. `Each` refuses a set, whose entries would move under its writes:
  run it on a page, `Each(Paginate($set), ...)`. `Sequence(a, b)` returns the last value.
- `Equals`, `LT`, `LTE`, `GT`, `GTE` (numbers, strings, instants or dates of one kind), `Not`, `All`, `Any`.
- `Add`, `Subtract`, `Multiply`, `Divide` (integers truncate), `Modulo`, `Greatest`, `Least`, `Absolute`, `Sum`,
  `Mean`.
- `Abort('message')` rolls the query back and throws `Flora\Exception\Aborted`.

## Lists, objects, strings and types

- Lists: `Append(elements, base)`, `Prepend(elements, base)`, `Take`, `Drop`, `Reverse`, `Distinct`, `IsEmpty`,
  `IsNonEmpty`, `Size`, `Union`, `Intersection`, `Difference`, `ContainsValue`.
- Objects: `Merge(object, values, resolver)` (one level, null removes), `ToObject([[k, v], ...])`, `ToArray(object)`.
- Strings (UTF-8, positions in characters): `Concat(list, separator)`, `Format('%s is %d', ...)` (Java conversions),
  `LowerCase`, `UpperCase`, `TitleCase`, `Casefold`, `Length`, `SubString(value, start, length)`, `ContainsStr`,
  `StartsWith`, `EndsWith`, `FindStr`, `ReplaceStr`, `SplitStr`, `Repeat`, `Space`, `TrimStr`, `LTrimStr`, `RTrimStr`.
  Regular expressions are PCRE without delimiters: `ContainsStrRegex`, `FindStrRegex`, `ReplaceStrRegex`,
  `SplitStrRegex`, `RegexEscape`.
- Types: `IsString`, `IsNumber`, `IsInteger`, `IsDouble`, `IsBoolean`, `IsNull`, `IsArray`, `IsObject`, `IsRef`,
  `IsDoc`, `IsCollection`, `IsIndex`, `IsFunction`, `IsLambda`, `IsSetRef`, `IsTimestamp`, `IsDate`. Conversions:
  `ToString`, `ToNumber`, `ToInteger`, `ToDouble`.

## Time and dates

```php
use function Flora\{Get, GT, Now, Select, TimeAdd, Timestamp, ToDate, ToMicros};

$client->query(Now());                                     // DateTimeImmutable, UTC, the time of the query
$client->query(Timestamp('2026-10-11T14:00:00+02:00'));    // ISO 8601 with an offset
$client->query(ToDate('2026-10-11'));                      // Flora\Value\Date; also new Flora\Value\Date('...')
$recent = GT(Select('ts', Get($ref)), ToMicros(TimeAdd(Now(), -1, 'hour')));   // a document ts is microseconds
```

- `TimeAdd`, `TimeSubtract`, `TimeDiff` take units `day`, `half day`, `hour`, `minute`, `second`, `millisecond`,
  `microsecond`, `nanosecond` (no months nor years); dates take days only. `Epoch(n, unit)`, `ToMicros`, `ToTime`.
- `DayOfMonth`, `DayOfWeek` (1 is Monday), `DayOfYear`, `Month`, `Year`, `Hour`, `Minute`, `Second`, in UTC.
- Store instants and dates as such (not strings): indexes sort them and `Between` ranges on them.

## History

- Every write is kept: `Get($ref, $ts)` or `At($ts, $expr)` reads the database as it was (`$ts` in microseconds or an
  instant); writing inside `At` throws.
- `Paginate(Events($ref))` lists the writes of a document, `Paginate(Events($set))` the entries added to and removed
  from a set; items are `['ts' => ..., 'action' => 'create'|'update'|'delete' or 'add'|'remove', 'document' => Ref,
  'data' => ...]`.
- `Insert($ref, $ts, $action, ['data' => ...])` and `Remove($ref, $ts, $action)` rewrite history.
  `$client->expireHistory()` (scheduled) drops what `history_days` no longer keeps.

## Stored functions

```php
use function Flora\{Call, CreateFunction, FunctionRef, Index, Lambda, MatchIndex, Query, Size, Variable};

$client->query(CreateFunction([
    'name' => 'members',
    'body' => Query(Lambda('role', Size(MatchIndex(Index('users_by_role'), Variable('role'))))),
]));
$client->query(Call(FunctionRef('members'), 'admin'));      // or Call('members', 'admin')
```

The body sees no variable of the calling query; several arguments go to a lambda destructuring a list. `Update` and
`Delete` take `FunctionRef('name')`.

## Errors

All implement `Flora\Exception\FloraException`: `NotFound` (document, collection, index, function, variable, path),
`AlreadyExists`, `NotUnique` (a unique index, named by `$e->index`), `InvalidArgument` (a wrong argument, a misuse),
`Aborted` (`Abort()`), `Contended` (other transactions held a lock too long, or a deadlock: nothing was written, run
the query again).

## Recipes

```php
use function Flora\{Collection, Create, CreateCollection, CreateIndex, Delete, Each, Exists, Get, IfElse, Index, Join, Lambda,
    MatchIndex, Paginate, Select, Size, Update, Variable};

// Create or update by a unique field.
$byEmail = MatchIndex(Index('users_by_email'), $email);
$client->query(IfElse(
    Exists($byEmail),
    Update(Select('ref', Get($byEmail)), ['data' => $data]),
    Create(Collection('users'), ['data' => [...$data, 'email' => $email]]),
));

// The posts of the users of a city: the refs of the users are the terms of the index of the posts.
$client->query(CreateCollection(['name' => 'posts']));          // documents like ['author' => $user['ref'], ...]
$client->query(CreateIndex(['name' => 'posts_by_author', 'source' => Collection('posts'), 'terms' => [['field' => ['data', 'author']]]]));
$client->query(CreateIndex(['name' => 'users_by_city', 'source' => Collection('users'), 'terms' => [['field' => ['data', 'city']]]]));
$client->query(Paginate(Join(MatchIndex(Index('users_by_city'), 'Paris'), Index('posts_by_author'))));

// Delete the guests, a page per query until none is left: a page holds at most the size asked.
$guests = MatchIndex(Index('users_by_role'), 'guest');
while ($client->query(Size($guests)) > 0) {
    $client->query(Each(Paginate($guests, ['size' => 500]), Lambda(['age', 'ref'], Delete(Variable('ref')))));
}
```
