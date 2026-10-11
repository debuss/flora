<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;
use Flora\Schema\IndexDefinition;
use Flora\Value\{Bounds, BoundedSet, SetRef};

/**
 * The set of one document: the documents of its collection between its ref and itself. It holds the ref while the
 * document exists, combines with other sets, and has the Events() of the document entering and leaving it.
 */
final readonly class Singleton implements Expr
{

    public function __construct(private mixed $ref) {}

    public function evaluate(Context $context): BoundedSet
    {
        $ref = $context->evaluateRef($this->ref, 'Singleton() ref');

        return new BoundedSet(new SetRef(IndexDefinition::documents($ref->collection)), new Bounds([$ref], [$ref]));
    }
}
