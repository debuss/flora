<?php declare(strict_types=1);

namespace Flora\Value;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use JsonSerializable;
use Stringable;
use Flora\Exception\InvalidArgument;
use Flora\Support\Times;

/**
 * A day of the calendar, without time, as ToDate() returns it: 2026-10-11. Documents store it as
 * {"@date": "2026-10-11"}, whose fixed width makes the order of the text the order of days.
 */
final readonly class Date implements JsonSerializable, Stringable
{

    /** The date, as YYYY-MM-DD. */
    public string $value;

    public function __construct(string $value)
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new InvalidArgument(sprintf('"%s" is not a date like 2026-10-11.', $value));
        }

        $this->value = $value;
    }

    /**
     * The day of an instant, in UTC.
     */
    public static function of(DateTimeInterface $time): self
    {
        return new self(DateTimeImmutable::createFromInterface($time)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d'));
    }

    /**
     * The instant the day starts at, in UTC.
     */
    public function midnight(): DateTimeImmutable
    {
        return Times::parse($this->value . 'T00:00:00Z');
    }

    /**
     * @return array{'@date': string}
     */
    public function jsonSerialize(): array
    {
        return ['@date' => $this->value];
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
