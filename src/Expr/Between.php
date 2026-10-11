<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;
use Flora\Exception\InvalidArgument;
use Flora\Value\{Bounds, BoundedSet, DistinctSet, FilteredSet, Readable, ReversedSet, Set};

/**
 * The entries of a set between two bounds, both included: FQL's Range, which would take the place of PHP's range()
 * where it is imported.
 *
 *     Between(MatchIndex(Index('users_by_age')), 18, 65)
 *     Between(MatchIndex(Index('orders_by_date')), [Timestamp('2026-10-01T00:00:00Z')], [Timestamp('2026-11-01T00:00:00Z')])
 *
 * A bound is a value, or a list of values then a ref: a prefix of the tuple of the entries, compared in the order of
 * the index (from the larger value for a reverse one). An entry is within [80] and [90] when its first value is, and an
 * empty list does not bound the entries. The bounds are conditions on the index, read like the cursor of a page.
 */
final readonly class Between implements Expr
{

    public function __construct(private mixed $set, private mixed $from, private mixed $to) {}

    public function evaluate(Context $context): Readable
    {
        $set = $context->evaluate($this->set);
        $bounds = new Bounds(self::bound($context->evaluate($this->from)), self::bound($context->evaluate($this->to)));

        return self::bounded($set, $bounds);
    }

    /**
     * The entries of a set between bounds, which are conditions on the index of the set it comes from.
     *
     * @internal
     */
    public static function bounded(mixed $set, Bounds $bounds): Readable
    {
        return match (true) {
            $set instanceof Set => new BoundedSet($set, $bounds),
            // The entries a filter keeps between bounds are those it keeps of the entries between them.
            $set instanceof FilteredSet => new FilteredSet(self::bounded($set->set, $bounds), $set->lambda),
            // Bounds follow the order of the reversed set: from the end of the set it reverses.
            $set instanceof ReversedSet => new ReversedSet(self::bounded($set->set, new Bounds($bounds->to, $bounds->from))),
            $set instanceof DistinctSet => new DistinctSet(self::bounded($set->set, $bounds)),
            default => throw InvalidArgument::expected('Between() set', 'a set (MatchIndex(), Union(), Filter()...)', $set),
        };
    }

    /**
     * @return list<mixed>
     */
    private static function bound(mixed $bound): array
    {
        return is_array($bound) && array_is_list($bound) ? $bound : [$bound];
    }
}
