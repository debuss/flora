<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;
use Flora\Exception\{AlreadyExists, InvalidArgument};
use Flora\Value\{CollectionRef, Ref as RefValue};

/**
 * Creates a document in a collection, with a generated id, or at the given ref.
 */
final readonly class Create implements Expr
{

    private const int MAX_ATTEMPTS = 5;

    public function __construct(private mixed $target, private mixed $params = []) {}

    /**
     * @return array{ref: RefValue, ts: int, data: array<string, mixed>}
     */
    public function evaluate(Context $context): array
    {
        $context->assertWritable('Create()');

        $target = $context->evaluate($this->target);
        $data = DocumentParams::data($context->evaluateArray($this->params, 'Create() params'), 'Create');

        if ($target instanceof RefValue) {
            if (!$context->driver->insert($target->collection->name, $target->id, $context->ts, $data)) {
                throw new AlreadyExists(sprintf('Document %s/%s already exists.', $target->collection->name, $target->id));
            }

            return ['ref' => $target, 'ts' => $context->ts, 'data' => $data];
        }

        if (!$target instanceof CollectionRef) {
            throw InvalidArgument::expected('Create() target', 'a Collection() or a Ref()', $target);
        }

        // A generated id only clashes if two generators share a worker id, so a few attempts are plenty.
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; ++$attempt) {
            $ref = new RefValue($target, $context->ids->next());
            if ($context->driver->insert($target->name, $ref->id, $context->ts, $data)) {
                return ['ref' => $ref, 'ts' => $context->ts, 'data' => $data];
            }
        }

        throw new AlreadyExists(sprintf('Could not generate a free id in collection "%s".', $target->name));
    }
}
