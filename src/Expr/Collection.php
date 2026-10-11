<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;
use Flora\Value\CollectionRef;

final readonly class Collection implements Expr
{

    public function __construct(private mixed $name) {}

    public function evaluate(Context $context): CollectionRef
    {
        return new CollectionRef($context->evaluateString($this->name, 'Collection() name'));
    }
}
