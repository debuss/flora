<?php declare(strict_types=1);

namespace Flora\Expr;

use DateTimeInterface;
use Flora\Context;
use Flora\Exception\InvalidArgument;
use Flora\Support\Times;
use Flora\Value\Date;

/**
 * How many whole units of time go from an instant to another, truncated toward zero, negative when the second comes
 * first: TimeDiff(Timestamp('2026-01-01T00:00:00Z'), Timestamp('2026-01-02T12:00:00Z'), 'days') is 1. Between dates,
 * the unit is days.
 */
final readonly class TimeDiff implements Expr
{

    public function __construct(private mixed $start, private mixed $finish, private mixed $unit) {}

    public function evaluate(Context $context): int
    {
        $start = $context->evaluate($this->start);
        $finish = $context->evaluate($this->finish);
        $unit = $context->evaluate($this->unit);

        if ($start instanceof Date && $finish instanceof Date) {
            if (!Times::isDay($unit)) {
                throw InvalidArgument::expected('TimeDiff() unit of dates', 'days', $unit);
            }

            [$start, $finish] = [$start->midnight(), $finish->midnight()];
        }

        if (!$start instanceof DateTimeInterface || !$finish instanceof DateTimeInterface) {
            throw InvalidArgument::expected('TimeDiff() times', 'two instants, or two dates', $start instanceof DateTimeInterface ? $finish : $start);
        }

        return Times::units(Times::toMicros($finish) - Times::toMicros($start), $unit, 'TimeDiff()');
    }
}
