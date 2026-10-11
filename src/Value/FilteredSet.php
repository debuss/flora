<?php declare(strict_types=1);

namespace Flora\Value;

use Flora\Context;
use Flora\Driver\IndexEntry;
use Flora\Exception\InvalidArgument;

/**
 * The entries of a set for which a lambda returns true, as returned by Filter(). The lambda receives the item of each
 * entry, like Map() over a page: its ref, its value, or its values.
 *
 * The lambda can do anything a query does, so it is run for each entry: the set is read in chunks from the cursor,
 * growing while the lambda rejects their entries, until the page is full. Entries keep the order and cursors of the
 * set they come from.
 */
final readonly class FilteredSet implements Readable
{

    private const int MAX_CHUNK = 1024;

    public function __construct(public Readable $set, public BoundLambda $lambda) {}

    public function collections(): array
    {
        return $this->set->collections();
    }

    public function values(): array
    {
        return $this->set->values();
    }

    /**
     * Reads the entries for which the lambda returns true, like Driver::scan() reads a set.
     *
     * @param list<mixed>|null $cursor
     * @return list<IndexEntry>
     */
    public function scan(Context $context, ?array $cursor, bool $inclusive, bool $backward, int $limit): array
    {
        $kept = [];
        $chunk = $limit;

        while (true) {
            $entries = $context->scan($this->set, $cursor, $inclusive, $backward, $chunk);
            foreach ($entries as $entry) {
                if (!$this->accepts($entry)) {
                    continue;
                }

                $kept[] = $entry;
                if (count($kept) === $limit) {
                    return $kept;
                }
            }

            if (count($entries) < $chunk) {
                return $kept;
            }

            // The next chunk starts after the last entry read, and is larger: the lambda rejects many entries.
            $cursor = $entries[count($entries) - 1]->cursor();
            $inclusive = false;
            $chunk = min(2 * $chunk, self::MAX_CHUNK);
        }
    }

    /**
     * How many entries the lambda returns true for: all the entries of the set are checked.
     */
    public function count(Context $context): int
    {
        $count = 0;
        foreach ($context->entries($this->set) as $entry) {
            if ($this->accepts($entry)) {
                ++$count;
            }
        }

        return $count;
    }

    private function accepts(IndexEntry $entry): bool
    {
        $accepted = $this->lambda->apply($entry->item());

        return is_bool($accepted) ? $accepted : throw InvalidArgument::expected('The result of a Filter() lambda', 'a boolean', $accepted);
    }
}
