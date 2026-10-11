<?php declare(strict_types=1);

namespace Flora\Expr;

use DateTimeImmutable;
use Flora\Context;
use Flora\Support\Times;

/**
 * The instant an ISO 8601 date and time stands for, in UTC: Timestamp('2026-10-11T12:00:00Z'). Timestamp('now') is
 * Now(). This is FQL's Time, which would take the place of PHP's time() where it is imported.
 */
final readonly class Timestamp implements Expr
{

    public function __construct(private mixed $time) {}

    public function evaluate(Context $context): DateTimeImmutable
    {
        $time = $context->evaluateString($this->time, 'Timestamp() time');

        return $time === 'now' ? Times::fromMicros($context->ts) : Times::parse($time);
    }
}
