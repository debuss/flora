<?php declare(strict_types=1);

namespace Flora\Id;

use DateTimeImmutable;
use Flora\Exception\InvalidArgument;

/**
 * Snowflake ids, the scheme Twitter introduced and FaunaDB used for its refs:
 *
 *     0 | 41 bits: milliseconds since EPOCH | 10 bits: worker | 12 bits: sequence
 *
 * Ids fit in a BIGINT, are sorted by creation time and need no coordination with the database.
 *
 * Two generators only collide if they share a worker id and produce the same sequence in the same millisecond. Give
 * each process a distinct worker id to rule that out; by default it is random, and the sequence also starts at a
 * random offset every millisecond, which makes a collision very unlikely (a duplicate id is then rejected by the
 * primary key, never silently overwritten). The offset stays in the lower half of the sequence, so a generator still
 * produces at least 2048 strictly increasing ids per millisecond.
 */
final class Snowflake implements IdGenerator
{

    /** 2026-01-01T00:00:00Z, so ids stay positive until 2095. */
    public const int EPOCH = 1_767_225_600_000;

    public const int MAX_WORKER = 0x3FF;

    private const int MAX_SEQUENCE = 0xFFF;

    private readonly int $worker;

    private int $lastMs = -1;

    private int $count = 0;

    private int $offset = 0;

    public function __construct(?int $worker = null)
    {
        if ($worker !== null && ($worker < 0 || $worker > self::MAX_WORKER)) {
            throw new InvalidArgument(sprintf('Snowflake worker id must be between 0 and %d, %d given.', self::MAX_WORKER, $worker));
        }

        $this->worker = $worker ?? random_int(0, self::MAX_WORKER);
    }

    public function next(): string
    {
        // A clock moving backwards keeps using the last millisecond rather than reusing old ids.
        $ms = max(self::now(), $this->lastMs);

        if ($ms === $this->lastMs && $this->offset + ++$this->count > self::MAX_SEQUENCE) {
            do {
                $ms = self::now();
            } while ($ms <= $this->lastMs);
        }

        if ($ms !== $this->lastMs) {
            $this->lastMs = $ms;
            $this->count = 0;
            $this->offset = random_int(0, self::MAX_SEQUENCE >> 1);
        }

        return (string) ((($ms - self::EPOCH) << 22) | ($this->worker << 12) | ($this->offset + $this->count));
    }

    /**
     * When the id was generated, with millisecond precision.
     */
    public static function createdAt(string $id): DateTimeImmutable
    {
        return DateTimeImmutable::createFromTimestamp((((int) $id >> 22) + self::EPOCH) / 1000);
    }

    private static function now(): int
    {
        return (int) (microtime(true) * 1000);
    }
}
