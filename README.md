# Flora

A FaunaDB-like document store on top of SQL, queried with functions modelled on FQL.

```php
use function Flora\{Collection, Get, Ref};

$client->query(Get(Ref(Collection('users'), '9203420348023942')));
```

- A **collection** is a table: `id BIGINT PRIMARY KEY`, `ts BIGINT`, `data JSON`.
- An **index** is built on JSON paths of `data` (indexed virtual columns on MySQL, MariaDB and SQLite, an expression
  index on PostgreSQL),
  so searching never scans the JSON.
- A **query** is a tree of expressions run by `Client::query()` in a single transaction: all its writes happen, or none.

Status: proof of concept, for PostgreSQL, MySQL, MariaDB and SQLite.

## Setup

```php
use Flora\Client;
use Flora\Driver\{MariadbDriver, MysqlDriver, PostgresDriver, SqliteDriver};

$driver = new PostgresDriver(new PDO('pgsql:host=localhost;dbname=app', 'user', 'password'));
// or
$driver = new MysqlDriver(new PDO('mysql:host=localhost;dbname=app;charset=utf8mb4', 'user', 'password'));
$driver = new MariadbDriver(new PDO('mysql:host=localhost;dbname=app;charset=utf8mb4', 'user', 'password'));
$driver = new SqliteDriver(new PDO('sqlite:/path/to/app.sqlite'));

$driver->install(); // creates the flora_collections, flora_indexes and flora_functions tables, once

$client = new Client($driver);
```

### Using Flora with an AI assistant

[`AI.md`](AI.md) is a guide for AI coding assistants: the rules, shapes, renamed FQL functions and recipes they need to
write correct queries. No assistant reads `vendor/` on its own: point yours to it from your project.

- Claude Code: add this line to the `CLAUDE.md` of your project, which imports the guide in every session:

  ```markdown
  @vendor/debuss-a/flora/AI.md
  ```

- Other assistants (`AGENTS.md`, Cursor or Copilot rules...): add an instruction such as *"Before writing database
  queries with Flora, read `vendor/debuss-a/flora/AI.md`."*

Tests keep the guide in step with the library. `tests/Unit/AiGuideTest.php` checks that it names every function, that
its examples call none that does not exist, and that its table of renamed FQL functions gives names that exist.
`tests/Integration/AiGuideTestCase.php` runs its examples as they are written, in order, on each database, and checks
what it says of the results: their shapes, the order of pages, bounds, joins, history.

## Collections and documents

```php
use function Flora\{Collection, Create, CreateCollection, Delete, Exists, Get, Ref, Replace, Update};

$client->query(CreateCollection(['name' => 'users']));

$ada = $client->query(Create(Collection('users'), ['data' => ['name' => 'Ada', 'email' => 'ada@example.com']]));
// ['ref' => Ref(users, "1068...") , 'ts' => 1791480000000000, 'data' => ['name' => 'Ada', ...]]

$client->query(Create(Ref(Collection('users'), '42'), ['data' => [...]])); // at a chosen id

$client->query(Get($ada['ref']));
$client->query(Update($ada['ref'], ['data' => ['age' => 36, 'email' => null]])); // deep merge, null removes a key
$client->query(Replace($ada['ref'], ['data' => ['name' => 'Ada Lovelace']]));  // replaces all data
$client->query(Exists($ada['ref']));
$client->query(Delete($ada['ref']));                                            // returns the deleted document
```

Refs can be stored in documents (`['author' => $ada['ref']]`), indexed and matched like any other value.

Data is JSON written as PHP arrays, plus refs, instants and dates. Objects are arrays with string keys, so an empty
object comes back as an empty list, and `stdClass` is refused: decode JSON with `json_decode($json, true)`. Data comes
back as it was written, fields starting with `@` included: Flora tags its own values as `{"@ref": ...}`, `{"@ts": ...}`
and `{"@date": ...}`, and stores a field of the application starting with `@` with another one (`"@type"` as
`"@@type"`). Index paths name fields as they are written (`['data', '@type']`).

Ids are [Snowflake ids](src/Id/Snowflake.php): 64-bit, sorted by creation time, generated in PHP.
`Snowflake::createdAt($id)` tells when an id was made. `NewId()` gives one without creating a document, for a document
whose ref is needed first.

`Delete(Collection('users'))` deletes a collection with its documents, their history and its indexes, and returns it
as it was. The name can then be given to another one.

```php
use function Flora\{Abort, IfElse, NewId};

// Abort() stops the query: its writes are rolled back, and the client throws Flora\Exception\Aborted.
$client->query([
    Update($account, ['data' => ['balance' => Subtract(Select(['data', 'balance'], Get($account)), 100)]]),
    IfElse(LT(Select(['data', 'balance'], Get($account)), 0), Abort('Insufficient funds.'), null),
]);
```

## Indexes

```php
use function Flora\{CreateIndex, Get, Index, MatchIndex, Paginate};

$client->query(CreateIndex([
    'name' => 'users_by_email',
    'source' => Collection('users'),
    'terms' => [['field' => ['data', 'email']]],
    'unique' => true,
]));

$client->query(Get(MatchIndex(Index('users_by_email'), 'ada@example.com'))); // first match

$client->query(CreateIndex([
    'name' => 'users_by_role',
    'source' => Collection('users'),
    'terms' => [['field' => ['data', 'role']]],
    'values' => [['field' => ['data', 'age'], 'reverse' => true], ['field' => ['data', 'name']], ['field' => ['ref']]],
]));

$page = $client->query(Paginate(MatchIndex(Index('users_by_role'), 'admin'), ['size' => 20]));
// $page->data: [[85, 'Grace', Ref], [36, 'Ada', Ref], ...]
$next = $client->query(Paginate(MatchIndex(Index('users_by_role'), 'admin'), ['size' => 20, 'after' => $page->after]));
$prev = $client->query(Paginate(MatchIndex(Index('users_by_role'), 'admin'), ['size' => 20, 'before' => $next->before]));
```

