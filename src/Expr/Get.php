<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;
use Flora\Exception\NotFound;
use Flora\Exception\InvalidArgument;
use Flora\Support\Values;
use Flora\Value\{CollectionRef, FunctionRef as FunctionRefValue, IndexRef, Ref as RefValue};

/**
 * Reads a document from its ref, or the first document of a set, or the document describing a collection, an index or
 * a function.
 */
final readonly class Get implements Expr
{

    /**
     * @param mixed $ts the time to read the document at, like At() does
     */
    public function __construct(private mixed $ref, private mixed $ts = null) {}

    /**
     * @return array<string, mixed>
     */
    public function evaluate(Context $context): array
    {
        if ($this->ts !== null) {
            return Values::object(new At($this->ts, new self($this->ref))->evaluate($context), 'Get() document');
        }

        $ref = $context->evaluateDocumentRef($this->ref, 'Get() ref') ?? throw new NotFound('The set is empty.');

        if ($ref instanceof CollectionRef) {
            return CreateCollection::document($context->driver->findCollection($ref->name)
                ?? throw new NotFound(sprintf('Collection "%s" does not exist.', $ref->name)));
        }

        if ($ref instanceof IndexRef) {
            $index = $context->index($ref);

            return CreateIndex::document($index, $context->driver->indexTs($ref->name) ?? throw new NotFound(sprintf('Index "%s" does not exist.', $ref->name)));
        }

        if ($ref instanceof FunctionRefValue) {
            return CreateFunction::stored($context->driver->findFunction($ref->name)
                ?? throw new NotFound(sprintf('Function "%s" does not exist.', $ref->name)));
        }

        if (!$ref instanceof RefValue) {
            throw InvalidArgument::expected('Get() ref', 'a ref', $ref);
        }

        $document = $context->driver->find($ref->collection->name, $ref->id, at: $context->at)
            ?? throw new NotFound(sprintf('Document %s/%s does not exist.', $ref->collection->name, $ref->id));

        return $document->toDocument($ref->collection);
    }
}
