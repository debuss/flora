<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;
use Flora\Exception\InvalidArgument;
use Flora\Support\Values;
use Flora\Value\{DistinctSet, Page, Readable};

/**
 * The items of a list or a page (which keeps its cursors) without the repeated ones, kept where they first are, or one
 * entry per distinct values of a set.
 */
final readonly class Distinct implements Expr
{

    public function __construct(private mixed $collection) {}

    public function evaluate(Context $context): mixed
    {
        $collection = $context->evaluate($this->collection);

        return match (true) {
            // Entries without values are refs, all different.
            $collection instanceof Readable => $collection->values() === [] || $collection instanceof DistinctSet ? $collection : new DistinctSet($collection),
            $collection instanceof Page => new Page(self::distinct($collection->data), $collection->before, $collection->after),
            is_array($collection) && array_is_list($collection) => self::distinct($collection),
            default => throw InvalidArgument::expected('Distinct() collection', 'a list, a page or a set', $collection),
        };
    }

    /**
     * @param list<mixed> $items
     * @return list<mixed>
     */
    private static function distinct(array $items): array
    {
        $distinct = [];
        foreach ($items as $item) {
            $distinct[Values::key($item)] ??= $item;
        }

        return array_values($distinct);
    }
}
