<?php declare(strict_types=1);

namespace Flora\Support;

use DateTimeImmutable;
use DateTimeInterface;
use Flora\Exception\InvalidArgument;
use Flora\Value\{Date, JoinItem};

/**
 * Conversions of query values, as ToString(), ToNumber(), ToInteger(), ToDouble(), ToDate() and ToTime() do them.
 *
 * @internal
 */
final class Conversions
{

    /**
     * Strings, numbers (doubles keep their fraction: "1.0"), booleans, null, instants and dates (as documents store
     * them).
     */
    public static function toString(mixed $value, string $function = 'ToString()'): string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value) => (string) $value,
            is_float($value) && is_finite($value) => Json::encode($value),
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            $value instanceof DateTimeInterface => Times::format($value),
            $value instanceof Date => $value->value,
            $value instanceof JoinItem => throw JoinItem::misuse(),
            default => throw InvalidArgument::expected(sprintf('%s value', $function), 'a string, a number, a boolean, null, an instant or a date', $value),
        };
    }

    /**
     * A date, the day of an instant in UTC, or a date written YYYY-MM-DD.
     */
    public static function toDate(mixed $value): Date
    {
        return match (true) {
            $value instanceof Date => $value,
            $value instanceof DateTimeInterface => Date::of($value),
            is_string($value) => new Date($value),
            $value instanceof JoinItem => throw JoinItem::misuse(),
            default => throw InvalidArgument::expected('ToDate() value', 'a date, an instant or a string like 2026-10-11', $value),
        };
    }

    /**
     * An instant, the start of a date in UTC, or an instant written as Timestamp() reads it.
     */
    public static function toTime(mixed $value): DateTimeImmutable
    {
        return match (true) {
            $value instanceof DateTimeInterface => DateTimeImmutable::createFromInterface($value),
            $value instanceof Date => $value->midnight(),
            is_string($value) => Times::parse($value),
            $value instanceof JoinItem => throw JoinItem::misuse(),
            default => throw InvalidArgument::expected('ToTime() value', 'an instant, a date or a string like 2026-10-11T12:00:00Z', $value),
        };
    }

    /**
     * Numbers, and strings writing one: "42" is an integer, "4.2" and "1e3" doubles, an integer too large a double.
     */
    public static function toNumber(mixed $value, string $function = 'ToNumber()'): int|float
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[+-]?\d+$/', $value)) {
            // Leading zeros are not an octal prefix, and an integer too large is a double.
            $integer = filter_var(preg_replace('/^([+-]?)0+(?=\d)/', '$1', $value), FILTER_VALIDATE_INT);

            return $integer !== false ? $integer : (float) $value;
        }

        if (is_string($value) && preg_match('/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$/', $value)) {
            $number = (float) $value;

            return is_finite($number) ? $number : throw new InvalidArgument(sprintf('%s value "%s" is beyond the numbers.', $function, $value));
        }

        return $value instanceof JoinItem
            ? throw JoinItem::misuse()
            : throw InvalidArgument::expected(sprintf('%s value', $function), 'a number, or a string writing one', $value);
    }

    /**
     * A number truncated toward zero, or a string writing one.
     */
    public static function toInteger(mixed $value): int
    {
        $number = self::toNumber($value, 'ToInteger()');
        if (is_int($number)) {
            return $number;
        }

        if (!is_finite($number) || $number >= PHP_INT_MAX || $number < PHP_INT_MIN) {
            throw new InvalidArgument(sprintf('ToInteger() value %s is beyond the integers.', var_export($number, true)));
        }

        return (int) $number;
    }

    public static function toDouble(mixed $value): float
    {
        return (float) self::toNumber($value, 'ToDouble()');
    }
}
