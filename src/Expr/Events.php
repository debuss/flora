<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;
use Flora\Exception\InvalidArgument;
use Flora\Value\{DocumentEvents, Ref, Set, SetEvents};

/**
 * The history of a document, or of a set.
 *
 * Of a document: an event per write, {ts, action, document, data}, where the action is create, update or delete and
 * data the document after it. Of a set: an event each time an entry was added to it or removed from it, data holding
 * its values. Both are read like sets, by time, with Paginate().
 */
final readonly class Events implements Expr
{

    public function __construct(private mixed $subject) {}

    public function evaluate(Context $context): DocumentEvents|SetEvents
    {
        $subject = $context->evaluate($this->subject);

        return match (true) {
            $subject instanceof Ref => new DocumentEvents($subject),
            $subject instanceof Set => new SetEvents($subject),
            default => throw InvalidArgument::expected('Events() subject', 'a Ref() or a set (MatchIndex(), Union()...)', $subject),
        };
    }
}
