<?php declare(strict_types=1);

namespace Flora\Value;

/**
 * The entries of a set between two tuples, both included, as Between() gives them. A bound is a prefix of the tuple of
 * an entry, its values then its ref, and compares in the order of the index: an entry is within [80] and [90] when its
 * first value is, whatever the others. An empty bound does not bound the entries.
 */
final readonly class Bounds
{

    /**
     * @param list<mixed> $from
     * @param list<mixed> $to
     */
    public function __construct(public array $from, public array $to) {}
}
