<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;
use Flora\Exception\InvalidArgument;

/**
 * Evaluates one branch or the other (FQL's If, a reserved word in PHP).
 */
final readonly class IfElse implements Expr
{

    public function __construct(private mixed $condition, private mixed $then, private mixed $else) {}

    public function evaluate(Context $context): mixed
    {
        $condition = $context->evaluate($this->condition);
        if (!is_bool($condition)) {
            throw InvalidArgument::expected('IfElse() condition', 'a boolean', $condition);
        }

        return $context->evaluate($condition ? $this->then : $this->else);
    }
}
