<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;
use Flora\Value\IndexRef;

final readonly class Index implements Expr
{

    public function __construct(private mixed $name) {}

    public function evaluate(Context $context): IndexRef
    {
        return new IndexRef($context->evaluateString($this->name, 'Index() name'));
    }
}
