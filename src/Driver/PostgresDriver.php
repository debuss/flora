<?php declare(strict_types=1);

namespace Flora\Driver;

use ArrayObject;
use LogicException;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;
use Flora\Exception\{AlreadyExists, Contended, InvalidArgument, NotFound, NotUnique};
use Flora\Schema\{IndexDefinition, IndexField};
use Flora\Support\Json;
use Flora\Value\{Branch, CollectionRef, JoinItem, JoinSet, Ref, Set, SetRef};

/**
 * PostgreSQL driver.
 *
 * Each collection is a table (id BIGINT PRIMARY KEY, ts BIGINT, data JSONB). PostgreSQL cannot index virtual
 * generated columns, so each Flora index is an expression index over the JSONB paths of its terms and values, which
 * works the same way: nothing is stored in the table, and queries on these exact expressions use the index.
 *
 * Fields are compared as JSONB, so they keep their JSON type: 1 and "1" are different terms, and numbers sort as
 * numbers. Missing values sort as JSON null, before everything else.
 *
 * An index unrolling fields has a table of entries (id, ts, t0..., v0...), kept up to date by every write.
 */
final readonly class PostgresDriver implements Driver
{

    use ManagesSchema;

    private const string UNDEFINED_TABLE = '42P01';

    private const string DUPLICATE_TABLE = '42P07';

    private const string UNIQUE_VIOLATION = '23505';

    private const string FOREIGN_KEY_VIOLATION = '23503';

    private const string SERIALIZATION_FAILURE = '40001';

    private const string DEADLOCK_DETECTED = '40P01';

    private const string LOCK_NOT_AVAILABLE = '55P03';

    /** How many documents a statement fills the entries of, when an index unrolling fields is created. */
    private const int BATCH = 100;

    /** @var ArrayObject<string, list<IndexDefinition>> the indexes unrolling fields of each collection, during a transaction */
    private ArrayObject $unrolling;

    public function __construct(private PDO $pdo)
    {
        /** @var ArrayObject<string, list<IndexDefinition>> $unrolling */
        $unrolling = new ArrayObject();
        $this->unrolling = $unrolling;

        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            throw new InvalidArgument('PostgresDriver needs a pgsql PDO connection.');
        }

        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function install(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS flora_collections (
                name TEXT PRIMARY KEY,
                ts BIGINT NOT NULL,
                history_days INTEGER
            );
            CREATE TABLE IF NOT EXISTS flora_indexes (
                name TEXT PRIMARY KEY,
                collection TEXT NOT NULL REFERENCES flora_collections (name),
                definition JSONB NOT NULL,
                ts BIGINT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS flora_functions (
                name TEXT PRIMARY KEY,
                body TEXT NOT NULL,
                ts BIGINT NOT NULL
            );
            SQL);
    }

    public function transaction(callable $callback): mixed
    {
        // Queries run inside a transaction opened by the application become part of it.
        if ($this->pdo->inTransaction()) {
            return $callback();
        }

        $this->pdo->beginTransaction();
        $this->unrolling->exchangeArray([]);
        try {
            $result = $callback();
            $this->pdo->commit();

            return $result;
        } catch (Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
    }

    public function createCollection(string $name, int $ts, ?int $historyDays = null): bool
    {
        $inserted = $this->execute(
            'INSERT INTO flora_collections (name, ts, history_days) VALUES (:name, :ts, :days) ON CONFLICT (name) DO NOTHING',
            ['name' => $name, 'ts' => $ts, 'days' => $historyDays],
        )->rowCount();

        if ($inserted === 0) {
            return false;
        }

        $this->execute(sprintf(
            "CREATE TABLE %s (id BIGINT PRIMARY KEY, ts BIGINT NOT NULL, data JSONB NOT NULL DEFAULT '{}')",
            self::identifier($name),
        ));
        $this->execute(sprintf(
            'CREATE TABLE %s (id BIGINT NOT NULL, ts BIGINT NOT NULL, action TEXT NOT NULL, data JSONB NOT NULL, PRIMARY KEY (id, ts))',
            self::history($name),
        ));

        return true;
    }

    public function createIndex(IndexDefinition $index, int $ts): bool
    {
        $inserted = $this->execute(
            'INSERT INTO flora_indexes (name, collection, definition, ts) VALUES (:name, :collection, CAST(:definition AS jsonb), :ts) ON CONFLICT (name) DO NOTHING',
            ['name' => $index->name, 'collection' => $index->source->name, 'definition' => Json::encode($index->toArray()), 'ts' => $ts],
        )->rowCount();

        if ($inserted === 0) {
            return false;
        }

        if ($index->unrolls()) {
            $this->createEntries($index);
        }

        // The column order matches the ORDER BY of scan(), so reading an index is a single range scan.
        $columns = [
            ...array_map(fn (int $i): string => $this->termOf($index, $i), array_keys($index->terms)),
            ...array_map(fn (int $i): string => $this->valueOf($index, $i) . ($index->values[$i]->reverse ? ' DESC' : ''), array_keys($index->values)),
        ];

        // A unique index constrains terms and values together; others end with the ref so entries are fully sorted.
        if (!$index->unique) {
            $columns[] = 'id' . ($index->refReversed() ? ' DESC' : '');
        }

        $this->execute(sprintf(
            'CREATE %sINDEX %s ON %s (%s)',
            $index->unique ? 'UNIQUE ' : '',
            self::identifier('flora_idx_' . $index->name),
            $this->table($index),
            implode(', ', $columns),
        ));

        if ($index->unrolls()) {
            $rows = Row::all($this->execute(sprintf('SELECT id, ts, data FROM %s', self::identifier($index->source->name))));
            foreach (array_chunk($rows, self::BATCH) as $batch) {
                $this->writeEntries($index, ...array_map(self::document(...), $batch));
            }
            $this->unrolling->offsetUnset($index->source->name);
        }

        return true;
    }

    public function findIndex(string $name): ?IndexDefinition
    {
        $definition = $this->execute('SELECT definition FROM flora_indexes WHERE name = :name', ['name' => $name])->fetchColumn();

        return $definition === false ? null : IndexDefinition::fromJson(Row::toString($definition));
    }

    public function deleteIndex(string $name): ?int
    {
        $index = $this->findIndex($name);
        if ($index === null) {
            return null;
        }

        $ts = (int) $this->execute('DELETE FROM flora_indexes WHERE name = :name RETURNING ts', ['name' => $name])->fetchColumn();

        // The table of entries takes its indexes with it.
        if ($index->unrolls()) {
            $this->dropTables(self::identifier('flora_entries_' . $name));
            $this->unrolling->offsetUnset($index->source->name);
        } else {
            $this->execute(sprintf('DROP INDEX %s', self::identifier('flora_idx_' . $name)));
        }

        return $ts;
    }

    public function find(string $collection, string $id, bool $lock = false, ?int $at = null): ?StoredDocument
    {
        if ($at !== null) {
            // The last event until then, unless the document was deleted.
            $row = $this->execute(
                sprintf('SELECT id, ts, action, data FROM %s WHERE id = :id AND ts <= :at ORDER BY ts DESC LIMIT 1', self::history($collection)),
                ['id' => (int) $id, 'at' => $at],
                $collection,
            )->fetch(PDO::FETCH_ASSOC);

            return $row === false || Row::of($row)['action'] === 'delete' ? null : self::document($row);
        }

        $row = $this->execute(
            sprintf('SELECT id, ts, data FROM %s WHERE id = :id%s', self::identifier($collection), $lock ? ' FOR UPDATE' : ''),
            ['id' => (int) $id],
            $collection,
        )->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::document($row);
    }

    public function insert(string $collection, string $id, int $ts, array $data): bool
    {
        // Only a clash on the primary key is ignored: a clash on a unique index still throws NotUnique.
        $inserted = $this->execute(
            sprintf('INSERT INTO %s (id, ts, data) VALUES (:id, :ts, CAST(:data AS jsonb)) ON CONFLICT (id) DO NOTHING', self::identifier($collection)),
            ['id' => (int) $id, 'ts' => $ts, 'data' => Json::encodeObject($data)],
            $collection,
        )->rowCount() === 1;

        if ($inserted) {
            $this->record($collection, $id, $ts, 'create', $data);
            foreach ($this->unrolling($collection) as $index) {
                $this->writeEntries($index, new StoredDocument($id, $ts, $data));
            }
        }

        return $inserted;
    }

    public function replace(string $collection, string $id, int $ts, array $data): bool
    {
        $updated = $this->execute(
            sprintf('UPDATE %s SET ts = :ts, data = CAST(:data AS jsonb) WHERE id = :id', self::identifier($collection)),
            ['id' => (int) $id, 'ts' => $ts, 'data' => Json::encodeObject($data)],
            $collection,
        )->rowCount() === 1;

        if ($updated) {
            $this->record($collection, $id, $ts, 'update', $data);
            foreach ($this->unrolling($collection) as $index) {
                $this->deleteEntries($index, $id);
                $this->writeEntries($index, new StoredDocument($id, $ts, $data));
            }
        }

        return $updated;
    }

    public function delete(string $collection, string $id, int $ts): ?StoredDocument
    {
        $row = $this->execute(
            sprintf('DELETE FROM %s WHERE id = :id RETURNING id, ts, data', self::identifier($collection)),
            ['id' => (int) $id],
            $collection,
        )->fetch(PDO::FETCH_ASSOC);

        if ($row !== false) {
            $this->record($collection, $id, $ts, 'delete', self::document($row)->data);
            foreach ($this->unrolling($collection) as $index) {
                $this->deleteEntries($index, $id);
            }
        }

        return $row === false ? null : self::document($row);
    }

    public function scan(Set $set, ?array $cursor, bool $inclusive, bool $backward, int $limit, ?int $at = null): array
    {
        $params = new Parameters($at);
        $branches = array_map(
            fn (Branch $branch): string => $this->branch($branch, $params, $cursor, $inclusive, $backward, $limit),
            $set->branches(),
        );

        $order = [];
        foreach ($set->values() as $i => $field) {
            $order[] = sprintf('v%d%s', $i, $field->reverse !== $backward ? ' DESC' : '');
        }
        $refs = IndexDefinition::refReversedAfter($set->values()) !== $backward ? ' DESC' : '';
        $order[] = 'id' . $refs;
        $order[] = 'c' . $refs;

        $entries = [];
        foreach ($this->execute(Union::of($branches, $order), $params->all(), $set->collections()[0]->name) as $row) {
            $row = Row::of($row);
            $collection = new CollectionRef(Row::string($row, 'c'));
            $values = [];
            foreach ($set->values() as $i => $field) {
                $values[] = match (true) {
                    $field->isRef() => new Ref($collection, Row::string($row, 'v' . $i)),
                    $field->isTs() => Row::int($row, 'v' . $i),
                    default => Row::json($row, 'v' . $i),
                };
            }

            $entries[] = new IndexEntry(new Ref($collection, Row::string($row, 'id')), $values);
        }

        return count($branches) === 1 ? $entries : IndexEntry::distinct($entries, $limit);
    }

    public function count(Set $set, ?int $at = null): int
    {
        $params = new Parameters($at);
        $branches = $set->branches();

        if (count($branches) === 1 && !self::distinct($branches[0])) {
            $index = $branches[0]->matches[0]->index;
            $where = $this->conditions($branches[0], $index, $params, driving: true);
            $sql = sprintf('SELECT COUNT(*) FROM %s AS %s%s', $this->table($index, $params), $params->alias(), $where === [] ? '' : ' WHERE ' . implode(' AND ', $where));
        } else {
            // Entries found in several branches, or several times by a join, count once.
            $rows = array_map(fn (Branch $branch): string => $this->rows($branch, $params, distinct: true, collection: true), $branches);
            $sql = sprintf('SELECT COUNT(*) FROM (%s) AS flora_entries', implode(' UNION ', $rows));
        }

        return (int) $this->execute($sql, $params->all(), $set->collections()[0]->name)->fetchColumn();
    }

    public function events(Ref $document, ?array $cursor, bool $inclusive, bool $backward, int $limit, ?int $at = null): array
    {
        $params = new Parameters();
        $where = [sprintf('id = %s', $params->add((int) $document->id))];
        if ($at !== null) {
            $where[] = sprintf('ts <= %s', $params->add($at));
        }
        if ($cursor !== null) {
            $where[] = sprintf('ts %s%s %s', $backward ? '<' : '>', $inclusive ? '=' : '', $params->add(is_int($cursor[0]) ? $cursor[0] : throw InvalidArgument::expected('An events cursor', 'a time followed by a ref', $cursor)));
        }

        $rows = Row::all($this->execute(
            sprintf('SELECT ts, action, data FROM %s WHERE %s ORDER BY ts%s LIMIT %d', self::history($document->collection->name), implode(' AND ', $where), $backward ? ' DESC' : '', $limit),
            $params->all(),
            $document->collection->name,
        ));

        return array_map(static fn (array $row): IndexEntry => new IndexEntry($document, [Row::int($row, 'ts')], [
            'ts' => Row::int($row, 'ts'),
            'action' => Row::string($row, 'action'),
            'document' => $document,
            'data' => Row::object($row, 'data'),
        ]), $rows);
    }

    public function insertEvent(Ref $document, int $ts, string $action, array $data): void
    {
        $collection = $document->collection->name;
        $id = ['id' => (int) $document->id, 'ts' => $ts];

        $this->execute(sprintf('DELETE FROM %s WHERE id = :id AND ts = :ts', self::history($collection)), $id, $collection);
        $this->execute(
            sprintf('INSERT INTO %s (id, ts, action, data) VALUES (:id, :ts, :action, CAST(:data AS jsonb))', self::history($collection)),
            [...$id, 'action' => $action, 'data' => Json::encodeObject($data)],
            $collection,
        );

        $this->resync($collection, $document->id);
    }

    public function removeEvent(Ref $document, int $ts, string $action): bool
    {
        $collection = $document->collection->name;
        $removed = $this->execute(
            sprintf('DELETE FROM %s WHERE id = :id AND ts = :ts AND action = :action', self::history($collection)),
            ['id' => (int) $document->id, 'ts' => $ts, 'action' => $action],
            $collection,
        )->rowCount() === 1;

        if ($removed) {
            $this->resync($collection, $document->id);
        }

        return $removed;
    }

    public function versions(Set $set, ?int $at = null): array
    {
        $params = new Parameters($at, versions: true);
        $values = array_keys($set->values());

        // The entries of the set in each version, then every version of the documents which were in the set.
        $members = fn (): string => implode(' UNION ', array_map(fn (Branch $branch): string => $this->member($branch, $params), $set->branches()));
        $sql = sprintf(
            'SELECT version.id, version.ts, member.id AS member%s FROM %s AS version'
            . ' LEFT JOIN (%s) AS member ON member.id = version.id AND member.ts = version.ts'
            . ' WHERE version.id IN (SELECT id FROM (%s) AS ever)%s ORDER BY version.id, version.ts',
            implode('', array_map(static fn (int $i): string => sprintf(', member.v%d', $i), $values)),
            self::history($set->collections()[0]->name),
            $members(),
            $members(),
            $at === null ? '' : ' AND version.ts <= ' . $params->add($at),
        );

        return self::versionsOf($set, Row::all($this->execute($sql, $params->all(), $set->collections()[0]->name)));
    }

    /**
     * The SQL reading the entries of a branch in every version of the documents: their id, time and values.
     */
    private function member(Branch $branch, Parameters $params): string
    {
        $index = $branch->matches[0]->index;
        $where = $this->conditions($branch, $index, $params, driving: true);

        return sprintf(
            'SELECT %s FROM %s AS %s%s',
            implode(', ', ['id', 'ts', ...array_map(fn (int $i): string => sprintf('%s AS v%d', $this->valueOf($index, $i), $i), array_keys($index->values))]),
            $this->table($index, $params),
            $params->alias(),
            $where === [] ? '' : ' WHERE ' . implode(' AND ', $where),
        );
    }

    /**
     * Groups the rows of versions() by version: a row per entry of the version in the set, or one without when it has
     * none.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array{ref: Ref, ts: int, entries: list<list<mixed>>}>
     */
    private static function versionsOf(Set $set, array $rows): array
    {
        $versions = [];
        foreach ($rows as $row) {
            $key = Row::string($row, 'id') . '@' . Row::int($row, 'ts');
            $versions[$key] ??= ['ref' => new Ref($set->collections()[0], Row::string($row, 'id')), 'ts' => Row::int($row, 'ts'), 'entries' => []];

            if ($row['member'] !== null) {
                $versions[$key]['entries'][] = array_map(static fn (IndexField $field, int $i): mixed => match (true) {
                    $field->isRef() => new Ref($set->collections()[0], Row::string($row, 'v' . $i)),
                    $field->isTs() => Row::int($row, 'v' . $i),
                    default => ($row['v' . $i] ?? null) === null ? null : Row::json($row, 'v' . $i),
                }, $set->values(), array_keys($set->values()));
            }
        }

        return array_values($versions);
    }

    /**
     * The SQL reading a page of a branch, through the index of its first match.
     *
     * @param list<mixed>|null $cursor
     */
    private function branch(Branch $branch, Parameters $params, ?array $cursor, bool $inclusive, bool $backward, int $limit): string
    {
        $index = $branch->matches[0]->index;
        $where = $this->conditions($branch, $index, $params, driving: true);
        $keys = $this->keys($index, $params, $cursor ?? [], $backward);

        if ($cursor !== null) {
            $after = Keyset::inclusive($index->source->name, $cursor[count($cursor) - 1], $inclusive, $index->refReversed() !== $backward);
            $where[] = Keyset::after($keys, $after, rowComparison: true);
        }

        // The collection of the entries, constant in a branch: it builds their refs, and sorts the union of branches.
        $select = ['id', sprintf('%s::text AS c', $this->pdo->quote($index->source->name))];
        foreach (array_keys($index->values) as $i) {
            $select[] = sprintf('%s AS v%d', $this->valueOf($index, $i), $i);
        }

        return sprintf(
            'SELECT %s%s FROM %s AS %s%s ORDER BY %s LIMIT %d',
            self::distinct($branch) ? 'DISTINCT ' : '',
            implode(', ', $select),
            $this->table($index, $params),
            $params->alias(),
            $where === [] ? '' : ' WHERE ' . implode(' AND ', $where),
            implode(', ', array_map(static fn (array $key): string => $key['sql'] . ($key['desc'] ? ' DESC' : ''), $keys)),
            $limit,
        );
    }

    /**
     * The sort keys of an index: its values, then the ref, each with a callback binding the value of a tuple at its
     * position. Reading backward flips every direction.
     *
     * @param array<mixed> $tuple values followed by a Ref: a cursor, or a bound
     * @return list<array{sql: string, desc: bool, bind: callable(): string, position: int}>
     */
    private function keys(IndexDefinition $index, Parameters $params, array $tuple, bool $backward): array
    {
        $keys = [];
        foreach ($index->values as $i => $field) {
            $keys[] = [
                'sql' => $this->valueOf($index, $i),
                'desc' => $field->reverse !== $backward,
                'bind' => static fn (): string => self::bind($params, $field, $tuple[$i]),
                'position' => $i,
            ];
        }
        $keys[] = [
            'sql' => 'id',
            'desc' => $index->refReversed() !== $backward,
            'bind' => static fn (): string => self::bind($params, IndexField::ref(), $tuple[count($index->values)]),
            'position' => count($index->values),
        ];

        return $keys;
    }

    /**
     * The conditions for a row of an index to be an entry of the branch, with the values this index gives it: it
     * matches the terms of all the matches, with the same values in all of them, is an entry of none of the excluded
     * sets, and is within the bounds.
     *
     * Matches are checked on the row itself when they read the same rows: the first one of a branch being read, or two
     * indexes computed from the documents of a collection. Others, unrolling fields, are checked by a subquery reading
     * their own rows.
     *
     * @param bool $driving whether the row is read through the first match of the branch
     * @param list<array{sql: string, position: int}>|null $items where to collect the terms standing for the items of
     *        a join's source entry, when the branch is the body of a join
     * @return list<string>
     */
    private function conditions(Branch $branch, IndexDefinition $index, Parameters $params, bool $driving, ?array &$items = null): array
    {
        $where = [];

        // An entry of another collection has another ref: no document is an entry of both, nor of this branch.
        foreach ($branch->matches as $match) {
            if ($match->index->source->name !== $index->source->name) {
                return ['1 = 0'];
            }
        }

        foreach ($branch->matches as $k => $match) {
            $first = $driving && $k === 0;
            if (!$first && ($match->index->unrolls() || $index->unrolls())) {
                $where[] = $this->within($match, $index, $params);
                continue;
            }

            if ($match instanceof JoinSet) {
                array_push($where, ...$this->joined($match, $index, $params, $first));
                continue;
            }

            foreach ($match->index->terms as $i => $field) {
                $term = $match instanceof SetRef ? $match->terms[$i] : throw new LogicException('A match is a SetRef or a JoinSet.');
                if (!$term instanceof JoinItem) {
                    $where[] = sprintf('%s = %s', $this->termOf($match->index, $i), self::bind($params, $field, $term));
                    continue;
                }

                if ($items === null) {
                    throw JoinItem::misuse();
                }
                $items[] = ['sql' => $this->termOf($match->index, $i), 'position' => $term->position];
            }

            foreach (array_keys($match->index->values) as $i) {
                if ($this->valueOf($match->index, $i) !== $this->valueOf($index, $i)) {
                    $where[] = sprintf('%s = %s', $this->valueOf($match->index, $i), $this->valueOf($index, $i));
                }
            }
        }

        $keys = fn (array $bound, bool $backward): array => $this->keys($index, $params, $bound, $backward);
        array_push($where, ...Keyset::within($branch->bounds, $index, $keys, rowComparison: true));

        // A condition on a missing field is NULL rather than false: the document is not an entry of the set.
        foreach ($branch->excluded as $set) {
            $where[] = sprintf('NOT COALESCE(%s, FALSE)', $this->contains($set, $index, $params));
        }

        return $where;
    }

    /**
     * The condition for a row of an index to be an entry of the set, with the values this index gives it.
     */
    private function contains(Set $set, IndexDefinition $index, Parameters $params): string
    {
        return implode(' OR ', array_map(
            fn (Branch $branch): string => '(' . (implode(' AND ', $this->conditions($branch, $index, $params, driving: false)) ?: '1 = 1') . ')',
            $set->branches(),
        ));
    }

    /**
     * The condition for a row of an index to be an entry of the set, read by a subquery: its document and values are
     * those of an entry of the set.
     */
    private function within(Set $set, IndexDefinition $index, Parameters $params): string
    {
        return sprintf(
            '(%s) IN (%s)',
            implode(', ', ['id', ...array_map(fn (int $i): string => $this->valueOf($index, $i), array_keys($index->values))]),
            implode(' UNION ALL ', array_map(fn (Branch $branch): string => $this->rows($branch, $params), $set->branches())),
        );
    }

    /**
     * The SQL reading the entries of a branch, unsorted: the id of their document, and their values.
     *
     * @param bool $distinct whether to read each entry once, even if a join finds it several times
     * @param bool $collection whether to read the collection of the entries too, to tell apart entries of several
     *        collections with the same id
     */
    private function rows(Branch $branch, Parameters $params, bool $distinct = false, bool $collection = false): string
    {
        $index = $branch->matches[0]->index;
        $where = $this->conditions($branch, $index, $params, driving: true);

        return sprintf(
            'SELECT %s%s FROM %s AS %s%s',
            $distinct && self::distinct($branch) ? 'DISTINCT ' : '',
            implode(', ', [
                'id',
                ...array_map(fn (int $i): string => sprintf('%s AS v%d', $this->valueOf($index, $i), $i), array_keys($index->values)),
                ...$collection ? [sprintf('%s::text AS c', $this->pdo->quote($index->source->name))] : [],
            ]),
            $this->table($index, $params),
            $params->alias(),
            $where === [] ? '' : ' WHERE ' . implode(' AND ', $where),
        );
    }

    /**
     * Whether reading a branch can find the same entry several times: a document has several entries in an index
     * unrolling fields, and a join, matching several terms, can find several with the same values.
     */
    private static function distinct(Branch $branch): bool
    {
        return $branch->matches[0] instanceof JoinSet && $branch->matches[0]->index->unrolls();
    }

    /**
     * The conditions for a row of an index to be an entry of a join, with the values this index gives it: it is an
     * entry of the body, whose terms standing for the items of a source entry are among the items of the source
     * entries, read by a subquery independent of the row, which databases run once.
     *
     * @return list<string>
     */
    private function joined(JoinSet $join, IndexDefinition $index, Parameters $params, bool $driving): array
    {
        $items = [];
        $where = $this->conditions($join->body, $index, $params, $driving, $items);
        /** @var list<array{sql: string, position: int}> $items */

        $selects = [];
        foreach ($join->source->branches() as $branch) {
            $source = $branch->matches[0]->index;
            $conditions = $this->conditions($branch, $source, $params, driving: true);
            $selects[] = sprintf(
                'SELECT %s FROM %s AS %s%s',
                $items === [] ? '1' : implode(', ', array_map(fn (array $item): string => $this->item($source, $item['position']), $items)),
                $this->table($source, $params),
                $params->alias(),
                $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions),
            );
        }

        // A body without items is the same for all the entries: it only needs one.
        $where[] = $items === []
            ? sprintf('EXISTS (%s)', implode(' UNION ALL ', $selects))
            : sprintf('(%s) IN (%s)', implode(', ', array_column($items, 'sql')), implode(' UNION ALL ', $selects));

        return $where;
    }

    /**
     * The JSONB of an item of the entries of an index: its ref when it has no values, else one of its values.
     */
    private function item(IndexDefinition $index, int $position): string
    {
        $field = $index->values[$position] ?? null;

        return match (true) {
            $field === null || $field->isRef() => $this->refJson($index),
            $index->unrolls() => $this->valueOf($index, $position),
            default => $this->termSql($field),
        };
    }

    /**
     * The table an index is read from: its collection, or the table of its entries when it unrolls fields. Inside At(),
     * the documents of the collection then, from its history: the last event of each until that time, unless it
     * deleted the document. For the history of a set, every version of the documents.
     */
    private function table(IndexDefinition $index, ?Parameters $params = null): string
    {
        if ($params === null || !$params->past()) {
            return self::identifier($index->unrolls() ? 'flora_entries_' . $index->name : $index->source->name);
        }

        $history = self::history($index->source->name);
        $documents = $params->versions
            ? sprintf(
                "(SELECT id, ts, data FROM %s WHERE action <> 'delete'%s)",
                $history,
                $params->at === null ? '' : ' AND ts <= ' . $params->add($params->at),
            )
            : sprintf(
                "(SELECT event.id, event.ts, event.data FROM %1\$s AS event WHERE event.ts <= %2\$s AND event.action <> 'delete'"
                . ' AND NOT EXISTS (SELECT 1 FROM %1$s AS later WHERE later.id = event.id AND later.ts > event.ts AND later.ts <= %3$s))',
                $history,
                $params->add($params->at),
                $params->add($params->at),
            );

        return $index->unrolls() ? $this->unrolled($index, $documents) : $documents;
    }

    /**
     * The entries of an index unrolling fields, computed from documents (a snapshot or versions from the history) like
     * the table of its entries holds them: the same columns, an entry per item of each unrolled field, every
     * combination of them, none when an unrolled field or a term is missing, each once.
     */
    private function unrolled(IndexDefinition $index, string $documents): string
    {
        $columns = ['document.id', 'document.ts'];
        $items = [];
        $present = [];

        foreach ([...$index->terms, ...$index->values] as $k => $field) {
            if ($field->isRef() || $field->isTs()) {
                continue;
            }

            $term = $k < count($index->terms);
            $json = $field->dataPath() === [] ? 'document.data' : sprintf('(document.data #> %s)', $this->pathSql($field));
            $value = $json;
            if ($field->unroll) {
                // A list gives its items, anything else is one item.
                $items[] = sprintf(<<<'SQL'
                    CROSS JOIN LATERAL jsonb_array_elements(
                        CASE WHEN jsonb_typeof(%1$s) = 'array' THEN %1$s ELSE jsonb_build_array(%1$s) END
                    ) AS item%2$d (value)
                    SQL, $json, $k);
                $value = sprintf('item%d.value', $k);
            }

            if ($term || $field->unroll) {
                $present[] = $json . ' IS NOT NULL';
            }

            $columns[] = $term
                ? sprintf('%s AS t%d', $value, $k)
                : sprintf("COALESCE(%s, 'null'::jsonb) AS v%d", $value, $k - count($index->terms));
        }

        return sprintf(
            '(SELECT DISTINCT %s FROM %s AS document %s%s)',
            implode(', ', $columns),
            $documents,
            implode(' ', $items),
            $present === [] ? '' : ' WHERE ' . implode(' AND ', $present),
        );
    }

    /**
     * Records an event in the history of a collection. Several writes of a document in a query, sharing its time, give
     * one event: creating and updating a document is creating it, and creating and deleting it leaves no trace.
     *
     * @param array<string, mixed> $data
     */
    private function record(string $collection, string $id, int $ts, string $action, array $data): void
    {
        $history = self::history($collection);
        if ($action === 'delete') {
            $erased = $this->execute(
                sprintf("DELETE FROM %s WHERE id = :id AND ts = :ts AND action = 'create'", $history),
                ['id' => (int) $id, 'ts' => $ts],
            )->rowCount();

            if ($erased === 1) {
                return;
            }
        }

        $this->execute(
            sprintf(
                'INSERT INTO %1$s (id, ts, action, data) VALUES (:id, :ts, :action, CAST(:data AS jsonb))'
                . ' ON CONFLICT (id, ts) DO UPDATE SET data = EXCLUDED.data,'
                . " action = CASE WHEN %1\$s.action = 'create' AND EXCLUDED.action = 'update' THEN 'create' ELSE EXCLUDED.action END",
                $history,
            ),
            ['id' => (int) $id, 'ts' => $ts, 'action' => $action, 'data' => Json::encodeObject($data)],
        );
    }

    /**
     * Makes a document what the last event of its history says, after the history changed: deleted, or holding the
     * data of that event. Its entries in indexes unrolling fields follow.
     */
    private function resync(string $collection, string $id): void
    {
        $last = $this->execute(
            sprintf('SELECT ts, action, data FROM %s WHERE id = :id ORDER BY ts DESC LIMIT 1', self::history($collection)),
            ['id' => (int) $id],
            $collection,
        )->fetch(PDO::FETCH_ASSOC);

        $this->execute(sprintf('DELETE FROM %s WHERE id = :id', self::identifier($collection)), ['id' => (int) $id], $collection);
        foreach ($this->unrolling($collection) as $index) {
            $this->deleteEntries($index, $id);
        }

        if ($last === false) {
            return;
        }

        $last = Row::of($last);
        if ($last['action'] === 'delete') {
            return;
        }

        $this->execute(
            sprintf('INSERT INTO %s (id, ts, data) VALUES (:id, :ts, CAST(:data AS jsonb))', self::identifier($collection)),
            ['id' => (int) $id, 'ts' => Row::int($last, 'ts'), 'data' => Row::string($last, 'data')],
            $collection,
        );
        foreach ($this->unrolling($collection) as $index) {
            $this->writeEntries($index, new StoredDocument($id, Row::int($last, 'ts'), Row::object($last, 'data')));
        }
    }

    /**
     * Drops tables, with their indexes.
     */
    private function dropTables(string ...$tables): void
    {
        $this->execute('DROP TABLE ' . implode(', ', $tables));
    }

    private static function history(string $collection): string
    {
        return self::identifier('flora_history_' . $collection);
    }

    /**
     * The SQL of a term of an index, compared for equality.
     */
    private function termOf(IndexDefinition $index, int $i): string
    {
        $field = $index->terms[$i];

        return $index->unrolls() && !$field->isRef() && !$field->isTs() ? 't' . $i : $this->termSql($field);
    }

    /**
     * The SQL of a value of an index, sorted on.
     */
    private function valueOf(IndexDefinition $index, int $i): string
    {
        $field = $index->values[$i];

        return $index->unrolls() && !$field->isRef() && !$field->isTs() ? 'v' . $i : $this->valueSql($field);
    }

    /**
     * Creates the table of the entries of an index unrolling fields: a column per term and value read from data.
     */
    private function createEntries(IndexDefinition $index): void
    {
        $columns = ['id BIGINT NOT NULL', 'ts BIGINT NOT NULL'];
        foreach (self::stored($index) as $column) {
            $columns[] = $column . ' JSONB NOT NULL';
        }

        $table = self::identifier('flora_entries_' . $index->name);
        $this->execute(sprintf('CREATE TABLE %s (%s)', $table, implode(', ', $columns)));
        $this->execute(sprintf('CREATE INDEX %s ON %s (id)', self::identifier('flora_ids_' . $index->name), $table));
    }

    /**
     * Stores the entries of documents in the table of an index unrolling fields.
     */
    private function writeEntries(IndexDefinition $index, StoredDocument ...$documents): void
    {
        $fields = [...$index->terms, ...$index->values];
        $params = new Parameters();
        $rows = [];
        foreach ($documents as $document) {
            foreach (Entries::of($index, $document->data) as $entry) {
                $row = [$params->add((int) $document->id), $params->add($document->ts)];
                foreach ($fields as $k => $field) {
                    if (!$field->isRef() && !$field->isTs()) {
                        $row[] = sprintf('CAST(%s AS jsonb)', $params->add(Json::encode($entry[$k])));
                    }
                }
                $rows[] = '(' . implode(', ', $row) . ')';
            }
        }

        if ($rows === []) {
            return;
        }

        $this->execute(
            sprintf(
                'INSERT INTO %s (%s) VALUES %s',
                self::identifier('flora_entries_' . $index->name),
                implode(', ', ['id', 'ts', ...array_keys(self::stored($index))]),
                implode(', ', $rows),
            ),
            $params->all(),
        );
    }

    private function deleteEntries(IndexDefinition $index, string $id): void
    {
        $this->execute(sprintf('DELETE FROM %s WHERE id = :id', self::identifier('flora_entries_' . $index->name)), ['id' => (int) $id]);
    }

    /**
     * The columns of the table of entries holding terms and values, the others being read from id and ts.
     *
     * @return array<string, string> the name of each column, keyed by itself
     */
    private static function stored(IndexDefinition $index): array
    {
        $columns = [];
        foreach ($index->terms as $i => $field) {
            if (!$field->isRef() && !$field->isTs()) {
                $columns['t' . $i] = 't' . $i;
            }
        }
        foreach ($index->values as $i => $field) {
            if (!$field->isRef() && !$field->isTs()) {
                $columns['v' . $i] = 'v' . $i;
            }
        }

        return $columns;
    }

    /**
     * The indexes of a collection which unroll fields, whose entries writes keep up to date.
     *
     * @return list<IndexDefinition>
     */
    private function unrolling(string $collection): array
    {
        $indexes = $this->unrolling[$collection] ?? null;
        if ($indexes === null) {
            $definitions = Row::column($this->execute('SELECT definition FROM flora_indexes WHERE collection = :collection', ['collection' => $collection]));

            $indexes = array_values(array_filter(
                array_map(static fn (mixed $definition): IndexDefinition => IndexDefinition::fromJson(Row::toString($definition)), $definitions),
                static fn (IndexDefinition $index): bool => $index->unrolls(),
            ));
            $this->unrolling->offsetSet($collection, $indexes);
        }

        return $indexes;
    }

    /**
     * The JSONB of the ref of a document of the index's collection, the way Refs are stored in data.
     */
    private function refJson(IndexDefinition $index): string
    {
        return sprintf(
            "jsonb_build_object('@ref', jsonb_build_object('collection', %s::text, 'id', id::text))",
            $this->pdo->quote($index->source->name),
        );
    }

    /**
     * The SQL of a term, compared for equality.
     */
    private function termSql(IndexField $field): string
    {
        return match (true) {
            $field->isRef() => 'id',
            $field->isTs() => 'ts',
            $field->dataPath() === [] => 'data',
            default => sprintf('(data #> %s)', $this->pathSql($field)),
        };
    }

    /**
     * The SQL of a value, sorted on. Missing values become JSON null so that cursors can compare them.
     */
    private function valueSql(IndexField $field): string
    {
        return match (true) {
            $field->isRef() || $field->isTs() || $field->dataPath() === [] => $this->termSql($field),
            default => sprintf("COALESCE(data #> %s, 'null'::jsonb)", $this->pathSql($field)),
        };
    }

    /**
     * Paths are inlined rather than bound: a query only uses an expression index when it has the very same constants.
     */
    private function pathSql(IndexField $field): string
    {
        return sprintf('ARRAY[%s]::text[]', implode(', ', array_map(
            fn (string|int $key): string => $this->pdo->quote((string) Json::escape($key)),
            $field->dataPath(),
        )));
    }

    /**
     * Adds a parameter holding a value of the field, and returns the SQL to compare the field with.
     */
    private static function bind(Parameters $params, IndexField $field, mixed $value): string
    {
        return match (true) {
            $field->isRef() => $params->add($value instanceof Ref ? (int) $value->id : throw InvalidArgument::expected('A ref of a cursor or a bound', 'a Ref()', $value)),
            $field->isTs() => $params->add(is_int($value) ? $value : throw InvalidArgument::expected('A "ts" value', 'an integer', $value)),
            default => sprintf('CAST(%s AS jsonb)', $params->add(Json::encode($value))),
        };
    }

    /**
     * @param array<string, mixed> $params
     * @param string|null $collection the collection queried, to report it when it does not exist
     */
    private function execute(string $sql, array $params = [], ?string $collection = null): PDOStatement
    {
        try {
            $statement = $this->pdo->prepare($sql);
            foreach ($params as $name => $value) {
                $statement->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $statement->execute();

            return $statement;
        } catch (PDOException $e) {
            throw match ($e->getCode()) {
                self::UNDEFINED_TABLE => new NotFound(sprintf('Collection "%s" does not exist.', $collection ?? '?'), 0, $e),
                self::FOREIGN_KEY_VIOLATION => new NotFound('The source collection does not exist.', 0, $e),
                self::DUPLICATE_TABLE => new AlreadyExists('A table with this name already exists in the database.', 0, $e),
                self::UNIQUE_VIOLATION => new NotUnique('The document breaks the unique constraint of an index.', 0, $e),
                self::SERIALIZATION_FAILURE, self::DEADLOCK_DETECTED, self::LOCK_NOT_AVAILABLE => new Contended('The query ran into other transactions (a deadlock, a serialization failure or a lock timeout): it wrote nothing, and can be run again.', 0, $e),
                default => $e,
            };
        }
    }

    private static function identifier(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    /**
     * The document of a row holding its id, ts and data.
     */
    private static function document(mixed $row): StoredDocument
    {
        $row = Row::of($row);

        return new StoredDocument(Row::string($row, 'id'), Row::int($row, 'ts'), Row::object($row, 'data'));
    }
}
