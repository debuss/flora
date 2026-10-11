<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;
use Flora\Exception\NotFound;

/**
 * Removes an event from the history of a document: the one of this action at this time. The document becomes what its
 * last event says, so removing the last event brings back its previous version, or deletes it when there is none.
 *
 *     Remove($ref, $ts, 'update')
 */
final readonly class Remove implements Expr
{

    public function __construct(private mixed $ref, private mixed $ts, private mixed $action) {}

    public function evaluate(Context $context): null
    {
        $context->assertWritable('Remove()');

        $ref = $context->evaluateRef($this->ref, 'Remove() ref');
        $ts = Insert::time($context->evaluate($this->ts), 'Remove()');
        $action = Insert::action($context->evaluate($this->action), 'Remove()');

        if (!$context->driver->removeEvent($ref, $ts, $action)) {
            throw new NotFound(sprintf('There is no %s event at %d in the history of %s/%s.', $action, $ts, $ref->collection->name, $ref->id));
        }

        return null;
    }
}
