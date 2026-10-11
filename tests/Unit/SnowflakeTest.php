<?php declare(strict_types=1);

namespace Flora\Tests\Unit;

use DateTimeImmutable;
use Flora\Exception\InvalidArgument;
use Flora\Id\Snowflake;
use PHPUnit\Framework\TestCase;

final class SnowflakeTest extends TestCase
{

    public function testIdsAreUniqueAndIncreasing(): void
    {
        $generator = new Snowflake();
        $previous = 0;

        // More ids than fit in one millisecond, to go through sequence exhaustion.
        for ($i = 0; $i < 10_000; ++$i) {
            $id = (int) $generator->next();
            self::assertGreaterThan($previous, $id);
            $previous = $id;
        }
    }

    public function testIdsEncodeTheirCreationTime(): void
    {
        $before = new DateTimeImmutable();
        $id = (new Snowflake())->next();

        self::assertMatchesRegularExpression('/^[1-9][0-9]{15,18}$/', $id);
        self::assertEqualsWithDelta((float) $before->format('U.v'), (float) Snowflake::createdAt($id)->format('U.v'), 0.005);
    }

    public function testWorkerIdIsEncoded(): void
    {
        $id = (int) (new Snowflake(42))->next();

        self::assertSame(42, ($id >> 12) & Snowflake::MAX_WORKER);
    }

    public function testRejectsAnOutOfRangeWorker(): void
    {
        $this->expectException(InvalidArgument::class);

        new Snowflake(1024);
    }
}
