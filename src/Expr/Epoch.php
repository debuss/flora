<?php declare(strict_types=1);

namespace Flora\Expr;

use DateTimeImmutable;
use Flora\Context;
use Flora\Support\Times;

/**
 * The instant a number of units of time after 1970-01-01T00:00:00Z: Epoch($document['ts'], 'microseconds').
 */
final readonly class Epoch implements Expr
{

    public function __construct(private mixed $amount, private mixed $unit) {}

    public function evaluate(Context $context): DateTimeImmutable
    {
        return Times::fromMicros(Times::micros($context->evaluate($this->amount), $context->evaluate($this->unit), 'Epoch()'));
    }
}
