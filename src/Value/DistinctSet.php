<?php declare(strict_types=1);

namespace Flora\Value;

use Flora\Context;
use Flora\Driver\IndexEntry;
use Flora\Support\Values;

/**
 * One entry per distinct values of a set, as returned by Distinct(): its entries are sorted by values, so those with
 * the same values follow each other, and only the first one read is kept. Its items are the values.
 *
 * A cursor stands for its values: reading after it, or before it, skips the other entries with the same values.
 */
final readonly class DistinctSet implements Readable
{

    private const int MAX_CHUNK = 1024;

    public function __construct(public Readable $set) {}

    public function collections(): array
    {
        return $this->set->collections();
    }

    public function values(): array
    {
        return $this->set->values();
    }

    /**
     * Reads the entries with distinct values, like Driver::scan() reads a set, in chunks growing while the entries read
     * repeat values, until the page is full.
     *
     * @param list<mixed>|null $cursor
     * @return list<IndexEntry>
     */
    public function scan(Context $context, ?array $cursor, bool $inclusive, bool $backward, int $limit): array
    {
        $kept = [];
        // The values of the last entry given: those of an excluded cursor were given with the previous page.
        $last = $cursor !== null && !$inclusive ? array_slice($cursor, 0, -1) : null;
        $chunk = $limit;

        while (true) {
            $entries = $context->scan($this->set, $cursor, $inclusive, $backward, $chunk);
            foreach ($entries as $entry) {
                if ($last !== null && Values::equal($entry->values, $last)) {
                    continue;
                }

                $kept[] = $entry;
                $last = $entry->values;
                if (count($kept) === $limit) {
                    return $kept;
                }
            }

            if (count($entries) < $chunk) {
                return $kept;
            }

            $cursor = $entries[count($entries) - 1]->cursor();
            $inclusive = false;
            $chunk = min(2 * $chunk, self::MAX_CHUNK);
        }
    }

    /**
     * How many distinct values the set has: all its entries are read.
     */
    public function count(Context $context): int
    {
        $count = 0;
        $last = null;
        foreach ($context->entries($this->set) as $entry) {
            if ($last === null || !Values::equal($entry->values, $last)) {
                ++$count;
                $last = $entry->values;
            }
        }

        return $count;
    }
}
