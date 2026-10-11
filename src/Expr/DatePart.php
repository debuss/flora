<?php declare(strict_types=1);

namespace Flora\Expr;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Flora\Context;
use Flora\Exception\InvalidArgument;
use Flora\Value\{Date, JoinItem};

/**
 * A part of an instant or a date, in UTC, as DayOfMonth(), DayOfWeek() (1 is Monday, 7 Sunday), DayOfYear(), Month(),
 * Year(), Hour(), Minute() and Second() return it. A date starts at midnight: its hour, minute and second are 0.
 */
final readonly class DatePart implements Expr
{

    /**
     * @param 'DayOfMonth'|'DayOfWeek'|'DayOfYear'|'Month'|'Year'|'Hour'|'Minute'|'Second' $part the name of the function
     */
    public function __construct(private string $part, private mixed $time) {}

    public function evaluate(Context $context): int
    {
        $time = $context->evaluate($this->time);
        $time = match (true) {
            $time instanceof Date => $time->midnight(),
            $time instanceof DateTimeInterface => DateTimeImmutable::createFromInterface($time)->setTimezone(new DateTimeZone('UTC')),
            $time instanceof JoinItem => throw JoinItem::misuse(),
            default => throw InvalidArgument::expected(sprintf('%s() time', $this->part), 'an instant or a date', $time),
        };

        return (int) $time->format(match ($this->part) {
            'DayOfMonth' => 'j',
            'DayOfWeek' => 'N',
            'DayOfYear' => 'z',
            'Month' => 'n',
            'Year' => 'Y',
            'Hour' => 'G',
            'Minute' => 'i',
            'Second' => 's',
        }) + ($this->part === 'DayOfYear' ? 1 : 0);
    }
}
