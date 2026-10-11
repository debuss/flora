<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;
use Flora\Exception\AlreadyExists;
use Flora\Schema\IndexDefinition;
use Flora\Value\IndexRef;

/**
 * Creates an index:
 *
 *     CreateIndex([
 *         'name' => 'users_by_email',
 *         'source' => Collection('users'),
 *         'terms' => [['field' => ['data', 'email']]],
 *         'values' => [['field' => ['data', 'name']], ['field' => ['ref']]],
 *         'unique' => true,
 *     ])
 */
final readonly class CreateIndex implements Expr
{

    public function __construct(private mixed $params) {}

    /**
     * @return array<string, mixed>
     */
    public function evaluate(Context $context): array
    {
        $context->assertWritable('CreateIndex()');

        $index = IndexDefinition::fromParams($context->evaluateArray($this->params, 'CreateIndex() params'));

        if (!$context->driver->createIndex($index, $context->ts)) {
            throw new AlreadyExists(sprintf('Index "%s" already exists.', $index->name));
        }

        return self::document($index, $context->ts);
    }

    /**
     * The document describing an index, as CreateIndex(), Update() and Delete() return it.
     *
     * @return array<string, mixed>
     */
    public static function document(IndexDefinition $index, int $ts): array
    {
        return ['ref' => new IndexRef($index->name), ...$index->toArray(), 'source' => $index->source, 'ts' => $ts];
    }
}
