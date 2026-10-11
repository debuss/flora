<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;
use Flora\Exception\InvalidArgument;
use Flora\Value\{Page, Readable, ReversedSet};

/**
 * The items of a list or a page (which keeps its cursors) in reverse order, or the entries of a set: the set is then
 * read backward, through its index, and paginated like any set.
 */
final readonly class Reverse implements Expr
{

    public function __construct(private mixed $collection) {}

    public function evaluate(Context $context): mixed
    {
        $collection = $context->evaluate($this->collection);

        return match (true) {
            $collection instanceof ReversedSet => $collection->set,
            $collection instanceof Readable => new ReversedSet($collection),
            $collection instanceof Page => new Page(array_reverse($collection->data), $collection->before, $collection->after),
            is_array($collection) && array_is_list($collection) => array_reverse($collection),
            default => throw InvalidArgument::expected('Reverse() collection', 'a list, a page or a set', $collection),
        };
    }
}
