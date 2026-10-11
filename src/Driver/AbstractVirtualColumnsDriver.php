<?php declare(strict_types=1);

namespace Flora\Driver;

use ArrayObject;
use LogicException;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;
use Flora\Exception\{InvalidArgument, NotFound, NotUnique};
use Flora\Schema\{IndexDefinition, IndexField};
use Flora\Support\Json;
use Flora\Value\{Branch, CollectionRef, JoinItem, JoinSet, Ref, Set, SetRef};

/**
 * Drivers storing indexes as indexed virtual columns (MySQL, MariaDB, SQLite).
 *
 * Each collection is a table (id BIGINT PRIMARY KEY, ts BIGINT, data JSON), and each Flora index adds virtual columns
 * to it, computed from the JSON paths of its terms and values, and an index on them:
 *
 * - a term is one column holding a key of the JSON value, equal for equal values and different for values of different
 *   types, so that 1 and "1" are different terms; when keys are cut, the JSON values are compared as well, so that
 *   matches stay exact;
 * - a value is three columns, its JSON type, its number and its string, so that entries sort like they do on
 *   PostgreSQL: null, then strings, numbers, booleans, arrays and objects, with numbers sorted as numbers.
 *
 * Fields on the ref or the timestamp use the id and ts columns.
 *
 * An index unrolling fields has a table of entries instead, with the same key columns and the JSON of each field,
 * kept up to date by every write.
 *
 * Subclasses provide the SQL that differs between databases: the collection tables, how virtual columns and indexes are
 * declared, and how JSON values are read, compared and turned into keys.
 *
 * @phpstan-type Column array{name: string, definition: string|null, key: (callable(string): string)|null, field: IndexField, json: string, kind: 'term'|'type'|'number'|'string'}
 * @phpstan-type TermColumn array{name: string, definition: string|null, key: (callable(string): string)|null, field: IndexField, json: string, kind: 'term'|'type'|'number'|'string', term: int}
 * @phpstan-type ValueColumn array{name: string, definition: string|null, key: (callable(string): string)|null, field: IndexField, json: string, kind: 'term'|'type'|'number'|'string', value: int}
 * @phpstan-type JoinItemColumn array{column: TermColumn, position: int}
 */
