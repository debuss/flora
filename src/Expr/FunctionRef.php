<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;
use Flora\Value\FunctionRef as FunctionRefValue;

/**
 * A reference to a stored function: FQL's Function, a word of PHP.
 */
final readonly class FunctionRef implements Expr
{

    public function __construct(private mixed $name) {}

    public function evaluate(Context $context): FunctionRefValue
    {
        return new FunctionRefValue($context->evaluateString($this->name, 'FunctionRef() name'));
    }
}
