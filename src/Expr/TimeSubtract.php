<?php declare(strict_types=1);

namespace Flora\Expr;

use DateTimeImmutable;
use Flora\Context;
use Flora\Value\Date;

/**
 * An instant moved back by a number of units of time: TimeSubtract(Now(), 1, 'hour'), or a date by days.
 */
final readonly class TimeSubtract implements Expr
{

    public function __construct(private mixed $time, private mixed $offset, private mixed $unit) {}

    public function evaluate(Context $context): DateTimeImmutable|Date
    {
        return TimeAdd::move($context, $this->time, $this->offset, $this->unit, -1, 'TimeSubtract()');
    }
}
