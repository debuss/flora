<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;
use Flora\Exception\InvalidArgument;
use Flora\Value\{BoundLambda, Page};

/**
 * Applies a Lambda() to each item of a list, or of a page (its cursors are kept).
 */
final readonly class Map implements Expr
{

    public function __construct(private mixed $collection, private mixed $lambda) {}

    public function evaluate(Context $context): mixed
    {
        $collection = $context->evaluate($this->collection);
        $lambda = $context->evaluate($this->lambda);

        if (!$lambda instanceof BoundLambda) {
            throw InvalidArgument::expected('Map() lambda', 'a Lambda()', $lambda);
        }

        if ($collection instanceof Page) {
            return new Page(array_map($lambda->apply(...), $collection->data), $collection->before, $collection->after);
        }

        if (!is_array($collection) || !array_is_list($collection)) {
            throw InvalidArgument::expected('Map() collection', 'a list or a page', $collection);
        }

        return array_map($lambda->apply(...), $collection);
    }
}
