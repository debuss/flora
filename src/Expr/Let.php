<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;
use Flora\Exception\InvalidArgument;

/**
 * Binds variables, in order, then evaluates an expression: each binding can use the previous ones.
 */
final readonly class Let implements Expr
{

    /**
     * @param array<array-key, mixed> $bindings the value of each variable, by name
     */
    public function __construct(private array $bindings, private mixed $in) {}

    public function evaluate(Context $context): mixed
    {
        foreach ($this->bindings as $name => $value) {
            if (!is_string($name)) {
                throw InvalidArgument::expected('Let() variable name', 'a string', $name);
            }

            $context = $context->withVariables([$name => $context->evaluate($value)]);
        }

        return $context->evaluate($this->in);
    }
}
