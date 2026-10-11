<?php declare(strict_types=1);

namespace Flora\Expr;

use DateTimeInterface;
use Flora\Context;
use Flora\Exception\InvalidArgument;
use Flora\Support\Times;

/**
 * The microseconds since 1970-01-01T00:00:00Z of an instant: what the ts of documents count.
 */
final readonly class ToMicros implements Expr
{

    public function __construct(private mixed $time) {}

    public function evaluate(Context $context): int
    {
        $time = $context->evaluate($this->time);

        return $time instanceof DateTimeInterface
            ? Times::toMicros($time)
            : throw InvalidArgument::expected('ToMicros() time', 'an instant (Timestamp(), Now()...)', $time);
    }
}