- **terms** are what `MatchIndex()` searches on, one argument per term. No terms: the whole collection.
- **values** are what each entry returns (the ref when there are none, the value itself when there is one) and what
  entries are sorted by. Entries with equal values are then sorted by ref.
- Fields are `['data', ...path]`, `['ref']` or `['ts']`. Values keep their JSON type: `36` and `"36"` are different
  terms, and numbers sort as numbers. Missing values are `null` and sort first.
- **unique** rejects writes that would give two documents the same terms and values (`NotUnique`).
- `Documents(Collection('users'))` is the set of all documents of a collection, sorted by ref.

```php
$client->query(Update(Index('users_by_email'), ['name' => 'emails']));   // renames it
$client->query(Update(Index('emails'), ['unique' => false]));             // or makes it unique, or not
$client->query(Delete(Index('emails')));                                  // the documents stay
```

- `Update` renames an index or changes `unique`; its terms, values and source stay, as in FQL: create another index
  for others. The index is built again: the new one before the old one is deleted, so that when it cannot be (a name
  taken, documents breaking it) the old one is still there, even on databases committing schema changes.
- `Delete` removes an index: its definition, and its columns, database index or table of entries.

### Unrolling lists

```php
$client->query(CreateIndex([
    'name' => 'posts_by_tag',
    'source' => Collection('posts'),
    'terms' => [['field' => ['data', 'tags'], 'unroll' => true]],
]));

// A post tagged ['php', 'sql'] has an entry for each tag: it matches both.
$client->query(Paginate(MatchIndex(Index('posts_by_tag'), 'php')));

// Posts tagged php and sql: entries of the same index, combined like any sets.
Intersection(MatchIndex(Index('posts_by_tag'), 'php'), MatchIndex(Index('posts_by_tag'), 'sql'));
```

- A field with `'unroll' => true` gives an entry per item of the list it holds, terms and values alike, and every
  combination of items when several fields unroll. A single value is one item; a missing field or an empty list
  gives no entry. A document repeating an item has one entry for it, and a unique index rejects an item another
  document has.
- Such an index cannot be computed from each document as one entry: it has a table of entries,
  `flora_entries_<index>`, filled when the index is created and updated by every write to the collection (one more
  statement per write and per such index). Reading it works like any index: matching a term or paginating seeks in
  the index of that table. The table only holds current entries: in the past (`At`, `Events`), entries are computed
  from the versions of the documents, unrolling their lists in SQL (`jsonb_array_elements`, `JSON_TABLE`,
  `json_each`).
- Unlike FaunaDB, which unrolled any list it met, fields are unrolled when the index says so: the index is stored
  differently.

## Combining sets

```php
use function Flora\{Difference, Intersection, Union};

// Admins or Parisians, each once, sorted by ref.
$client->query(Paginate(Union(
    MatchIndex(Index('users_by_role'), 'admin'),
    MatchIndex(Index('users_by_city'), 'Paris'),
)));

// Admins living in Paris.
$client->query(Get(Intersection(
    MatchIndex(Index('users_by_role'), 'admin'),
    MatchIndex(Index('users_by_city'), 'Paris'),
)));

// Admins not living in Paris.
$client->query(Paginate(Difference(
    MatchIndex(Index('users_by_role'), 'admin'),
    MatchIndex(Index('users_by_city'), 'Paris'),
)));

// Sets nest: (admins or editors) in Paris, except the viewers.
Difference(Intersection(Union($admins, $editors), $parisians), $viewers);

// Lists too.
$client->query(Union([1, 2], [2, 3]));        // [1, 2, 3]
$client->query(Intersection([1, 2], [2, 3])); // [2]
$client->query(Difference([1, 2], [2, 3]));   // [1]
```

- Entries are compared by their tuple, the index values followed by the ref: an intersection keeps the documents found
  in all the sets with the same values, a difference those of the first set found in none of the others with the same
  values, a union keeps each entry once. Entries are sorted like their indexes. A document missing the terms of a set
  is not in it.
- The indexes of the sets of an operation have as many values, of the same kind (data, ref or ts), sorted the same way.
  Indexes without values (entries are refs) always combine.
- Sets can point to several collections: `Union(MatchIndex(Index('users_by_tag'), 'x'), MatchIndex(Index('teams_by_tag'),
  'x'))` holds users and teams, sorted by values, then by ref (id, then collection). An intersection of sets of
  different collections is empty, since their refs differ, and a set of another collection excludes nothing from a
  difference.
- Paginating a union reads one page of each index from the cursor and merges them; an intersection or a difference
  reads the index of its first set and checks the terms of the others on each document. Nested sets are read as a
  union of branches: `(A ∪ B) ∩ C` as `(A ∩ C) ∪ (B ∩ C)`, up to 32 of them; the sets a difference excludes are
  checked whole, however nested.

## Joins

```php
use function Flora\Join;

// Posts written by admins: the posts_by_author index (terms: data.author) matched with the refs of the admins.
$client->query(Paginate(Join(MatchIndex(Index('users_by_role'), 'admin'), Index('posts_by_author'))));

// Projects of the teams of the admins: teams_by_role holds a value, data.team, matched with projects_by_team.
Join(MatchIndex(Index('teams_by_role'), 'admin'), Index('projects_by_team'));

// Joins nest and combine: comments on the posts of admins, except those of the web team.
Join(Difference(Join($admins, Index('posts_by_author')), $webPosts), Index('comments_by_post'));

// A lambda receives the items of each source entry (destructured when it holds several values) and returns a set.
Join($admins, Lambda('ref', MatchIndex(Index('posts_by_author'), Variable('ref'))));
Join($ada, Lambda(['team', '_'], MatchIndex(Index('grants_by_team_and_role'), Variable('team'), 'editor')));

// Posts written and reviewed by the same admin: all the terms of the result refer to the same entry.
Join($admins, Lambda('ref', Intersection(
    MatchIndex(Index('posts_by_author'), Variable('ref')),
    MatchIndex(Index('posts_by_reviewer'), Variable('ref')),
)));
```

