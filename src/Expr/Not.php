<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;
use Flora\Exception\InvalidArgument;

final readonly class Not implements Expr
{

    public function __construct(private mixed $value) {}

    public function evaluate(Context $context): bool
    {
        $value = $context->evaluate($this->value);

        return is_bool($value) ? !$value : throw InvalidArgument::expected('Not() value', 'a boolean', $value);
    }
}
