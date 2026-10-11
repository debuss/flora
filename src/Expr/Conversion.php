<?php declare(strict_types=1);

namespace Flora\Expr;

use DateTimeImmutable;
use Flora\Context;
use Flora\Support\Conversions;
use Flora\Value\Date;

/**
 * A value converted, as ToString(), ToNumber(), ToInteger(), ToDouble(), ToDate() and ToTime() return it.
 */
final readonly class Conversion implements Expr
{

    /**
     * @param 'String'|'Number'|'Integer'|'Double'|'Date'|'Time' $to the name of the function, without "To"
     */
    public function __construct(private string $to, private mixed $value) {}

    public function evaluate(Context $context): string|int|float|Date|DateTimeImmutable
    {
        $value = $context->evaluate($this->value);

        return match ($this->to) {
            'String' => Conversions::toString($value),
            'Number' => Conversions::toNumber($value),
            'Integer' => Conversions::toInteger($value),
            'Double' => Conversions::toDouble($value),
            'Date' => Conversions::toDate($value),
            'Time' => Conversions::toTime($value),
        };
    }
}
