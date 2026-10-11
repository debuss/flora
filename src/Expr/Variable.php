<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;

/**
 * Reads a variable bound by Let() or Lambda() (FQL's Var, a reserved word in PHP).
 */
final readonly class Variable implements Expr
{

    public function __construct(private string $name) {}

    public function evaluate(Context $context): mixed
    {
        return $context->variable($this->name);
    }
}
