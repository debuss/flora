<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;
use Flora\Exception\Aborted;

/**
 * Stops the query with a message: its writes are rolled back, and the client throws Aborted.
 *
 *     IfElse(GTE(Select(['data', 'balance'], Get($account)), $amount), Update(...), Abort('Insufficient funds.'))
 */
final readonly class Abort implements Expr
{

    public function __construct(private mixed $message) {}

    public function evaluate(Context $context): never
    {
        throw new Aborted($context->evaluateString($this->message, 'Abort() message'));
    }
}
