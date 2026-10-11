<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;
use Flora\Exception\InvalidArgument;

/**
 * The absolute value of a number (FQL's Abs, which would take the place of PHP's abs() where it is imported).
 */
final readonly class Absolute implements Expr
{

    public function __construct(private mixed $value) {}

    public function evaluate(Context $context): int|float
    {
        $value = $context->evaluate($this->value);

        return is_int($value) || is_float($value) ? abs($value) : throw InvalidArgument::expected('Absolute() value', 'a number', $value);
    }
}
