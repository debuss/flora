<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;
use Flora\Schema\IndexDefinition;
use Flora\Value\SetRef;

/**
 * The set of all the documents of a collection, sorted by ref.
 */
final readonly class Documents implements Expr
{

    public function __construct(private mixed $collection) {}

    public function evaluate(Context $context): SetRef
    {
        return new SetRef(IndexDefinition::documents($context->evaluateCollection($this->collection, 'Documents() collection')));
    }
}
