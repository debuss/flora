<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;
use Flora\Exception\InvalidArgument;
use Flora\Value\{Page, Readable};

/**
 * Whether a list, a page or a set is empty, as returned by IsEmpty(), or not, by IsNonEmpty(). A set is checked by
 * reading one entry.
 */
final readonly class IsEmpty implements Expr
{

    public function __construct(private mixed $collection, private bool $empty = true) {}

    public function evaluate(Context $context): bool
    {
        $collection = $context->evaluate($this->collection);

        $empty = match (true) {
            $collection instanceof Readable => $context->scan($collection, null, true, false, 1) === [],
            $collection instanceof Page => $collection->data === [],
            is_array($collection) && array_is_list($collection) => $collection === [],
            default => throw InvalidArgument::expected(
                sprintf('%s() collection', $this->empty ? 'IsEmpty' : 'IsNonEmpty'),
                'a list, a page or a set',
                $collection,
            ),
        };

        return $empty === $this->empty;
    }
}
