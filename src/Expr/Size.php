<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;
use Flora\Exception\InvalidArgument;
use Flora\Value\{Page, Readable};

/**
 * How many items a list or a page has, or how many entries a set has, each counted once (FQL's Count, which would take
 * the place of PHP's count() where it is imported).
 *
 * A set is counted by the database, in a single query; a filtered set by running its lambda for each entry.
 */
final readonly class Size implements Expr
{

    public function __construct(private mixed $collection) {}

    public function evaluate(Context $context): int
    {
        $collection = $context->evaluate($this->collection);

        return match (true) {
            $collection instanceof Readable => $context->count($collection),
            $collection instanceof Page => count($collection->data),
            is_array($collection) && array_is_list($collection) => count($collection),
            default => throw InvalidArgument::expected('Size() collection', 'a list, a page or a set', $collection),
        };
    }
}
