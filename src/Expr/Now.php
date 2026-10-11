<?php declare(strict_types=1);

namespace Flora\Expr;

use DateTimeImmutable;
use Flora\Context;
use Flora\Support\Times;

/**
 * The time of the query, the same everywhere in it: the ts of its writes, as an instant in UTC.
 */
final readonly class Now implements Expr
{

    public function evaluate(Context $context): DateTimeImmutable
    {
        return Times::fromMicros($context->ts);
    }
}
