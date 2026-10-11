<?php declare(strict_types=1);

namespace Flora\Value;

use Flora\Context;
use Flora\Driver\IndexEntry;

/**
 * The entries of a set in reverse order, as returned by Reverse(): the set is read backward, through the same index.
 * Its cursors are those of the set.
 */
final readonly class ReversedSet implements Readable
{

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
     * Reads the entries like Driver::scan() reads a set: reading forward reads the set backward, and the other way.
     *
     * @param list<mixed>|null $cursor
     * @return list<IndexEntry>
     */
    public function scan(Context $context, ?array $cursor, bool $inclusive, bool $backward, int $limit): array
    {
        return $context->scan($this->set, $cursor, $inclusive, !$backward, $limit);
    }

    public function count(Context $context): int
    {
        return $context->count($this->set);
    }
}
