<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;
use Flora\Exception\InvalidArgument;
use Flora\Value\Query as QueryValue;

/**
 * A Lambda() kept as it is written, rather than evaluated: the body of a stored function.
 */
final readonly class Query implements Expr
{

    private Lambda $lambda;

    public function __construct(mixed $lambda)
    {
        $this->lambda = $lambda instanceof Lambda ? $lambda : throw InvalidArgument::expected('Query() lambda', 'a Lambda()', $lambda);
    }

    public function evaluate(Context $context): QueryValue
    {
        return new QueryValue($this->lambda);
    }
}
