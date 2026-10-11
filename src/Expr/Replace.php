<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;
use Flora\Exception\NotFound;
use Flora\Value\Ref as RefValue;

/**
 * Replaces the whole data of a document.
 */
final readonly class Replace implements Expr
{

    public function __construct(private mixed $ref, private mixed $params) {}

    /**
     * @return array{ref: RefValue, ts: int, data: array<string, mixed>}
     */
    public function evaluate(Context $context): array
    {
        $context->assertWritable('Replace()');

        $ref = $context->evaluateRef($this->ref, 'Replace() ref');
        $data = DocumentParams::data($context->evaluateArray($this->params, 'Replace() params'), 'Replace');

        if (!$context->driver->replace($ref->collection->name, $ref->id, $context->ts, $data)) {
            throw new NotFound(sprintf('Document %s/%s does not exist.', $ref->collection->name, $ref->id));
        }

        return ['ref' => $ref, 'ts' => $context->ts, 'data' => $data];
    }
}
