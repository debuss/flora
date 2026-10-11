<?php declare(strict_types=1);

namespace Flora\Driver;

use Flora\Exception\{NotFound, NotUnique};
use Flora\Schema\IndexDefinition;
use Flora\Value\{Ref, Set};

/**
 * What a database must provide to run Flora queries.
 *
 * Collections are tables with a BIGINT primary key "id", a BIGINT "ts" (microseconds of the last write) and a JSON
 * "data" column, and keep the history of their documents: each write adds an event (create, update or delete) at its
 * time, several writes of a document in a query giving one. Indexes are built from the JSON paths of their terms and values, through whatever the database
 * offers (expression indexes, indexed virtual columns...).
 *
 * Methods referring to a collection that does not exist throw NotFound, and writes breaking a unique index throw
 * NotUnique.
 */
interface Driver
{

    /**
     * Creates the tables Flora keeps its schema in. Safe to call more than once.
     */
    public function install(): void;

    /**
     * Runs the callback in a transaction, committed when it returns and rolled back when it throws.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed;

    /**
     * @param int|null $historyDays how many days the history of its documents is kept for, null for ever
     * @return bool false when the collection already exists
     */
    public function createCollection(string $name, int $ts, ?int $historyDays = null): bool;

    /**
     * @return array{name: string, ts: int, history_days: int|null}|null
     */
    public function findCollection(string $name): ?array;

    /**
     * @param int|null $historyDays how many days the history of its documents is kept for, null for ever
     * @return bool false when the collection does not exist
     */
    public function updateCollection(string $name, int $ts, ?int $historyDays): bool;

    /**
     * Removes the events older than the history of their collections keeps, keeping for each document the version it
     * had then, so that the documents and reads in the past within that time do not change.
     *
     * @param int $now the time to count the days kept from, in microseconds
     * @return int how many events were removed
     */
    public function expireHistory(int $now): int;

    /**
     * Creates the index and fills it with the documents already in its source collection.
     *
     * @return bool false when an index with this name already exists
     *
     * @throws NotFound when the source collection does not exist
     * @throws NotUnique when the index is unique and existing documents already break it
     */
    public function createIndex(IndexDefinition $index, int $ts): bool;

    public function findIndex(string $name): ?IndexDefinition;

    /**
     * @return int|null the time an index was created at, or null when it does not exist
     */
    public function indexTs(string $name): ?int;

    /**
     * The names of the collections, indexes or functions, in order.
     *
     * @param 'collections'|'indexes'|'functions' $kind
     * @return list<string>
     */
    public function schema(string $kind): array;

    /**
     * Stores a function: its body is the JSON of a Lambda() (Serializer).
     *
     * @return bool false when a function with this name already exists
     */
    public function createFunction(string $name, string $body, int $ts): bool;

    /**
     * @return array{name: string, body: string, ts: int}|null
     */
    public function findFunction(string $name): ?array;

    /**
     * Renames a function, or changes its body.
     *
     * @return bool false when the function does not exist, or the new name is taken
     */
    public function updateFunction(string $name, string $newName, string $body, int $ts): bool;

    /**
     * @return array{name: string, body: string, ts: int}|null the function as it was, or null when it does not exist
     */
    public function deleteFunction(string $name): ?array;

    /**
     * Deletes an index: its definition, and the columns, indexes or table of entries it was read from. The documents of
     * its collection stay.
     *
     * @return int|null the time the index was created at, or null when it does not exist
     */
    public function deleteIndex(string $name): ?int;

    /**
     * Deletes a collection: its documents, their history, and its indexes.
     *
     * @return array{name: string, ts: int, history_days: int|null}|null the collection as it was, or null when it does
     *         not exist
     */
    public function deleteCollection(string $name): ?array;

    /**
     * @param bool $lock whether to lock the document until the end of the transaction, before updating it
     * @param int|null $at the time to read the document at, from its history
     */
    public function find(string $collection, string $id, bool $lock = false, ?int $at = null): ?StoredDocument;

    /**
     * @param array<string, mixed> $data
     * @return bool false when a document with this id already exists
     */
    public function insert(string $collection, string $id, int $ts, array $data): bool;

    /**
     * @param array<string, mixed> $data
     * @return bool false when the document does not exist
     */
    public function replace(string $collection, string $id, int $ts, array $data): bool;

    /**
     * @param int $ts the time of the deletion, for the history
     * @return StoredDocument|null the deleted document, or null when it did not exist
     */
    public function delete(string $collection, string $id, int $ts): ?StoredDocument;

    /**
     * Reads the entries of a set, in index order, each once.
     *
     * The set is read as a union of branches (Set::branches()): an entry belongs to a branch when its document matches
     * all its index matches with the same values, and is an entry of none of its excluded sets. Entries are sorted by
     * the first match.
     *
     * @param list<mixed>|null $cursor values followed by a Ref, where to start from
     * @param bool $inclusive whether the entry at the cursor is part of the result
     * @param bool $backward whether to read in reverse index order
     * @param int|null $at the time to read the set at, from the history of its collection
     * @return list<IndexEntry>
     */
    public function scan(Set $set, ?array $cursor, bool $inclusive, bool $backward, int $limit, ?int $at = null): array;

    /**
     * How many entries a set has, each counted once.
     *
     * @param int|null $at the time to count the set at, from the history of its collection
     */
    public function count(Set $set, ?int $at = null): int;

    /**
     * The versions of the documents which were entries of a set at some time, each with the values of the entries it
     * then had in the set (none when it was not in the set, or deleted), by document then time: Events() of the set
     * are the differences between versions in a row.
     *
     * @param int|null $at the time to read versions until
     * @return list<array{ref: Ref, ts: int, entries: list<list<mixed>>}>
     */
    public function versions(Set $set, ?int $at = null): array;

    /**
     * The times collections were written at, from their history, in order: the times a set reading them can change.
     *
     * @param non-empty-list<string> $collections
     * @param int|null $at the time to read history until
     * @return list<int>
     */
    public function historyTimes(array $collections, ?int $at = null): array;

    /**
     * Reads the events of a document, by time: an entry per write, whose item is the event, and value the time.
     *
     * @param list<mixed>|null $cursor a time followed by the ref of the document, where to start from
     * @param int|null $at the time to read events until
     * @return list<IndexEntry>
     */
    public function events(Ref $document, ?array $cursor, bool $inclusive, bool $backward, int $limit, ?int $at = null): array;

    /**
     * Adds an event to the history of a document, replacing the event at the same time if there is one, then makes
     * the document what its last event says: deleted, or holding the data of that event.
     *
     * @param 'create'|'update'|'delete' $action
     * @param array<string, mixed> $data the whole document after the event
     */
    public function insertEvent(Ref $document, int $ts, string $action, array $data): void;

    /**
     * Removes an event from the history of a document, then makes the document what its last event says: deleted when
     * there is none left, or when it deletes the document.
     *
     * @return bool false when there is no such event
     */
    public function removeEvent(Ref $document, int $ts, string $action): bool;
}
