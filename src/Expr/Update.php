<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;
use Flora\Exception\{AlreadyExists, InvalidArgument, NotFound};
use Flora\Schema\IndexDefinition;
use Flora\Support\MergePatch;
use Flora\Support\Serializer;
use Flora\Value\{CollectionRef, FunctionRef as FunctionRefValue, Identifier, IndexRef};

/**
 * Merges data into a document: nested objects are merged, null removes a key, anything else replaces the old value.
 * Given a collection, an index or a function, changes it instead.
 */
final readonly class Update implements Expr
{

    public function __construct(private mixed $ref, private mixed $params) {}

    /**
     * @return array<string, mixed>
     */
    public function evaluate(Context $context): array
    {
        $context->assertWritable('Update()');

        $target = $context->evaluate($this->ref);
        if ($target instanceof CollectionRef) {
            return $this->updateCollection($context, $target);
        }

        if ($target instanceof IndexRef) {
            return $this->updateIndex($context, $target);
        }

        if ($target instanceof FunctionRefValue) {
            return $this->updateFunction($context, $target);
        }

        $ref = $context->evaluateRef($target, 'Update() ref');
        $patch = DocumentParams::data($context->evaluateArray($this->params, 'Update() params'), 'Update');

        $document = $context->driver->find($ref->collection->name, $ref->id, lock: true)
            ?? throw new NotFound(sprintf('Document %s/%s does not exist.', $ref->collection->name, $ref->id));

        $data = MergePatch::apply($document->data, $patch);
        $context->driver->replace($ref->collection->name, $ref->id, $context->ts, $data);

        return ['ref' => $ref, 'ts' => $context->ts, 'data' => $data];
    }

    /**
     * Renames an index, or makes it unique or not: Update(Index('users_by_email'), ['unique' => true]). Its terms,
     * values and source stay: another index is created for others. The index is built again, under its new name.
     *
     * @return array<string, mixed>
     */
    private function updateIndex(Context $context, IndexRef $ref): array
    {
        $params = $context->evaluateArray($this->params, 'Update() params');

        $unknown = array_diff(array_keys($params), ['name', 'unique']);
        if ($unknown !== []) {
            throw new InvalidArgument(sprintf(
                'Unknown Update() parameter(s) of an index: %s. Its terms, values and source stay: create another index instead.',
                implode(', ', $unknown),
            ));
        }

        $index = $context->index($ref);
        $updated = IndexDefinition::fromParams([...$index->toArray(), 'source' => $index->source, ...$params]);
        $driver = $context->driver;
        $create = static fn (IndexDefinition $index): bool => $driver->createIndex($index, $context->ts)
            ?: throw new AlreadyExists(sprintf('Index "%s" already exists.', $index->name));

        // The new index is built before the old one is deleted, since some databases cannot roll back a schema change:
        // when it cannot be (its name is taken, documents break it), the old one is still there. Under the same name, a
        // unique index is first built under another one, to find out.
        if ($updated->name !== $index->name) {
            $create($updated);
            $driver->deleteIndex($index->name);
        } else {
            if ($updated->unique && !$index->unique) {
                $check = new IndexDefinition('_unique_' . hash('xxh64', $index->name), $index->source, $index->terms, $index->values, unique: true);
                $create($check);
                $driver->deleteIndex($check->name);
            }

            $driver->deleteIndex($index->name);
            $create($updated);
        }
        $context->forgetIndexes(name: $index->name);

        return CreateIndex::document($updated, $context->ts);
    }

    /**
     * Renames a function, or changes its body: Update(FunctionRef('adults'), ['body' => Query(Lambda(...))]).
     *
     * @return array<string, mixed>
     */
    private function updateFunction(Context $context, FunctionRefValue $ref): array
    {
        $params = $context->evaluateArray(CreateFunction::query($this->params), 'Update() params');
        $unknown = array_diff(array_keys($params), ['name', 'body']);
        if ($unknown !== []) {
            throw new InvalidArgument(sprintf('Unknown Update() parameter(s) of a function: %s.', implode(', ', $unknown)));
        }

        $current = CreateFunction::stored($context->driver->findFunction($ref->name)
            ?? throw new NotFound(sprintf('Function "%s" does not exist.', $ref->name)));
        $name = $params['name'] ?? $ref->name;
        if (!is_string($name)) {
            throw InvalidArgument::expected('Function "name"', 'a string', $name);
        }
        Identifier::assert($name, 'Function name');
        $body = array_key_exists('body', $params) ? CreateFunction::body($params['body'], 'Update()') : $current['body'];

        if (!$context->driver->updateFunction($ref->name, $name, Serializer::serialize($body->lambda), $context->ts)) {
            throw new AlreadyExists(sprintf('Function "%s" already exists.', $name));
        }
        $context->forgetFunction($ref->name);

        return CreateFunction::document($name, $body, $context->ts);
    }

    /**
     * Changes the settings of a collection: Update(Collection('users'), ['history_days' => 7]).
     *
     * @return array{ref: CollectionRef, name: string, ts: int, history_days: int|null}
     */
    private function updateCollection(Context $context, CollectionRef $collection): array
    {
        $params = $context->evaluateArray($this->params, 'Update() params');

        $unknown = array_diff(array_keys($params), ['history_days']);
        if ($unknown !== []) {
            throw new InvalidArgument(sprintf('Unknown Update() parameter(s) of a collection: %s.', implode(', ', $unknown)));
        }

        $current = $context->driver->findCollection($collection->name)
            ?? throw new NotFound(sprintf('Collection "%s" does not exist.', $collection->name));
        $historyDays = array_key_exists('history_days', $params)
            ? CreateCollection::historyDays($params['history_days'], 'Update()')
            : $current['history_days'];

        $context->driver->updateCollection($collection->name, $context->ts, $historyDays);

        return CreateCollection::document(['name' => $collection->name, 'ts' => $context->ts, 'history_days' => $historyDays]);
    }
}
