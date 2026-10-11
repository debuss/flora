<?php declare(strict_types=1);

namespace Flora\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Flora\Exception\InvalidArgument;

/**
 * Instants: microseconds since the Unix epoch, as the ts of documents, or DateTimeImmutable in UTC, as Now() and
 * Timestamp() return them. Documents store them as {"@ts": "2026-10-11T12:00:00.000000Z"}, whose fixed width makes the
 * order of the text the order of time.
 *
 * @internal
 */
final class Times
{

    private const string FORMAT = 'Y-m-d\TH:i:s.u\Z';

    /** The microseconds of each unit of time, as FQL names them. */
    private const array UNITS = [
        'day' => 86_400_000_000,
        'half day' => 43_200_000_000,
        'hour' => 3_600_000_000,
        'minute' => 60_000_000,
        'second' => 1_000_000,
        'millisecond' => 1_000,
        'microsecond' => 1,
    ];

    public static function fromMicros(int $micros): DateTimeImmutable
    {
        $seconds = intdiv($micros, 1_000_000);
        $fraction = $micros % 1_000_000;
        if ($fraction < 0) {
            --$seconds;
            $fraction += 1_000_000;
        }

        // A Unix time is read in the +00:00 zone whatever the zone given: it is set to UTC after.
        $time = DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%06d', $seconds, $fraction))
            ?: throw new InvalidArgument(sprintf('%d microseconds is not an instant PHP can represent.', $micros));

        return $time->setTimezone(new DateTimeZone('UTC'));
    }

    public static function toMicros(DateTimeInterface $time): int
    {
        return (int) $time->format('U') * 1_000_000 + (int) $time->format('u');
    }

    /**
     * The text documents store an instant as.
     */
    public static function format(DateTimeInterface $time): string
    {
        return DateTimeImmutable::createFromInterface($time)->setTimezone(new DateTimeZone('UTC'))->format(self::FORMAT);
    }

    /**
     * Reads an ISO 8601 date and time with an offset: 2026-10-11T12:00:00Z, 2026-10-11T14:00:00.5+02:00... Digits after
     * the microseconds are dropped.
     */
    public static function parse(string $time): DateTimeImmutable
    {
        if (!preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d{1,9}))?(Z|[+-]\d{2}:\d{2})$/', $time, $parts)) {
            throw new InvalidArgument(sprintf('"%s" is not a date and time like 2026-10-11T12:00:00Z.', $time));
        }

        $normalized = sprintf('%s.%s%s', $parts[1], str_pad(substr($parts[2], 0, 6), 6, '0'), $parts[3] === 'Z' ? '+00:00' : $parts[3]);
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.uP', $normalized);

        // PHP would roll an impossible date over to another one: 2026-02-30 is not March 2.
        if ($parsed === false || $parsed->format('Y-m-d\TH:i:s') !== $parts[1]) {
            throw new InvalidArgument(sprintf('"%s" is not a valid date and time.', $time));
        }

        return $parsed->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * A number of units of time in microseconds: days, half days, hours, minutes, seconds, milliseconds, microseconds
     * or nanoseconds, singular or plural. Nanoseconds are truncated to microseconds.
     */
    public static function micros(mixed $amount, mixed $unit, string $function): int
    {
        if (!is_int($amount)) {
            throw InvalidArgument::expected(sprintf('%s amount', $function), 'an integer', $amount);
        }

        $name = self::unit($unit, $function);
        if ($name === 'nanosecond') {
            return intdiv($amount, 1_000);
        }

        if (abs($amount) > intdiv(PHP_INT_MAX, self::UNITS[$name])) {
            throw new InvalidArgument(sprintf('%s goes beyond the instants it can represent.', $function));
        }

        return $amount * self::UNITS[$name];
    }

    /**
     * How many whole units of time a number of microseconds holds, truncated toward zero.
     */
    public static function units(int $micros, mixed $unit, string $function): int
    {
        $name = self::unit($unit, $function);
        if ($name === 'nanosecond') {
            if (abs($micros) > intdiv(PHP_INT_MAX, 1_000)) {
                throw new InvalidArgument(sprintf('%s is too large to count in nanoseconds.', $function));
            }

            return $micros * 1_000;
        }

        return intdiv($micros, self::UNITS[$name]);
    }

    /**
     * Whether a unit of time is the day: the only one dates move by.
     */
    public static function isDay(mixed $unit): bool
    {
        return is_string($unit) && preg_replace('/s$/', '', $unit) === 'day';
    }

    /**
     * The singular name of a unit of time.
     *
     * @return key-of<self::UNITS>|'nanosecond'
     */
    private static function unit(mixed $unit, string $function): string
    {
        $name = is_string($unit) ? preg_replace('/s$/', '', $unit) : null;

        return match (true) {
            $name === 'nanosecond', is_string($name) && isset(self::UNITS[$name]) => $name,
            default => throw InvalidArgument::expected(
                sprintf('%s unit', $function),
                'one of days, half days, hours, minutes, seconds, milliseconds, microseconds or nanoseconds',
                $unit,
            ),
        };
    }
}
