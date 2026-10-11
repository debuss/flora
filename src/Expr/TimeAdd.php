<?php declare(strict_types=1);

namespace Flora\Expr;

use DateTimeImmutable;
use DateTimeInterface;
use Flora\Context;
use Flora\Exception\InvalidArgument;
use Flora\Support\Times;
use Flora\Value\Date;

/**
 * An instant moved by a number of units of time, which can be negative: TimeAdd(Now(), -1, 'hour'), or a date by days.
 * Months and years are not units, their length varies.
 */
final readonly class TimeAdd implements Expr
{

    public function __construct(private mixed $time, private mixed $offset, private mixed $unit) {}

    public function evaluate(Context $context): DateTimeImmutable|Date
    {
        return self::move($context, $this->time, $this->offset, $this->unit, 1, 'TimeAdd()');
    }

    /**
     * Moves an instant forward (direction 1) or backward (-1) by a number of units of time, or a date by days.
     *
     * @internal
     */
    public static function move(Context $context, mixed $time, mixed $offset, mixed $unit, int $direction, string $function): DateTimeImmutable|Date
    {
        $time = $context->evaluate($time);
        $unit = $context->evaluate($unit);
        if ($time instanceof Date && !Times::isDay($unit)) {
            throw InvalidArgument::expected(sprintf('%s unit of a date', $function), 'days', $unit);
        }

        $instant = match (true) {
            $time instanceof Date => $time->midnight(),
            $time instanceof DateTimeInterface => $time,
            default => throw InvalidArgument::expected(sprintf('%s time', $function), 'an instant (Timestamp(), Now()...) or a date', $time),
        };

        $from = Times::toMicros($instant);
        $by = $direction * Times::micros($context->evaluate($offset), $unit, $function);
        if (($by > 0 && $from > PHP_INT_MAX - $by) || ($by < 0 && $from < PHP_INT_MIN - $by)) {
            throw new InvalidArgument(sprintf('%s goes beyond the instants it can represent.', $function));
        }

        $moved = Times::fromMicros($from + $by);

        return $time instanceof Date ? Date::of($moved) : $moved;
    }
}
