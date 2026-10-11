<?php declare(strict_types=1);

namespace Flora\Expr;

use DateTimeInterface;
use Flora\Context;
use Flora\Exception\InvalidArgument;
use Flora\Support\Times;

/**
 * Evaluates an expression as the database was at a time, an instant or microseconds like the ts of documents: its reads
 * see the documents and sets of then, from the history of their collections. It cannot write.
 *
 *     At($document['ts'], Get($document['ref']))
 *     At(Timestamp('2026-10-11T12:00:00Z'), Paginate(...))
 */
final readonly class At implements Expr
{

    public function __construct(private mixed $ts, private mixed $expr) {}

    public function evaluate(Context $context): mixed
    {
        $ts = $context->evaluate($this->ts);
        if ($ts instanceof DateTimeInterface) {
            $ts = Times::toMicros($ts);
        }

        if (!is_int($ts)) {
            throw InvalidArgument::expected('At() time', 'an instant (Timestamp(), Now()) or microseconds', $ts);
        }

        return $context->withAt($ts)->evaluate($this->expr);
    }
}
