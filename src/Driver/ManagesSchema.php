<?php declare(strict_types=1);

namespace Flora\Driver;

use PDO;

/**
 * The settings of collections, the expiration of their history, stored functions and the names in the schema, in SQL
 * every database understands.
 *
 * @internal
 */
trait ManagesSchema
{

    public function findCollection(string $name): ?array
    {
        $row = $this->execute('SELECT name, ts, history_days FROM flora_collections WHERE name = :name', ['name' => $name])->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $row = Row::of($row);

        return ['name' => Row::string($row, 'name'), 'ts' => Row::int($row, 'ts'), 'history_days' => Row::nullableInt($row, 'history_days')];
    }

    public function deleteCollection(string $name): ?array
    {
        $collection = $this->findCollection($name);
        if ($collection === null) {
            return null;
        }

        // The indexes of the collection go with its table, but for the tables of entries of those unrolling fields.
        $tables = [self::identifier($name), self::history($name)];
        foreach (Row::column($this->execute('SELECT name FROM flora_indexes WHERE collection = :name', ['name' => $name])) as $index) {
            $index = Row::toString($index);
            if ($this->findIndex($index)?->unrolls()) {
                $tables[] = self::identifier('flora_entries_' . $index);
            }
        }

        // The definitions go first: a database committing before a schema change, like MySQL, commits their deletion
        // with the change, so that the rest of a query failing cannot bring back a collection whose tables are gone.
        $this->execute('DELETE FROM flora_indexes WHERE collection = :name', ['name' => $name]);
        $this->execute('DELETE FROM flora_collections WHERE name = :name', ['name' => $name]);
        $this->dropTables(...$tables);
        $this->unrolling->offsetUnset($name);

        return $collection;
    }

    public function indexTs(string $name): ?int
    {
        $ts = $this->execute('SELECT ts FROM flora_indexes WHERE name = :name', ['name' => $name])->fetchColumn();

        return $ts === false ? null : Row::toInt($ts);
    }

    public function schema(string $kind): array
    {
        $table = match ($kind) {
            'collections' => 'flora_collections',
            'indexes' => 'flora_indexes',
            'functions' => 'flora_functions',
        };

        return array_map(Row::toString(...), Row::column($this->execute(sprintf('SELECT name FROM %s ORDER BY name', $table))));
    }

    public function createFunction(string $name, string $body, int $ts): bool
    {
        if ($this->findFunction($name) !== null) {
            return false;
        }

        $this->execute('INSERT INTO flora_functions (name, body, ts) VALUES (:name, :body, :ts)', ['name' => $name, 'body' => $body, 'ts' => $ts]);

        return true;
    }

    public function findFunction(string $name): ?array
    {
        $row = $this->execute('SELECT name, body, ts FROM flora_functions WHERE name = :name', ['name' => $name])->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $row = Row::of($row);

        return ['name' => Row::string($row, 'name'), 'body' => Row::string($row, 'body'), 'ts' => Row::int($row, 'ts')];
    }

    public function updateFunction(string $name, string $newName, string $body, int $ts): bool
    {
        if ($this->findFunction($name) === null || ($newName !== $name && $this->findFunction($newName) !== null)) {
            return false;
        }

        $this->execute(
            'UPDATE flora_functions SET name = :new_name, body = :body, ts = :ts WHERE name = :name',
            ['name' => $name, 'new_name' => $newName, 'body' => $body, 'ts' => $ts],
        );

        return true;
    }

    public function deleteFunction(string $name): ?array
    {
        $function = $this->findFunction($name);
        if ($function !== null) {
            $this->execute('DELETE FROM flora_functions WHERE name = :name', ['name' => $name]);
        }

        return $function;
    }

    public function updateCollection(string $name, int $ts, ?int $historyDays): bool
    {
        if ($this->findCollection($name) === null) {
            return false;
        }

        $this->execute(
            'UPDATE flora_collections SET ts = :ts, history_days = :days WHERE name = :name',
            ['name' => $name, 'ts' => $ts, 'days' => $historyDays],
        );

        return true;
    }

    public function historyTimes(array $collections, ?int $at = null): array
    {
        $params = [];
        $selects = [];
        foreach ($collections as $i => $collection) {
            $selects[] = sprintf('SELECT ts FROM %s%s', self::history($collection), $at === null ? '' : ' WHERE ts <= :at' . $i);
            if ($at !== null) {
                $params['at' . $i] = $at;
            }
        }

        // UNION removes the times several collections share.
        $times = $this->execute(sprintf('SELECT ts FROM (%s) AS times ORDER BY ts', implode(' UNION ', $selects)), $params, $collections[0]);

        return array_map(Row::toInt(...), Row::column($times));
    }

    public function expireHistory(int $now): int
    {
        $removed = 0;
        $collections = Row::all($this->execute('SELECT name, history_days FROM flora_collections WHERE history_days IS NOT NULL'));

        foreach ($collections as $row) {
            $collection = Row::string($row, 'name');
            $history = self::history($collection);
            $cutoff = $now - Row::int($row, 'history_days') * 86_400_000_000;

            // The events of a document before the last one until the cutoff: that one is its version then, and reads at
            // that time still need it. The table is read through a derived table, which MySQL requires to delete from it.
            $removed += $this->execute(
                sprintf(
                    'DELETE FROM %1$s WHERE (id, ts) IN (SELECT id, ts FROM (SELECT DISTINCT event.id, event.ts FROM %1$s AS event'
                    . ' WHERE event.ts < :cutoff AND EXISTS (SELECT 1 FROM %1$s AS later WHERE later.id = event.id AND later.ts > event.ts AND later.ts <= :until)) AS expired)',
                    $history,
                ),
                ['cutoff' => $cutoff, 'until' => $cutoff],
                $collection,
            )->rowCount();

            // Deletions until the cutoff: without events, a document does not exist either.
            $removed += $this->execute(
                sprintf("DELETE FROM %s WHERE ts <= :cutoff AND action = 'delete'", $history),
                ['cutoff' => $cutoff],
                $collection,
            )->rowCount();
        }

        return $removed;
    }
}