abstract readonly class AbstractVirtualColumnsDriver implements Driver
{

    use ManagesSchema;

    /**
     * How many documents a statement fills the entries of, when an index unrolling fields is created: enough to save
     * round trips to the database, few enough to keep statements short.
     *
     * @var positive-int
     */
    protected const int BATCH = 100;

    /** Stands for a bound JSON value in the SQL templates of keys. */
    private const string VALUE = '{{flora:value}}';

    /** Stands for the JSON value of a field in the SQL template comparing terms. */
    private const string FIELD = '{{flora:field}}';

    /** @var ArrayObject<string, list<IndexDefinition>> the indexes unrolling fields of each collection, during a transaction */
    private ArrayObject $unrolling;

    public function __construct(protected PDO $pdo)
    {
        /** @var ArrayObject<string, list<IndexDefinition>> $unrolling */
        $unrolling = new ArrayObject();
        $this->unrolling = $unrolling;

        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== $this->pdoDriver()) {
            throw new InvalidArgument(sprintf('%s needs a %s PDO connection.', static::class, $this->pdoDriver()));
        }

        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * The name of the PDO driver for this database.
     */
    abstract protected function pdoDriver(): string;

    /**
     * Throws when the server is not the database this driver is for, or is too old.
     *
     * @throws InvalidArgument
     */
    abstract protected function checkServer(): void;

    /**
     * The statement creating the table of a collection: id, ts and data.
     */
    abstract protected function createTable(string $table): string;

    /**
     * The statements adding virtual columns and an index on them to a table.
     *
     * @param list<array{name: string, definition: string}> $columns the virtual columns to add
     * @param list<string> $keys the columns of the index, with their direction
     * @return list<string>
     */
    abstract protected function indexStatements(string $table, array $columns, string $index, array $keys, bool $unique): array;

    /**
     * The statements dropping an index of a table, then columns of the table only it used.
     *
     * @param list<string> $columns
     * @return list<string>
     */
    abstract protected function dropIndexStatements(string $table, string $index, array $columns): array;

    /**
     * What ends a SELECT reading a document to update it, so that it stays locked until the end of the transaction.
     */
    abstract protected function lockForUpdate(): string;

    /**
     * Turns a database error into a Flora exception: NotFound for a missing table, AlreadyExists for a table that
     * exists, NotUnique for a duplicate key. Null leaves the error as it is.
     *
     * @param string|null $collection the collection queried, to name it in the message
     */
    abstract protected function translate(PDOException $e, ?string $collection): ?Throwable;

    /**
     * Whether a duplicate key error is about the primary key, rather than a unique index.
     */
    abstract protected function isPrimaryKeyConflict(PDOException $e): bool;

    /**
     * The SQL reading the JSON value at a path of the data column (a quoted JSON path), or NULL when it is missing.
     */
    abstract protected function extract(string $path): string;

    /**
     * The SQL building the JSON of the ref of a document of the collection (a quoted name), the way Refs are stored in
     * data: {"@ref": {"collection": ..., "id": ...}}.
     */
    abstract protected function refJson(string $collection): string;

    /**
     * Whether the database turns a row comparison such as (a, b) > (x, y) into an index range. When it does not, the
     * cursor of a page is written as (a > x) OR (a = x AND b > y), which it does.
     */
    protected function rangesOnRowValues(): bool
    {
        return false;
    }

    /**
     * The statement adding an event to the history of a collection, from the parameters :id, :ts, :action and :data,
     * or merging it with the event of the same document at the same time: its data is the new one, and its action too,
     * unless it creates the document and the new one updates it.
     */
    abstract protected function recordEvent(string $history): string;

    /**
     * Unrolls the JSON of a field: a list gives its items, anything else is one item.
     *
     * @return array{string, string} the join giving the items, named $alias, and the SQL of the JSON of an item
     */
    abstract protected function unroll(string $json, string $alias): array;

    /**
     * The SQL type of a column holding a key, or the JSON of a field.
     *
     * @param 'term'|'type'|'number'|'string'|'json'|'text' $kind
     */
    abstract protected function columnType(string $kind): string;

    /**
     * The declaration of a virtual column computed by the expression.
     *
     * @param 'term'|'type'|'number'|'string' $kind which key the column holds
     */
    abstract protected function virtualColumn(string $kind, string $expression, bool $nullable): string;

    /**
     * The SQL reading a JSON value bound to the placeholder.
     */
    abstract protected function jsonParameter(string $placeholder): string;

    /**
     * The SQL comparing two JSON values, equal when they have the same type and value whatever their formatting, or
     * null when term keys are never cut, and comparing them is enough.
     */
    abstract protected function jsonEquals(string $a, string $b): ?string;

    /**
     * A key equal for equal JSON values, and different for values of different types. NULL when the value is missing.
     */
    abstract protected function termKey(string $json): string;

    /**
     * The rank of the JSON type, in PostgreSQL's JSONB order: missing and null 0, string 1, number 2, boolean 3,
     * array 4, object 5.
     */
    abstract protected function typeKey(string $json): string;

    /**
     * The value of a number, 0 and 1 for booleans, 0 for anything else.
     */
    abstract protected function numberKey(string $json): string;

    /**
     * The value of a string, a JSON text for arrays and objects, an empty string for anything else.
     */
    abstract protected function stringKey(string $json): string;

    public function install(): void
    {
        $this->checkServer();

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS flora_collections (
                name VARCHAR(64) PRIMARY KEY,
                ts BIGINT NOT NULL,
                history_days INTEGER
            )
            SQL);
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS flora_indexes (
                name VARCHAR(64) PRIMARY KEY,
                collection VARCHAR(64) NOT NULL,
                definition JSON NOT NULL,
                ts BIGINT NOT NULL,
                FOREIGN KEY (collection) REFERENCES flora_collections (name)
            )
            SQL);
        // The JSON of a body is kept as written: a JSON column may reorder the fields of objects.
        $this->pdo->exec(sprintf(<<<'SQL'
            CREATE TABLE IF NOT EXISTS flora_functions (
                name VARCHAR(64) PRIMARY KEY,
                body %s NOT NULL,
                ts BIGINT NOT NULL
            )
            SQL, $this->columnType('text')));
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
            if ($this->inTransaction()) {
                $this->pdo->commit();
            }

            return $result;
        } catch (Throwable $e) {
            if ($this->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    public function createCollection(string $name, int $ts, ?int $historyDays = null): bool
    {
        if ($this->execute('SELECT 1 FROM flora_collections WHERE name = :name', ['name' => $name])->fetchColumn() !== false) {
            return false;
        }

        // The tables are created first: some databases cannot roll back a schema change.
        $this->alterSchema($this->createTable(self::identifier($name)));
        $this->alterSchema(sprintf(
            'CREATE TABLE %s (id BIGINT NOT NULL, ts BIGINT NOT NULL, action VARCHAR(6) NOT NULL, data %s NOT NULL, PRIMARY KEY (id, ts))',
            self::history($name),
            $this->columnType('json'),
        ));
        $this->execute('INSERT INTO flora_collections (name, ts, history_days) VALUES (:name, :ts, :days)', ['name' => $name, 'ts' => $ts, 'days' => $historyDays]);

        return true;
    }

    public function createIndex(IndexDefinition $index, int $ts): bool
    {
        if ($this->findIndex($index->name) !== null) {
            return false;
        }

        if ($this->execute('SELECT 1 FROM flora_collections WHERE name = :name', ['name' => $index->source->name])->fetchColumn() === false) {
            throw new NotFound(sprintf('Collection "%s" does not exist.', $index->source->name));
        }

        $added = [];
        $keys = [];
        foreach ([...$this->termColumns($index), ...$this->valueColumns($index)] as $column) {
            if ($column['definition'] !== null) {
                $added[] = ['name' => $column['name'], 'definition' => $column['definition']];
            }
            $keys[$column['name']] ??= $column['name'] . ($column['field']->reverse ? ' DESC' : '');
        }

        // A unique index constrains terms and values together; others end with the ref so entries are fully sorted.
        // A column cannot appear twice in an index, and a column already in it is sorted anyway.
        if (!$index->unique) {
            $keys['id'] ??= 'id' . ($index->refReversed() ? ' DESC' : '');
        }

        // The index is created first: some databases cannot roll back a schema change.
        $statements = $index->unrolls()
            ? [
                $this->createEntries($index),
                ...$this->indexStatements($this->table($index), [], self::identifier('flora_ids_' . $index->name), ['id'], false),
                ...$this->indexStatements($this->table($index), [], self::identifier('flora_idx_' . $index->name), array_values($keys), $index->unique),
            ]
            : $this->indexStatements($this->table($index), $added, self::identifier('flora_idx_' . $index->name), array_values($keys), $index->unique);
        foreach ($statements as $statement) {
            $this->alterSchema($statement);
        }
        $this->execute(
            'INSERT INTO flora_indexes (name, collection, definition, ts) VALUES (:name, :collection, :definition, :ts)',
            ['name' => $index->name, 'collection' => $index->source->name, 'definition' => Json::encode($index->toArray()), 'ts' => $ts],
        );

        if ($index->unrolls()) {
            $rows = Row::all($this->execute(sprintf('SELECT id, ts, data FROM %s', self::identifier($index->source->name))));
            foreach (array_chunk($rows, static::BATCH) as $batch) {
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

        // The definition goes first: a database committing before a schema change, like MySQL, commits its deletion
        // with the change, so that the rest of a query failing cannot bring back an index whose columns are gone.
        $ts = (int) $this->execute('SELECT ts FROM flora_indexes WHERE name = :name', ['name' => $name])->fetchColumn();
        $this->execute('DELETE FROM flora_indexes WHERE name = :name', ['name' => $name]);

        // The table of entries takes its indexes with it.
        if ($index->unrolls()) {
            $this->dropTables($this->table($index));
            $this->unrolling->offsetUnset($index->source->name);
        } else {
            $columns = array_column(array_filter(
                [...$this->termColumns($index), ...$this->valueColumns($index)],
                static fn (array $column): bool => $column['definition'] !== null,
            ), 'name');
            foreach ($this->dropIndexStatements($this->table($index), self::identifier('flora_idx_' . $name), array_values(array_unique($columns))) as $statement) {
                $this->alterSchema($statement);
            }
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
            sprintf('SELECT id, ts, data FROM %s WHERE id = :id%s', self::identifier($collection), $lock ? ' ' . $this->lockForUpdate() : ''),
            ['id' => (int) $id],
            $collection,
        )->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::document($row);
    }

    public function insert(string $collection, string $id, int $ts, array $data): bool
    {
        try {
            $this->execute(
                sprintf('INSERT INTO %s (id, ts, data) VALUES (:id, :ts, :data)', self::identifier($collection)),
                ['id' => (int) $id, 'ts' => $ts, 'data' => Json::encodeObject($data)],
                $collection,
            );
        } catch (NotUnique $e) {
            // Only a clash on the primary key means the document exists: a clash on a unique index is an error.
            if ($e->getPrevious() instanceof PDOException && $this->isPrimaryKeyConflict($e->getPrevious())) {
                return false;
            }

            throw $e;
        }

        $this->record($collection, $id, $ts, 'create', $data);
        foreach ($this->unrolling($collection) as $index) {
            $this->writeEntries($index, new StoredDocument($id, $ts, $data));
        }

        return true;
    }

    public function replace(string $collection, string $id, int $ts, array $data): bool
    {
        $updated = $this->execute(
            sprintf('UPDATE %s SET ts = :ts, data = :data WHERE id = :id', self::identifier($collection)),
            ['id' => (int) $id, 'ts' => $ts, 'data' => Json::encodeObject($data)],
            $collection,
        )->rowCount();

        // Only the rows that changed are counted, not the rows found: writing the same data twice in a query changes none.
        if ($updated === 0 && $this->find($collection, $id) === null) {
            return false;
        }

        $this->record($collection, $id, $ts, 'update', $data);
        foreach ($this->unrolling($collection) as $index) {
            $this->deleteEntries($index, $id);
            $this->writeEntries($index, new StoredDocument($id, $ts, $data));
        }

        return true;
    }

    public function delete(string $collection, string $id, int $ts): ?StoredDocument
    {
        $document = $this->find($collection, $id, lock: true);
        if ($document !== null) {
            $this->execute(sprintf('DELETE FROM %s WHERE id = :id', self::identifier($collection)), ['id' => (int) $id], $collection);
            $this->record($collection, $id, $ts, 'delete', $document->data);
            foreach ($this->unrolling($collection) as $index) {
                $this->deleteEntries($index, $id);
            }
        }

        return $document;
    }

    public function scan(Set $set, ?array $cursor, bool $inclusive, bool $backward, int $limit, ?int $at = null): array
    {
        $params = new Parameters($at);
        $branches = $set->branches();
        $sql = array_map(
            fn (Branch $branch): string => $this->branch($branch, $params, $cursor, $inclusive, $backward, $limit, count($branches) > 1 || self::distinct($branch)),
            $branches,
        );

        // The union is sorted on the key columns, selected as k0, k1...
        $order = [];
        foreach ($this->valueColumns($branches[0]->matches[0]->index) as $i => $column) {
            $order[] = sprintf('k%d%s', $i, $column['field']->reverse !== $backward ? ' DESC' : '');
        }
        $refs = IndexDefinition::refReversedAfter($set->values()) !== $backward ? ' DESC' : '';
        $order[] = 'id' . $refs;
        $order[] = 'c' . $refs;

        $entries = [];
        foreach ($this->execute(Union::of($sql, $order), $params->all(), $set->collections()[0]->name) as $row) {
            $row = Row::of($row);
            $collection = new CollectionRef(Row::string($row, 'c'));
            $values = [];
            foreach ($set->values() as $i => $field) {
                $values[] = match (true) {
                    $field->isRef() => new Ref($collection, Row::string($row, 'v' . $i)),
                    $field->isTs() => Row::int($row, 'v' . $i),
                    default => ($row['v' . $i] ?? null) === null ? null : Row::json($row, 'v' . $i),
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
            sprintf('INSERT INTO %s (id, ts, action, data) VALUES (:id, :ts, :action, :data)', self::history($collection)),
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
            implode(', ', ['id', 'ts', ...array_map(fn (int $i): string => sprintf('%s AS v%d', $this->valueJson($index, $i), $i), array_keys($index->values))]),
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
     * @param bool $selectKeys whether to select the key columns too, to sort a union of branches or distinct entries
     */
    private function branch(Branch $branch, Parameters $params, ?array $cursor, bool $inclusive, bool $backward, int $limit, bool $selectKeys): string
    {
        $index = $branch->matches[0]->index;
        $valueColumns = $this->valueColumns($index, $params->past());
        $where = $this->conditions($branch, $index, $params, driving: true);
        $keys = $this->keys($index, $params, $cursor ?? [], $backward);

        if ($cursor !== null) {
            $after = Keyset::inclusive($index->source->name, $cursor[count($cursor) - 1], $inclusive, $index->refReversed() !== $backward);
            $where[] = Keyset::after($keys, $after, $this->rangesOnRowValues());
        }

        // The collection of the entries, constant in a branch: it builds their refs, and sorts the union of branches.
        $select = ['id', sprintf('%s AS c', $this->pdo->quote($index->source->name))];
        foreach (array_keys($index->values) as $i) {
            $select[] = sprintf('%s AS v%d', $this->valueJson($index, $i), $i);
        }
        if ($selectKeys) {
            foreach ($valueColumns as $i => $column) {
                $select[] = sprintf('%s AS k%d', $column['name'], $i);
            }
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
     * The sort keys of an index: the columns of its values, then the ref, each with a callback binding the value of a
     * tuple at its position. Reading backward flips every direction.
     *
     * @param array<mixed> $tuple values followed by a Ref: a cursor, or a bound
     * @return list<array{sql: string, desc: bool, bind: callable(): string, position: int}>
     */
    private function keys(IndexDefinition $index, Parameters $params, array $tuple, bool $backward): array
    {
        $keys = [];
        foreach ($this->valueColumns($index, $params->past()) as $column) {
            $value = $tuple[$column['value']] ?? null;
            $keys[] = [
                'sql' => $column['name'],
                'desc' => $column['field']->reverse !== $backward,
                'bind' => $column['key'] === null
                    ? static fn (): string => self::bindScalar($params, $column['field'], $value)
                    : fn (): string => $this->bindJson($params, ($column['key'])(self::VALUE), $value),
                'position' => $column['value'],
            ];
        }
        $keys[] = [
            'sql' => 'id',
            'desc' => $index->refReversed() !== $backward,
            'bind' => static fn (): string => self::bindScalar($params, IndexField::ref(), $tuple[count($index->values)]),
            'position' => count($index->values),
        ];

        return $keys;
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
     * The conditions for a row of an index to be an entry of the branch, with the values this index gives it: it
     * matches the terms of all the matches, with the same values in all of them, is an entry of none of the excluded
     * sets, and is within the bounds.
     *
     * @param list<JoinItemColumn>|null $items
     *        where to collect the terms standing for the items of a join's source entry, when the branch is the body of
     *        a join
     * Matches are checked on the row itself when they read the same rows: the first one of a branch being read, or two
     * indexes computed from the documents of a collection. Others, unrolling fields, are checked by a subquery reading
     * their own rows.
     *
     * @param bool $driving whether the row is read through the first match of the branch
     * @return list<string>
     */
    private function conditions(Branch $branch, IndexDefinition $index, Parameters $params, bool $driving, ?array &$items = null): array
    {
        $past = $params->past();
        $valueColumns = $this->valueColumns($index, $past);
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

            foreach ($this->termColumns($match->index, $past) as $i => $column) {
                $term = $match instanceof SetRef ? $match->terms[$i] : throw new LogicException('A match is a SetRef or a JoinSet.');
                if (!$term instanceof JoinItem) {
                    array_push($where, ...$this->termConditions($column, $term, $params));
                    continue;
                }

                if ($items === null) {
                    throw JoinItem::misuse();
                }
                $items[] = ['column' => $column, 'position' => $term->position];
            }

            foreach ($this->valueColumns($match->index, $past) as $i => $column) {
                if ($column['name'] !== $valueColumns[$i]['name']) {
                    $where[] = sprintf('%s = %s', $column['name'], $valueColumns[$i]['name']);
                }
            }
        }

        $keys = fn (array $bound, bool $backward): array => $this->keys($index, $params, $bound, $backward);
        array_push($where, ...Keyset::within($branch->bounds, $index, $keys, $this->rangesOnRowValues()));

        // A condition on a missing field is NULL rather than false: the document is not an entry of the set.
        foreach ($branch->excluded as $set) {
            $where[] = sprintf('NOT COALESCE(%s, FALSE)', $this->contains($set, $index, $params));
        }

        return $where;
    }

    /**
     * The condition for a document to be an entry of the set, with the values the index gives it.
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
            implode(', ', ['id', ...array_column($this->valueColumns($index, $params->past()), 'name')]),
            implode(' UNION ALL ', array_map(fn (Branch $branch): string => $this->rows($branch, $params), $set->branches())),
        );
    }

    /**
     * The SQL reading the entries of a branch, unsorted: the id of their document, and the keys of their values.
     *
     * @param bool $distinct whether to read each entry once, even if a join finds it several times
     * @param bool $collection whether to read the collection of the entries too, to tell apart entries of several
     *        collections with the same id
     */
    private function rows(Branch $branch, Parameters $params, bool $distinct = false, bool $collection = false): string
    {
        $index = $branch->matches[0]->index;
        $where = $this->conditions($branch, $index, $params, driving: true);

        $columns = ['id'];
        foreach ($this->valueColumns($index, $params->past()) as $i => $column) {
            $columns[] = sprintf('%s AS k%d', $column['name'], $i);
        }
        if ($collection) {
            $columns[] = sprintf('%s AS c', $this->pdo->quote($index->source->name));
        }

        return sprintf(
            'SELECT %s%s FROM %s AS %s%s',
            $distinct && self::distinct($branch) ? 'DISTINCT ' : '',
            implode(', ', $columns),
            $this->table($index, $params),
            $params->alias(),
            $where === [] ? '' : ' WHERE ' . implode(' AND ', $where),
        );
    }

    /**
     * The conditions for a row of an index to be an entry of a join, with the values this index gives it: it is an
     * entry of the body, whose terms standing for the items of a source entry are among the items of the source
     * entries, read by a subquery independent of the row, which databases run once. They are compared on their keys:
     * where keys are cut, values longer than that only match on their beginning.
     *
     * @return list<string>
     */
    private function joined(JoinSet $join, IndexDefinition $index, Parameters $params, bool $driving): array
    {
        $items = [];
        $where = $this->conditions($join->body, $index, $params, $driving, $items);
        /** @var list<JoinItemColumn> $items */

        $selects = [];
        foreach ($join->source->branches() as $branch) {
            $source = $branch->matches[0]->index;
            $conditions = $this->conditions($branch, $source, $params, driving: true);
            $selects[] = sprintf(
                'SELECT %s FROM %s AS %s%s',
                $items === [] ? '1' : implode(', ', array_map(
                    fn (array $item): string => ($item['column']['key'] ?? throw new LogicException('Join terms are fields of data.'))($this->item($source, $item['position'])),
                    $items,
                )),
                $this->table($source, $params),
                $params->alias(),
                $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions),
            );
        }

        // A body without items is the same for all the entries: it only needs one.
        $where[] = $items === []
            ? sprintf('EXISTS (%s)', implode(' UNION ALL ', $selects))
            : sprintf(
                '(%s) IN (%s)',
                implode(', ', array_map(static fn (array $item): string => $item['column']['name'], $items)),
                implode(' UNION ALL ', $selects),
            );

        return $where;
    }

    /**
     * The JSON of an item of the entries of an index: its ref when it has no values, else one of its values.
     */
    private function item(IndexDefinition $index, int $position): string
    {
        $field = $index->values[$position] ?? null;

        return $field === null || $field->isRef() ? $this->refJson($this->pdo->quote($index->source->name)) : $this->valueJson($index, $position);
    }

    /**
     * The conditions matching a term column with a value.
     *
     * @param TermColumn $column
     * @return list<string>
     */
    private function termConditions(array $column, mixed $term, Parameters $params): array
    {
        if ($column['key'] === null) {
            return [sprintf('%s = %s', $column['name'], self::bindScalar($params, $column['field'], $term))];
        }

        // The key finds the entries through the index, comparing the JSON makes the match exact when keys are cut.
        $conditions = [sprintf('%s = %s', $column['name'], $this->bindJson($params, ($column['key'])(self::VALUE), $term))];
        $equals = $this->jsonEquals(self::FIELD, self::VALUE);
        if ($equals !== null) {
            $conditions[] = str_replace(self::FIELD, $column['json'], $this->bindJson($params, $equals, $term));
        }

        return $conditions;
    }

    /**
     * One column per term: the ref or ts column, or a virtual column holding a key of the JSON value.
     *
     * @return list<TermColumn>
     */
    private function termColumns(IndexDefinition $index, bool $past = false): array
    {
        $columns = [];
        foreach ($index->terms as $i => $field) {
            // Missing terms are NULL, so that they match nothing and never collide in a unique index.
            $columns[] = [...$this->column($index, $field, 't' . $i, 't' . $i, 'term', $this->termKey(...), nullable: true, past: $past), 'term' => $i];
        }

        return $columns;
    }

    /**
     * The sort columns of the values: the ref or ts column, or three virtual columns holding the JSON type, the number
     * and the string of the value.
     *
     * @return list<ValueColumn>
     */
    private function valueColumns(IndexDefinition $index, bool $past = false): array
    {
        $columns = [];
        foreach ($index->values as $i => $field) {
            if ($field->isRef() || $field->isTs()) {
                $columns[] = [...$this->column($index, $field, '', '', 'term', null, nullable: false), 'value' => $i];
                continue;
            }

            $columns[] = [...$this->column($index, $field, "v{$i}_type", 'v' . $i, 'type', $this->typeKey(...), nullable: false, past: $past), 'value' => $i];
            $columns[] = [...$this->column($index, $field, "v{$i}_num", 'v' . $i, 'number', $this->numberKey(...), nullable: false, past: $past), 'value' => $i];
            $columns[] = [...$this->column($index, $field, "v{$i}_str", 'v' . $i, 'string', $this->stringKey(...), nullable: false, past: $past), 'value' => $i];
        }

        return $columns;
    }

    /**
     * A column of an index. Fields on the ref or the timestamp use the id and ts columns, others a column holding a key
     * of the JSON of the field, computed by the key function: a virtual column of the collection, or a column of the
     * table of entries when the index unrolls fields, which also has a column holding the JSON of the field.
     *
     * @param string $suffix the name of the column
     * @param string $name the name of the field in a table of entries, whose JSON is in the column $name_json
     * @param 'term'|'type'|'number'|'string' $kind
     * @param (callable(string): string)|null $key
     * @param bool $past whether the column is read from a snapshot of the collection, inside At(): it has no virtual
     *        columns, and keys are computed instead
     * @return Column
     */
    private function column(IndexDefinition $index, IndexField $field, string $suffix, string $name, string $kind, ?callable $key, bool $nullable, bool $past = false): array
    {
        if ($field->isRef() || $field->isTs()) {
            $column = $field->isRef() ? 'id' : 'ts';

            return ['name' => $column, 'definition' => null, 'key' => null, 'field' => $field, 'json' => $column, 'kind' => $kind];
        }

        $key ??= throw new LogicException('A column of a field of data holds a key of it.');
        if ($past && !$index->unrolls()) {
            return ['name' => $key($this->json($field)), 'definition' => null, 'key' => $key, 'field' => $field, 'json' => $this->json($field), 'kind' => $kind];
        }

        if ($index->unrolls()) {
            return [
                'name' => self::identifier($suffix),
                'definition' => null,
                'key' => $key,
                'field' => $field,
                'json' => self::identifier($name . '_json'),
                'kind' => $kind,
            ];
        }

        return [
            'name' => self::identifier(sprintf('flora_%s_%s', $index->name, $suffix)),
            'definition' => $this->virtualColumn($kind, $key($this->json($field)), $nullable),
            'key' => $key,
            'field' => $field,
            'json' => $this->json($field),
            'kind' => $kind,
        ];
    }

    /**
     * The JSON of a value of an index: read from the document, or from the table of entries.
     */
    private function valueJson(IndexDefinition $index, int $i): string
    {
        $field = $index->values[$i];

        return match (true) {
            $field->isRef() => 'id',
            $field->isTs() => 'ts',
            $index->unrolls() => self::identifier('v' . $i . '_json'),
            default => $this->json($field),
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
        $fields = [...$index->terms, ...$index->values];
        $items = [];
        $present = [];
        $values = [];

        foreach ($fields as $k => $field) {
            if ($field->isRef() || $field->isTs()) {
                continue;
            }

            $json = $this->json($field);
            $values[$k] = $json;
            if ($field->unroll) {
                [$items[], $values[$k]] = $this->unroll($json, 'item' . $k);
            }

            if ($k < count($index->terms) || $field->unroll) {
                $present[] = $json . ' IS NOT NULL';
            }
        }

        $columns = ['document.id', 'document.ts'];
        foreach ($this->stored($index) as $column) {
            $columns[] = sprintf('%s AS %s', str_replace(self::VALUE, $values[$column['position']], $column['template']), $column['name']);
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

        $this->execute($this->recordEvent($history), ['id' => (int) $id, 'ts' => $ts, 'action' => $action, 'data' => Json::encodeObject($data)]);
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
            sprintf('INSERT INTO %s (id, ts, data) VALUES (:id, :ts, :data)', self::identifier($collection)),
            ['id' => (int) $id, 'ts' => Row::int($last, 'ts'), 'data' => Row::string($last, 'data')],
            $collection,
        );
        foreach ($this->unrolling($collection) as $index) {
            $this->writeEntries($index, new StoredDocument($id, Row::int($last, 'ts'), Row::object($last, 'data')));
        }
    }

    private static function history(string $collection): string
    {
        return self::identifier('flora_history_' . $collection);
    }

    /**
     * The statement creating the table of the entries of an index unrolling fields: its key columns, and the JSON of
     * each field read from data.
     */
    private function createEntries(IndexDefinition $index): string
    {
        $columns = ['id BIGINT NOT NULL', 'ts BIGINT NOT NULL'];
        foreach ($this->stored($index) as $column) {
            $columns[] = sprintf('%s %s', $column['name'], $this->columnType($column['kind']));
        }

        return sprintf('CREATE TABLE %s (%s)', $this->table($index), implode(', ', $columns));
    }

    /**
     * Stores the entries of documents in the table of an index unrolling fields.
     */
    private function writeEntries(IndexDefinition $index, StoredDocument ...$documents): void
    {
        $stored = $this->stored($index);
        $params = new Parameters();
        $rows = [];
        foreach ($documents as $document) {
            foreach (Entries::of($index, $document->data) as $entry) {
                $row = [$params->add((int) $document->id), $params->add($document->ts)];
                foreach ($stored as $column) {
                    $row[] = $this->bindJson($params, $column['template'], $entry[$column['position']]);
                }
                $rows[] = '(' . implode(', ', $row) . ')';
            }
        }

        if ($rows === []) {
            return;
        }

        $this->execute(
            sprintf('INSERT INTO %s (%s) VALUES %s', $this->table($index), implode(', ', ['id', 'ts', ...array_column($stored, 'name')]), implode(', ', $rows)),
            $params->all(),
        );
    }

    private function deleteEntries(IndexDefinition $index, string $id): void
    {
        $this->execute(sprintf('DELETE FROM %s WHERE id = :id', $this->table($index)), ['id' => (int) $id]);
    }

    /**
     * The columns of a table of entries read from data: the keys of each field, then its JSON.
     *
     * @return list<array{name: string, kind: 'term'|'type'|'number'|'string'|'json', template: string, position: int}> each column, with the SQL
     *         template computing it from the JSON of the field (VALUE), and the position of the field in an entry
     */
    private function stored(IndexDefinition $index): array
    {
        $columns = [];
        foreach ([...$this->termColumns($index), ...$this->valueColumns($index)] as $column) {
            if ($column['key'] === null) {
                continue;
            }

            $position = isset($column['value']) ? count($index->terms) + $column['value'] : $column['term'];
            $columns[] = ['name' => $column['name'], 'kind' => $column['kind'], 'template' => ($column['key'])(self::VALUE), 'position' => $position];
            $columns[$column['json']] = ['name' => $column['json'], 'kind' => 'json', 'template' => self::VALUE, 'position' => $position];
        }

        return array_values($columns);
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
     * The value of a field of the document: the id, the ts, or the JSON at its path (SQL NULL when it is missing).
     */
    private function json(IndexField $field): string
    {
        if ($field->isRef() || $field->isTs()) {
            return $field->isRef() ? 'id' : 'ts';
        }

        $path = '$';
        foreach ($field->dataPath() as $key) {
            $path .= is_int($key) ? sprintf('[%d]', $key) : '."' . addcslashes($key, '"\\') . '"';
        }

        return $this->extract($this->pdo->quote($path));
    }

    /**
     * Fills an SQL template in which VALUE stands for a JSON value, binding the value once for each time it is used:
     * PDO cannot use a named parameter twice in a statement when prepares are not emulated.
     */
    private function bindJson(Parameters $params, string $template, mixed $value): string
    {
        $json = Json::encode($value);
        $parts = explode(self::VALUE, $template);

        $sql = array_shift($parts);
        foreach ($parts as $part) {
            $sql .= $this->jsonParameter($params->add($json)) . $part;
        }

        return $sql;
    }

    /**
     * Adds a parameter holding the value of a ref or ts field, and returns its placeholder.
     */
    private static function bindScalar(Parameters $params, IndexField $field, mixed $value): string
    {
        return $field->isRef()
            ? $params->add($value instanceof Ref ? (int) $value->id : throw InvalidArgument::expected('A ref of a cursor or a bound', 'a Ref()', $value))
            : $params->add(is_int($value) ? $value : throw InvalidArgument::expected('A "ts" value', 'an integer', $value));
    }

    /**
     * Whether a transaction is open: a schema change may have committed it.
     *
     * @phpstan-impure
     */
    private function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    /**
     * Drops tables, with their indexes.
     */
    private function dropTables(string ...$tables): void
    {
        foreach ($tables as $table) {
            $this->alterSchema('DROP TABLE ' . $table);
        }
    }

    /**
     * Runs a schema change, then opens a new transaction if the database committed the current one, like MySQL does.
     */
    private function alterSchema(string $sql): void
    {
        try {
            $this->execute($sql);
        } finally {
            if (!$this->pdo->inTransaction()) {
                $this->pdo->beginTransaction();
            }
        }
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
            throw $this->translate($e, $collection) ?? $e;
        }
    }

    private static function identifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
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
