<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;
use Flora\Exception\NotFound;
use Flora\Value\{CollectionRef, FunctionRef as FunctionRefValue, IndexRef};

/**
 * Deletes a document and returns it as it was. Given an index or a function, deletes it, and given a collection,
 * deletes it with its documents, their history and its indexes.
 */
final readonly class Delete implements Expr
{

    public function __construct(private mixed $ref) {}

    /**
     * @return array<string, mixed>
     */
    public function evaluate(Context $context): array
    {
        $context->assertWritable('Delete()');

        $target = $context->evaluate($this->ref);
        if ($target instanceof IndexRef) {
            $index = $context->index($target);
            $ts = $context->driver->deleteIndex($target->name) ?? throw new NotFound(sprintf('Index "%s" does not exist.', $target->name));
            $context->forgetIndexes(name: $target->name);

            return CreateIndex::document($index, $ts);
        }

        if ($target instanceof FunctionRefValue) {
            $function = $context->driver->deleteFunction($target->name) ?? throw new NotFound(sprintf('Function "%s" does not exist.', $target->name));
            $context->forgetFunction($target->name);

            return CreateFunction::stored($function);
        }

        if ($target instanceof CollectionRef) {
            $collection = $context->driver->deleteCollection($target->name) ?? throw new NotFound(sprintf('Collection "%s" does not exist.', $target->name));
            $context->forgetIndexes(collection: $target->name);

            return CreateCollection::document($collection);
        }

        $ref = $context->evaluateRef($target, 'Delete() ref');

        $document = $context->driver->delete($ref->collection->name, $ref->id, $context->ts)
            ?? throw new NotFound(sprintf('Document %s/%s does not exist.', $ref->collection->name, $ref->id));

        return $document->toDocument($ref->collection);
    }
}