- `Join(source, Index('detail'))` is the set of the entries of the detail index whose terms equal an entry of the
  source set: its ref when the source index has no values, its value when it has one, its values (one per term) when
  it has several. It is sorted like the detail index, and combines with `Union`, `Intersection` and `Difference`.
- `Join(source, Lambda(...))` is the union of the sets the lambda returns for each source entry. The lambda is
  evaluated once, its parameters standing for the items of all the entries at once: they can only be terms of
  `MatchIndex()` in the set it returns (combined with `Union`, `Intersection`, `Difference` and other joins), not in
  the sets a `Difference()` excludes, nor in the source of a nested join. Comparing or reading them throws, rather than
  deciding once for all the entries.
- The detail index has as many terms as the source entries hold items, on data fields; refs stored in documents (see
  above) match the refs of the source.
- A page of a join reads the detail entries matching the source, through a subquery the database runs once, then sorts
  them: it costs about the number of matching entries.
- On MySQL and MariaDB, join terms are compared on their index keys: values longer than 128 characters match on their
  beginning, and numbers as doubles.

## Ranges

```php
use function Flora\Between;

// Admins aged 40 to 65, both included, in the order of the index.
$client->query(Paginate(Between(MatchIndex(Index('ages_by_role'), 'admin'), 40, 65)));

// October's orders, from an index whose first value is the date: the bounds are prefixes.
Between(MatchIndex(Index('orders_by_date')), [Timestamp('2026-10-01T00:00:00Z')], [Timestamp('2026-10-31T23:59:59.999999Z')]);

// From 40 on, up to 65: an empty list leaves a side open.
Between(MatchIndex(Index('ages_by_role'), 'admin'), 40, []);
Between(MatchIndex(Index('ages_by_role'), 'admin'), [], 65);
```

