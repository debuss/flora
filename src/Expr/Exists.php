<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;
use Flora\Value\{CollectionRef, FunctionRef as FunctionRefValue, IndexRef, Ref as RefValue};

/**
 * Whether a document, a collection, an index or a function exists, or whether a set has at least one entry.
 */
final readonly class Exists implements Expr
{

    public function __construct(private mixed $ref) {}

    public function evaluate(Context $context): bool
    {
        $ref = $context->evaluateDocumentRef($this->ref, 'Exists() ref');

        return match (true) {
            $ref === null => false,
            $ref instanceof CollectionRef => $context->driver->findCollection($ref->name) !== null,
            $ref instanceof IndexRef => $context->driver->findIndex($ref->name) !== null,
            $ref instanceof FunctionRefValue => $context->driver->findFunction($ref->name) !== null,
            $ref instanceof RefValue => $context->driver->find($ref->collection->name, $ref->id, at: $context->at) !== null,
            default => false,
        };
    }
}
