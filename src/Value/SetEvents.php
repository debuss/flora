<?php declare(strict_types=1);

namespace Flora\Value;

use LogicException;
use Flora\Context;
use Flora\Driver\IndexEntry;
use Flora\Exception\InvalidArgument;
use Flora\Schema\IndexField;
use Flora\Support\Json;

/**
 * The history of a set, as returned by Events(): an event {ts, action, document, data} each time an entry was added to
 * the set or removed from it, data holding the values of the entry. Changing the values of a document removes its entry
 * and adds the new one, at the same time.
 *
 * The history is computed as each page is read, in one of two ways:
 *
 * - when the membership of a document only depends on its own versions (a set of one collection, without joins), from
 *   these versions: the driver gives the entries of each, and two versions in a row differ by the events between them;
 * - otherwise (joins, whose entries depend on other documents, or several collections), from the set itself as it was
 *   each time one of the collections it reads was written: two of these states in a row differ by the events between
 *   them. This costs a read of the whole set for each of these times.
 *
 * Indexes unrolling fields cannot be read in the past, so sets reading them have no history yet.
 *
 * Events are sorted by time, then document, removals first, then values; cursors hold the three of them, then the ref.
 */
final readonly class SetEvents implements Readable
{

    public function __construct(public Set $set) {}

    public function collections(): array
    {
        return $this->set->collections();
    }

    public function values(): array
    {
        // The parts of a cursor before the ref: the time, the action and the values of the event.
        return [new IndexField(['ts']), new IndexField(['data', 'action']), new IndexField(['data', 'values'])];
    }

    /**
     * Reads the events of the set in order, like Driver::scan() reads a set.
     *
     * @param list<mixed>|null $cursor
     * @return list<IndexEntry>
     */
    public function scan(Context $context, ?array $cursor, bool $inclusive, bool $backward, int $limit): array
    {
        $events = $this->events($context);
        if ($backward) {
            $events = array_reverse($events);
        }

        if ($cursor !== null) {
            $events = array_values(array_filter($events, static function (IndexEntry $event) use ($cursor, $inclusive, $backward): bool {
                $order = self::compare($event->cursor(), $cursor) * ($backward ? -1 : 1);

                return $order > 0 || ($inclusive && $order === 0);
            }));
        }

        return array_slice($events, 0, $limit);
    }

    /**
     * @return list<IndexEntry>
     */
    public function events(Context $context): array
    {
        $events = self::readsOtherDocuments($this->set) || count($this->set->collections()) > 1
            ? $this->eventsFromStates($context)
            : $this->eventsFromVersions($context);

        usort($events, static fn (IndexEntry $a, IndexEntry $b): int => self::compare($a->cursor(), $b->cursor()));

        return $events;
    }

    /**
     * The events between the versions in a row of each document.
     *
     * @return list<IndexEntry>
     */
    private function eventsFromVersions(Context $context): array
    {
        $events = [];
        $previous = [];
        $document = null;

        foreach ($context->driver->versions($this->set, $context->at) as $version) {
            if ($version['ref']->id !== $document) {
                $document = $version['ref']->id;
                $previous = [];
            }

            $current = [];
            foreach ($version['entries'] as $values) {
                $current[Json::encode($values)] = $values;
            }

            foreach (array_diff_key($previous, $current) as $values) {
                $events[] = self::event($version['ts'], 'remove', $version['ref'], $values);
            }
            foreach (array_diff_key($current, $previous) as $values) {
                $events[] = self::event($version['ts'], 'add', $version['ref'], $values);
            }

            $previous = $current;
        }

        return $events;
    }

    /**
     * The events between the states in a row of the set: as it was each time one of the collections it reads was
     * written.
     *
     * @return list<IndexEntry>
     */
    private function eventsFromStates(Context $context): array
    {
        $events = [];
        $previous = [];

        foreach ($context->driver->historyTimes(self::reads($this->set), $context->at) as $ts) {
            $current = [];
            foreach ($context->withAt($ts)->entries($this->set) as $entry) {
                $ref = $entry->ref;
                if ($ref instanceof Ref) {
                    $current[$ref->collection->name . '/' . $ref->id . ' ' . Json::encode($entry->values)] = [$ref, $entry->values];
                }
            }

            foreach (array_diff_key($previous, $current) as [$ref, $values]) {
                $events[] = self::event($ts, 'remove', $ref, $values);
            }
            foreach (array_diff_key($current, $previous) as [$ref, $values]) {
                $events[] = self::event($ts, 'add', $ref, $values);
            }

            $previous = $current;
        }

        return $events;
    }

    /**
     * @param list<mixed> $values
     */
    private static function event(int $ts, string $action, Ref $document, array $values): IndexEntry
    {
        return new IndexEntry(
            $document,
            [$ts, $action, $values],
            ['ts' => $ts, 'action' => $action, 'document' => $document, 'data' => $values],
        );
    }

    /**
     * The order of two cursors: [ts, action, values, ref].
     *
     * @param list<mixed> $a
     * @param list<mixed> $b
     */
    private static function compare(array $a, array $b): int
    {
        if (count($a) !== 4 || count($b) !== 4 || !$a[3] instanceof Ref || !$b[3] instanceof Ref) {
            throw InvalidArgument::expected('A cursor of the events of a set', 'a time, an action, values and a ref', $b);
        }

        return [$a[0], strlen($a[3]->id), $a[3]->id, $a[3]->collection->name, $a[1] === 'remove' ? 0 : 1, Json::encode($a[2])]
            <=> [$b[0], strlen($b[3]->id), $b[3]->id, $b[3]->collection->name, $b[1] === 'remove' ? 0 : 1, Json::encode($b[2])];
    }

    /**
     * Whether the entries of a set depend on documents other than their own: joins read their source sets.
     */
    private static function readsOtherDocuments(Set $set): bool
    {
        return array_any(
            $set->branches(),
            static fn (Branch $branch): bool => array_any($branch->matches, static fn (IndexMatch $match): bool => $match instanceof JoinSet)
                || array_any($branch->excluded, static fn (Set $excluded): bool => self::readsOtherDocuments($excluded)),
        );
    }

    /**
     * The collections a set reads: those of its matches, of the bodies and sources of its joins, and of the sets it
     * excludes.
     *
     * @return non-empty-list<string>
     */
    private static function reads(Set $set): array
    {
        $collections = [];
        foreach ($set->branches() as $branch) {
            array_push($collections, ...self::readsBranch($branch));
        }

        return array_values(array_unique($collections)) ?: throw new LogicException('A set reads at least one collection.');
    }

    /**
     * @return list<string>
     */
    private static function readsBranch(Branch $branch): array
    {
        $collections = [];
        foreach ($branch->matches as $match) {
            if ($match instanceof JoinSet) {
                array_push($collections, ...self::readsBranch($match->body), ...self::reads($match->source));
            } else {
                $collections[] = $match->index->source->name;
            }
        }

        foreach ($branch->excluded as $excluded) {
            array_push($collections, ...self::reads($excluded));
        }

        return $collections;
    }
}
