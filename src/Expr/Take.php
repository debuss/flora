<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;

/**
 * The first items of a list or a page (which keeps its cursors): Take(2, [1, 2, 3]) is [1, 2].
 */
final readonly class Take implements Expr
{

    public function __construct(private mixed $count, private mixed $collection) {}

    public function evaluate(Context $context): mixed
    {
        return Drop::slice($context, $this->count, $this->collection, 'Take()', static fn (array $items, int $count): array => array_slice($items, 0, $count));
    }
}