- `Between(set, from, to)` (FQL's `Range`) keeps the entries of a set from one bound to the other, both included. A
  bound is a value or a list: a prefix of the tuple of the entries, their values then their ref. An entry is within
  `[80]` and `[90]` when its first value is, whatever the others: `[90, 'Zoe']` is in. A ref at the end of a bound
  tells apart entries with the same values, and bounds a set without values, like `Documents()`.
- Bounds follow the order of the index: for a `reverse` value, `from` is the larger one. Values of different types
  compare like the index sorts them.
- The bounds are conditions on the index, read like the cursor of a page: a page of the range seeks into the index
  and reads no more than the page, and `Size` counts the range in the index.
- `Between` bounds any set: matches, unions, intersections, differences, joins (bound the source to join part of it,
  or the result of the lambda), other ranges (both apply), filters (the range is read, then filtered). Ranges read the
  past with `At` and have `Events`: a document enters the range, or leaves it, when its value does.

## Filtering

```php
use function Flora\{All, Any, Filter, GT, GTE, LT, LTE, Not};

// Admins living in Paris: the lambda receives each entry (a ref here) and returns whether to keep it.
$client->query(Paginate(Filter(
    MatchIndex(Index('users_by_role'), 'admin'),
    Lambda('ref', Equals(Select(['data', 'city'], Get(Variable('ref'))), 'Paris')),
)));

// On the values of an index: admins aged 40 or more (Between(..., 40, []) reads them from the index).
Filter(MatchIndex(Index('ages_by_role'), 'admin'), Lambda(['age', 'ref'], GTE(Variable('age'), 40)));

// Lists and pages too (a page keeps its cursors).
$client->query(Filter([1, 2, 3, 4], Lambda('n', GT(Variable('n'), 2)))); // [3, 4]
```

- A filtered set is read like any set, with `Paginate` (both ways), `Get` and `Exists`. The lambda can do anything a
  query does, so it runs for each entry: the set is read from the cursor in chunks, growing while the lambda rejects
  entries, until the page is full. A page costs about the number of entries the lambda looks at.
- Filters nest, and filter any set (unions, joins...), but a filtered set cannot be combined with `Union`,
  `Intersection`, `Difference` or be the source of a `Join`, which databases read with SQL: filter the combination.
- `LT`, `LTE`, `GT` and `GTE` compare numbers, or strings (by code point): `LT(1, 2, 3)` is true. `Not` negates,
  `All` and `Any` (FQL's `And` and `Or`) evaluate their conditions in order until the result is known.

## Counting and reducing

```php
use function Flora\{Add, Append, Concat, Greatest, Reduce, Size};

$client->query(Size(MatchIndex(Index('users_by_role'), 'admin')));          // 2
$client->query(Size(Union($admins, $parisians)));                           // each entry once
$client->query(Size(Filter(Documents(Collection('users')), $inParis)));
$client->query(Size([1, 2, 2]));                                            // 3

// The reducer receives the value so far and each item: the total age of the admins, and the oldest.
$ages = MatchIndex(Index('ages_by_role'), 'admin');                         // values: age, ref
$client->query(Reduce(Lambda(['total', 'entry'], Add(Variable('total'), Select(0, Variable('entry')))), 0, $ages));
$client->query(Reduce(Lambda(['oldest', 'entry'], Greatest(Variable('oldest'), Select(0, Variable('entry')))), 0, $ages));

// Their names, as a list, then a string.
$names = Reduce(Lambda(['names', 'entry'], Append([Select(['data', 'name'], Get(Select(1, Variable('entry'))))], Variable('names'))), [], $ages);
$client->query(Concat($names, ', '));                                       // "Margaret, Ada, Grace"
```

- `Size` (FQL's `Count`) returns how many items a list or a page has, or how many entries a set has, each counted once (an entry
  found in several branches of a union, or several times by a join, counts once). FQL returned a page for a page;
  Flora returns an integer.
- A set is counted by the database in a single query (`SELECT COUNT(*)`, through the index when it is a single
  match); a filtered set by running its lambda on every entry of the set it filters.
- `Reduce(reducer, initial, collection)` folds a list, a page or a set. The reducer can do anything a query does, so
  it runs in PHP: a set is read whole, in chunks of 1,024 entries.
- `Add`, `Subtract`, `Multiply`, `Divide`, `Modulo`, `Greatest`, `Least` and `Absolute` work on numbers, folded from
  left to right. Dividing integers gives an integer, truncated, like FQL: `Divide(7, 2)` is 3.
- `Append(elements, base)` adds elements at the end of a list, in FQL's order of arguments: `Append([4, 5], [1, 2, 3])`
  is `[1, 2, 3, 4, 5]`, and `Prepend(elements, base)` at its start: `Prepend([1, 2], [3, 4])` is `[1, 2, 3, 4]`.
  `Concat(strings, separator)` joins strings.

## Lists, objects and sets

```php
use function Flora\{ContainsField, ContainsPath, ContainsValue, Distinct, Drop, IsEmpty, IsNonEmpty, Mean, Merge,
    Reverse, Sum, Take, ToArray, ToObject};

$client->query(Take(2, [1, 2, 3]));                                         // [1, 2]
$client->query(Drop(2, [1, 2, 3]));                                         // [3]
$client->query(Paginate(Reverse(MatchIndex(Index('ages')))));               // the oldest first
$client->query(Get(Reverse(MatchIndex(Index('ages_and_refs')))));          // the oldest user
$client->query(Paginate(Distinct(MatchIndex(Index('tags')))));              // each tag once
$client->query(IsEmpty(MatchIndex(Index('users_by_role'), 'guest')));       // true
$client->query(Sum(MatchIndex(Index('ages_by_role'), 'admin')));            // their total age
$client->query(Mean([1, 2, 3, 4]));                                         // 2.5
$client->query(ContainsValue(41, MatchIndex(Index('ages'))));               // is someone 41?
$client->query(ContainsPath(['data', 'address', 'city'], Get($ref)));
$client->query(Merge(['a' => 1, 'b' => 2], ['b' => null, 'c' => 3]));      // ['a' => 1, 'c' => 3]
$client->query(ToArray(['a' => 1]));                                        // [['a', 1]], and back with ToObject()
```

- `Take(n, list)` and `Drop(n, list)` keep or drop the first items of a list or a page; a page keeps its cursors, like
  with `Filter` and `Map`.
- `Reverse` reverses a list or a page, or a set: the set is read backward, through its index, so its pages cost what
  they cost forward. It is paginated with the cursors of the set; `Between` on it takes its bounds in its order.
- `Distinct` removes the items repeated in a list or a page, keeping the first, or gives one entry per distinct values
  of a set (its item, the values). A set sorts by values, so repeated values follow each other: they are skipped while
  reading, and a page reads the entries it skips. `Size` of it reads the whole set.
- `IsEmpty` and `IsNonEmpty` read one entry of a set. `Sum` and `Mean` add the numbers of a list, a page or a set,
  read whole; `Mean` of nothing throws.
- `ContainsValue(value, in)` looks for an item of a list or a page, a value of an object, or an item of a set: the set
  is searched in its index, like `Between(set, value, value)`. `ContainsPath(path, in)` tells whether `Select` finds a
  value at a path, and `ContainsField(field, object)` whether an object has a field.
- `Merge(object, values, resolver)` merges objects one level deep (unlike `Update`, which merges data at any depth):
  `values` is an object or a list of them, a `null` value removes the field, and the optional resolver,
  `Lambda(['field', 'left', 'right'], ...)`, gives the value of a field both have. `ToObject` and `ToArray` turn
  `[field, value]` pairs into an object, and back.
- Reversed, distinct and filtered sets are read through the set they come from: they cannot be combined with `Union`,
  `Intersection`, `Difference` nor be the source of a `Join`; apply them to the combination.

## Strings and types

```php
use function Flora\{Casefold, ContainsStr, Format, LowerCase, ReplaceStrRegex, SplitStr, SubString, ToInteger,
    ToString, TrimStr, IsString};

$client->query(LowerCase('ÉCOLE'));                                         // "école"
$client->query(Casefold('Straße'));                                         // "strasse", to compare
$client->query(SubString('Ada Lovelace', -8, 4));                           // "Love"
$client->query(SplitStr('a,b,c', ','));                                     // ['a', 'b', 'c']
$client->query(ReplaceStrRegex('One Fish Two Fish', '(\w+) Fish', '$1 Cat')); // "One Cat Two Cat"
$client->query(Format('%s is %d years old', 'Ada', 36));                    // "Ada is 36 years old"
$client->query(ToInteger('12'));                                            // 12
$client->query(IsString(Select(['data', 'name'], Get($ref))));
```

- The functions on strings are FQL's: `LowerCase`, `UpperCase`, `TitleCase`, `Casefold` (with a normalizer, NFKC by
  default), `Length`, `SubString` (from the end when the start is negative), `ContainsStr`, `StartsWith`,
  `EndsWith`, `FindStr` (-1 when not found), `ReplaceStr`, `SplitStr`, `Repeat`, `Space`, and `TrimStr`, `LTrimStr`,
  `RTrimStr` (FQL's `Trim`, `LTrim`, `RTrim`). Strings are UTF-8: positions and lengths count characters (code
  points), not bytes.
- Regular expressions are PCRE, written without delimiters: `ContainsStrRegex`, `FindStrRegex` (each match with its
  start, end included, and text), `ReplaceStrRegex` (`$1` is a group; only the first match when told), `SplitStrRegex`,
  and `RegexEscape`, which escapes a string to match it as it is. FQL's were Java's, which PCRE mostly reads alike.
- `Format(format, ...values)` takes Java's conversions: `%s` (`%S` in upper case), `%d`, `%f`, `%e`, `%x`, `%o`, `%b`
  (a boolean), `%@` (any value as JSON), `%%` and `%n`, with the flags `-`, `+`, space and `0`, a width, a precision
  and positions (`%2$s`). Numbers are rounded as PHP's `sprintf()` does, which may differ from Java on the last digit
  (`%.1f` of 2.25 is `2.2`).
- `Repeat`, `Space` and the widths of `Format` make strings of at most 16 MB: beyond, PHP would run out of memory, a
  fatal error no query could catch.
- `IsString`, `IsNumber`, `IsInteger`, `IsDouble`, `IsBoolean`, `IsNull`, `IsArray`, `IsObject`, `IsRef` (of a
  document, a collection or an index), `IsCollection`, `IsIndex`, `IsDoc`, `IsLambda`, `IsTimestamp` and `IsSetRef`
  (FQL's `IsSet`: `isset` is a word of PHP) check types. PHP does not tell an empty list from an empty object: `[]` is
  both.
- `ToString` writes strings, numbers (doubles keep their fraction: `"1.0"`), booleans, null and instants;
  `ToNumber` reads numbers (`"42"` is an integer, `"4.2"` and `"1e3"` doubles); `ToInteger` truncates; `ToDouble`.
- They need the mbstring extension of PHP, and `Casefold` the intl extension too.

## Stored functions

```php
use function Flora\{Call, CreateFunction, FunctionRef, Functions, Query};

$client->query(CreateFunction([
    'name' => 'transfer',
    'body' => Query(Lambda(['from', 'to', 'amount'], IfElse(
        LT(Select(['data', 'balance'], Get(Variable('from'))), Variable('amount')),
        Abort('Insufficient funds.'),
        [
            Update(Variable('from'), ['data' => ['balance' => Subtract(Select(['data', 'balance'], Get(Variable('from'))), Variable('amount'))]]),
            Update(Variable('to'), ['data' => ['balance' => Add(Select(['data', 'balance'], Get(Variable('to'))), Variable('amount'))]]),
        ],
    ))),
]));

$client->query(Call(FunctionRef('transfer'), $from, $to, 100));            // or Call('transfer', ...)
$client->query(Update(FunctionRef('transfer'), ['body' => Lambda(...)]));   // or ['name' => ...]
$client->query(Delete(FunctionRef('transfer')));
```

- A function is a `Lambda()`, stored in the database: its body is kept as written, in `Query()` as in FQL, or given
  directly. It sees none of the variables of the query creating or calling it, and runs in the query calling it: its
  writes are part of it, and inside `At` it reads the past.
- `Call(function, ...arguments)` takes a `FunctionRef()` (FQL's `Function`, a word of PHP) or a name. A function
  taking one parameter receives the argument, one destructuring several (`Lambda(['a', 'b'], ...)`) the list of the
  arguments. Functions call functions, themselves too, at most 64 calls deep.
- The body is stored as JSON: each expression with its class and properties, refs and instants tagged
  (`Support\Serializer`). It can hold any expression and value written in a query, but not what a query makes while
  it runs (sets, pages, evaluated lambdas): those are built inside the function. Reading a body back only builds
  expressions of Flora.
- A query loads a function once, however many times it calls it.

## The schema

```php
use function Flora\{Collections, Functions, Indexes};

$client->query(Paginate(Collections()));                                    // [Collection('accounts'), Collection('users')]
$client->query(Map(Paginate(Indexes()), Lambda('index', Get(Variable('index')))));   // the definitions
$client->query(Get(Index('users_by_email')));                               // name, source, terms, values, unique, ts
$client->query(Exists(FunctionRef('transfer')));
```

- `Collections()`, `Indexes()` and `Functions()` are the sets of the refs of the schema, sorted by name: paginated,
  filtered, counted like other sets. The schema has no history: inside `At`, they are those of now.
- `Get` and `Exists` take the refs of collections, indexes and functions; `Select('name', $ref)` reads their name.

## Writing in bulk

```php
use function Flora\Each;

// Delete the guests, a page at a time: each page in a query, and so in a transaction.
do {
    $page = $client->query(Each(
        Paginate(MatchIndex(Index('users_by_role'), 'guest'), ['size' => 100]),
        Lambda('ref', Delete(Variable('ref'))),
    ));
} while ($page->after !== null);

// A year older each: the items of a page of ages_by_role are its values, the age and the ref.
Each(Paginate($admins), Lambda(['age', 'ref'], Update(Variable('ref'), ['data' => ['age' => Add(Variable('age'), 1)]])));
```

- `Each` (FQL's `Foreach`) runs a lambda on each item of a list or a page, for what it does, and returns the list or
  the page. Like any query, it writes all or nothing.
- Like FQL, it takes a list or a page, not a set: writing to the documents of a set while reading it would move their
  entries in its indexes, and some would be seen twice and others not at all. Paginate, and run `Each` on each page.

## Time

```php
use function Flora\{Epoch, Now, TimeAdd, TimeDiff, Timestamp, TimeSubtract, ToMicros};

$client->query(Now());                                         // the time of the query, a DateTimeImmutable in UTC
$client->query(Timestamp('2026-10-11T14:00:00+02:00'));        // 2026-10-11 12:00:00 UTC
$client->query(Create(Collection('people'), ['data' => ['born' => Timestamp('1815-12-10T00:00:00Z')]]));
$client->query(At(Timestamp('2026-10-01T00:00:00Z'), Paginate(MatchIndex(Index('users_by_role'), 'admin'))));

// Documents written in the last hour: the ts of a document counts microseconds.
$client->query(Paginate(Filter(Documents(Collection('people')), Lambda('ref',
    GT(Select('ts', Get(Variable('ref'))), ToMicros(TimeAdd(Now(), -1, 'hour'))),
))));
$client->query(Epoch($document['ts'], 'microseconds'));       // the ts of a document, as an instant
$client->query(TimeDiff(Epoch($document['ts'], 'microseconds'), Now(), 'days'));   // its age, in whole days
```

- `Now()` is the time of the query, the same everywhere in it: the ts of its writes. `Timestamp(text)` (FQL's `Time`)
  reads an ISO 8601 date and time with an offset; `Timestamp('now')` is `Now()`. Both are `DateTimeImmutable` in UTC,
  to the microsecond, and impossible dates (`2026-02-30T...`) throw rather than roll over.
- Documents store instants, from these functions or `DateTimeInterface` of PHP, as `{"@ts": "...Z"}`, and give them
  back as `DateTimeImmutable`. Their fixed width makes indexes sort them by time and match them exactly.
- `At` takes an instant or microseconds; `LT`, `LTE`, `GT`, `GTE` compare instants, `Equals` compares them whatever
  their time zone.
- `ToMicros(time)` gives the microseconds since 1970-01-01T00:00:00Z of an instant, `Epoch(amount, unit)` the instant
  that many units after it, and `TimeAdd(time, offset, unit)` moves an instant (backward when the offset is negative),
  `TimeSubtract(time, offset, unit)` backward. `TimeDiff(start, finish, unit)` counts the whole units from `start` to
  `finish`, truncated toward zero: negative when `finish` comes first.
  Units are FQL's, singular or plural: `day`, `half day`, `hour`, `minute`, `second`, `millisecond`, `microsecond`,
  `nanosecond` (truncated to microseconds). Months and years are not units: their length varies.

### Dates

```php
use Flora\Value\Date;
use function Flora\{DayOfWeek, Month, ToDate, ToTime, Year};

$client->query(ToDate('2026-10-11'));                          // a Flora\Value\Date
$client->query(ToDate(Now()));                                 // today, in UTC
$client->query(Create(Collection('people'), ['data' => ['birthday' => new Date('1815-12-10')]]));
$client->query(Paginate(Between(MatchIndex(Index('birthdays')), ToDate('1900-01-01'), ToDate('1949-12-31'))));
$client->query(TimeDiff(ToDate('2026-01-01'), ToDate('2026-10-11'), 'days'));   // 283
$client->query([DayOfWeek($time), Month($time), Year($time)]);  // of an instant or a date, in UTC
```

- A date is a day of the calendar, without time: `Flora\Value\Date`, whose `value` is `2026-10-11`. `ToDate` (also
  FQL's `Date`, which would take the place of PHP's `date()`) reads one from text, or gives the day of an instant in
  UTC; `ToTime` gives the instant a date starts at, in UTC, or reads an instant from text.
- Documents store dates as `{"@date": "2026-10-11"}`: indexes sort and match them, and `Between` reads ranges of them.
- `DayOfMonth`, `DayOfWeek` (1 is Monday, 7 Sunday), `DayOfYear`, `Month`, `Year`, `Hour`, `Minute` and `Second` read
  instants and dates, in UTC; a date starts at midnight.
- `TimeAdd`, `TimeSubtract` and `TimeDiff` take dates too, in days. Dates compare with dates, not with instants: convert
  one with `ToDate` or `ToTime`. `ToString`, `Format` and `IsDate` know them.

## History

```php
use function Flora\{At, Events, Insert, Remove};

$created = $client->query(Create(Collection('users'), ['data' => ['name' => 'Ada', 'role' => 'guest']]));
$client->query(Update($created['ref'], ['data' => ['role' => 'admin']]));

// Every write of a document is an event: {ts, action (create, update or delete), document, data}.
$client->query(Paginate(Events($created['ref'])));

// Every change of a set is an event: {ts, action (add or remove), document, data (the values of the entry)}.
$client->query(Paginate(Events(MatchIndex(Index('users_by_role'), 'admin'))));

// Rewrites history: an event is an action and the whole document after it. The document becomes what its last event
// says, so rewriting the last one changes it.
$client->query(Insert($created['ref'], $created['ts'] + 1, 'update', ['data' => ['name' => 'Ada Byron', 'role' => 'guest']]));
$client->query(Remove($created['ref'], $created['ts'] + 1, 'update'));

// Reads as the database was at a time, in microseconds like the ts of documents.
$client->query(At($created['ts'], Get($created['ref'])));                        // role: guest
$client->query(Get($created['ref'], $created['ts']));                            // the same
$client->query(At($created['ts'], Paginate(MatchIndex(Index('users_by_role'), 'guest'))));
```

- Each collection has a history, `flora_history_<collection>`: every write adds an event, at the time of its query.
  Several writes of a document in a query are one event: creating and updating it is creating it, creating and
  deleting it leaves no trace. History costs one more statement per write.
- History is kept for ever, unless the collection says otherwise: `CreateCollection(['name' => 'logs', 'history_days' =>
  30])`, or `Update(Collection('logs'), ['history_days' => 7])` later; `Get(Collection('logs'))` reads it.
  `$client->expireHistory()`, run regularly (a scheduled task), removes the events older than that, keeping the
  version each document had then: documents, and reads in the past within those days, do not change. Unlike FaunaDB,
  which kept 30 days by default and expired history in the background, nothing expires unless asked.
- `Events(ref)` is the history of a document, read by time like a set: `Paginate` (both ways), `Size`, `Filter`,
  `Reduce`. Inside `At`, it ends at that time.
- `Events(set)` is the history of a set: an `add` when an entry entered it, a `remove` when it left, sorted by time,
  document, removals first. Changing the values of an entry removes it and adds the new one at the same time. It is
  computed as each page is read:
  - for a set of one collection without joins, from the versions of its documents: a page costs about the size of the
    history of the set (75 ms for a set of 200 documents with 3 versions each, 0.5 to 0.7 s for a whole collection of
    60,000 versions);
  - for a set with joins, whose entries also depend on other documents (a post leaves the posts of admins when its
    author stops being one), or of several collections, from the set as it was each time a collection it reads was
    written: a page costs a read of the whole set per write (0.2 to 0.7 s for 300 writes and a set of 40 posts).
- `Insert(ref, ts, action, params)` adds an event (`create`, `update` or `delete`, with the whole document after it)
  to the history of a document, replacing the event at that time; `Remove(ref, ts, action)` removes one. The document
  then becomes what its last event says, with its indexes: inserting an event after the others changes it, removing
  the last one brings back the previous version, removing a deletion brings the document back, and without events it
  does not exist.
- `At(ts, expr)` evaluates `expr` as the database was at `ts`: documents are read from their history, and sets from a
  snapshot of their collections (the last event of each document until `ts`), with `Paginate`, `Size`, `Get`,
  `Exists`, unions, joins, indexes unrolling fields... The snapshot has no index: reading a set in the past costs
  more as history grows. `At` only reads: writing inside it throws.

## Composing queries

```php
use function Flora\{Equals, IfElse, Lambda, Let, Map, Select, Variable};

$client->query(Map(
    Paginate(MatchIndex(Index('users_by_role'), 'admin')),
    Lambda(['age', 'name', 'ref'], Select(['data', 'email'], Get(Variable('ref')), 'none')),
));

$client->query(Let(
    ['user' => Get(MatchIndex(Index('users_by_email'), 'ada@example.com'))],
    IfElse(
        Equals(Select(['data', 'role'], Variable('user')), 'admin'),
        Update(Select('ref', Variable('user')), ['data' => ['last_seen' => time()]]),
        null,
    ),
));
```

Arrays are evaluated too, so `$client->query([Get($a), Get($b)])` reads both documents in one transaction.

```php
use function Flora\{SelectAll, Sequence, Singleton};

$client->query(Sequence(Update($ref, ['data' => ['seen' => true]]), Get($ref)));   // the value of the last one
$client->query(SelectAll(['data', 'tags', 'name'], Get($post)));                  // the name of each tag
$client->query(Paginate(Difference(MatchIndex(Index('users_by_role'), 'admin'), Singleton($me))));
```

- `Sequence` (FQL's `Do`, a word of PHP) evaluates expressions in order and returns the value of the last one, where
  an array returns them all.
- `SelectAll(path, from)` returns every value at a path: a field of the path is read in each item of a list it meets,
  an index in the list itself, and missing values are skipped. `SelectAll(['foo', 0], [['foo' => [0, 1]],
  ['foo' => [2, 3]]])` is `[0, 2]`.
- `Singleton(ref)` is the set of one document, while it exists: it combines with other sets, and its `Events` are the
  document entering the set when created and leaving it when deleted.

### Differences with FQL

| FQL      | Flora        | Why                    |
|----------|--------------|------------------------|
| `Match`  | `MatchIndex` | `match` is a PHP keyword |
| `Var`    | `Variable`   | `var` is a PHP keyword   |
| `If`     | `IfElse`     | `if` is a PHP keyword    |
| `And`    | `All`        | `and` is a PHP keyword   |
| `Or`     | `Any`        | `or` is a PHP keyword    |
| `Foreach`| `Each`       | `foreach` is a PHP keyword |
| `Count`  | `Size`       | `count()` is a PHP function |
| `Max`    | `Greatest`   | `max()` is a PHP function   |
| `Min`    | `Least`      | `min()` is a PHP function   |
| `Abs`    | `Absolute`   | `abs()` is a PHP function   |
| `Time`   | `Timestamp`  | `time()` is a PHP function  |
| `Date`   | `ToDate`     | `date()` is a PHP function  |
| `Range`  | `Between`    | `range()` is a PHP function |
| `Trim`, `LTrim`, `RTrim` | `TrimStr`, `LTrimStr`, `RTrimStr` | `trim()`, `ltrim()` and `rtrim()` are PHP functions |
| `IsSet`  | `IsSetRef`   | `isset` is a PHP keyword    |
| `Function` | `FunctionRef` | `function` is a PHP keyword |
| `Do`     | `Sequence`   | `do` is a PHP keyword       |

Function names are case-insensitive in PHP: a function imported with `use function` takes the place of the PHP
function of the same name in that file, and would silently return an expression. Flora's functions therefore avoid the
names of PHP's, except `Join`: importing it hides `join()`, the alias of `implode()`. Frameworks define global
functions too: in a Laravel application, importing `Now` hides the `now()` helper in that file.

Not there yet: other databases.

## Errors

All exceptions implement `Flora\Exception\FloraException`: `NotFound`, `AlreadyExists`, `NotUnique`,
`InvalidArgument`, `Aborted` and `Contended`. A failing query rolls back all of its writes.

`Contended` means that the query ran into other transactions: it waited too long for a lock (SQLite's busy timeout,
`innodb_lock_wait_timeout`, `lock_timeout`), or the database stopped it to break a deadlock. It wrote nothing and can
be run again, or answered with a 503.

## Drivers

A database is supported by implementing [`Flora\Driver\Driver`](src/Driver/Driver.php): collection and index
creation, reads and writes by id, and an index scan with cursors. The query language does not depend on it, and all
drivers pass the same integration tests.

|                 | PostgreSQL (12+)               | MySQL (8.0.17+)         | MariaDB (10.11+)        | SQLite (3.38+)          |
|-----------------|--------------------------------|-------------------------|-------------------------|-------------------------|
| `data` column   | `JSONB`                        | `JSON`                  | `JSON` (checked text)   | `TEXT` (checked JSON)   |
| Index           | expression index on the paths  | indexed virtual columns | indexed virtual columns | indexed virtual columns |
| `36` and `36.0` | the same term                  | the same term           | the same term           | the same term           |
| Object terms    | key order ignored              | key order ignored       | key order ignored       | key order matters       |
| String order    | the database collation         | code point              | code point              | code point              |
| Lists, objects  | by size, then items; `[]` first | by JSON text           | by JSON text            | by JSON text            |
| Large integers  | exact                          | sorted as doubles       | sorted as doubles       | exact                   |
| Nesting depth   | 512 levels (PHP's JSON)        | 100 levels              | 31 levels               | 512 levels (PHP's JSON) |
| Long values     | index entries up to ~2 KB      | keys cut at 128 chars   | keys cut at 128 chars   | no limit                |
| Object keys     | sorted by JSONB                | sorted by MySQL         | kept as written         | kept as written         |
| Schema changes  | transactional                  | commit the transaction  | commit the transaction  | transactional           |

### Virtual columns: MySQL, MariaDB and SQLite

[`MysqlDriver`](src/Driver/MysqlDriver.php), [`MariadbDriver`](src/Driver/MariadbDriver.php) and
[`SqliteDriver`](src/Driver/SqliteDriver.php) share [`AbstractVirtualColumnsDriver`](src/Driver/AbstractVirtualColumnsDriver.php),
and differ only in the SQL that declares tables and indexes and reads JSON. Each driver checks in `install()` that the
database is the right one, and recent enough.

- Each term is a virtual column holding a key of the JSON value. Each value is three virtual columns (JSON type,
  number, string), so entries sort by type then value, as on PostgreSQL. Lists and objects sort by their JSON text,
  where PostgreSQL sorts them by size, then item by item, and puts the empty list before `null`: index values holding
  lists or objects sort differently from one database to another, documents with them are found the same.
- Pages are read with keyset pagination on the index: a page far in a set costs about the same as the first one. When
  the values of an index go different ways (one `reverse`, one not), a page also reads the entries sharing its first
  value.

MySQL and MariaDB ([`AbstractMysqlDriver`](src/Driver/AbstractMysqlDriver.php)):

- Keys are cut at 128 characters; the JSON values are compared too, so matches are always exact. Values whose first
  128 characters are equal sort as equal (by the values after them in the index, then by ref), and collide in a
  unique index.
- Both commit the current transaction before `CREATE TABLE`, `ALTER TABLE` and `DROP TABLE`: in a query that runs
  `CreateCollection()`, `CreateIndex()`, or `Update()` or `Delete()` on an index or a collection, the writes made
  before are committed even if the query fails afterwards. The writes made after are still rolled back. A deleted
  index or collection stays deleted: its definition is deleted before its tables and columns, and committed with
  them.
- An index holds at most 16 columns: count one per term, three per value, plus the ref.
- Numbers sort as doubles: integers beyond 2^53 that differ by little sort as equal, by ref. They are stored and
  matched exactly.
- Documents nest lists and objects at most 100 levels deep on MySQL, 31 on MariaDB: deeper ones are refused with
  `InvalidArgument`.
- Prepared statements work emulated (PDO's default) or native.

SQLite:

- Keys are never cut, and integers keep their exact value.
- Objects used as terms are compared as written: `{"a": 1, "b": 2}` and `{"b": 2, "a": 1}` are different terms (refs
  are always written the same way).
- SQLite has a single writer: a query holds the database until its transaction ends. Transactions begin `IMMEDIATE`:
  a query takes the write lock before it reads, waiting for the others up to the busy timeout of the connection
  (`PDO::ATTR_TIMEOUT`, 60 seconds by default), rather than failing when it writes after reading. Queries that only
  read wait as well.

## Tests

```sh
docker compose up -d   # PostgreSQL on port 54329, MySQL on 33069, MariaDB on 33079
composer test
```

The integration tests in `tests/Integration/*TestCase.php` run on each driver (`tests/Integration/Postgres`,
`tests/Integration/Mysql`, `tests/Integration/Mariadb`, `tests/Integration/Sqlite`); a driver's tests are skipped when
its database is not reachable. SQLite runs in memory, without Docker.

Two tests run on data made to catch what databases do differently (`tests/Integration/AuditFixture.php`: numbers
near the limits of integers and doubles, strings in several scripts, null and missing fields, lists, objects, refs,
instants, dates, as the values of indexes):

- `SetInvariantsTestCase`, on each database: every kind of set (matches, unions, joins, ranges, distinct, filtered...)
  read page by page, forward and backward, with pages of several sizes, gives the whole set once and in order; `Size`,
  `Reverse` and `IsEmpty` agree with it.
- `CrossDatabaseTest`, on the four databases at once: the same queries give the same entries, in the same order, the
  same matches and the same events; where they differ, it is as the table of the Drivers section says, which it
  checks too.

```sh
composer analyse       # PHPStan
```

PHPStan checks the library at its strictest level (`phpstan.dist.neon`), and the tests at level 8
(`phpstan.tests.neon`): what queries return is mixed by nature.

The GitHub Actions workflow ([`.github/workflows/ci.yml`](.github/workflows/ci.yml)) runs PHPStan, and the tests on
PHP 8.4 and 8.5 against the databases of `compose.yaml`, as services. There, a skipped test fails the build: a
database that did not start would otherwise go unnoticed.

## License

Flora is released under the [MIT License](LICENSE).
