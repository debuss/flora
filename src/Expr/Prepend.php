<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;

/**
 * The elements followed by the base list, in FQL's order of arguments: Prepend([1, 2], [3, 4]) is [1, 2, 3, 4].
 */
final readonly class Prepend implements Expr
{

    public function __construct(private mixed $elements, private mixed $base) {}

    /**
     * @return list<mixed>
     */
    public function evaluate(Context $context): array
    {
        return [
            ...Append::list($context->evaluate($this->elements), 'elements', 'Prepend()'),
            ...Append::list($context->evaluate($this->base), 'base', 'Prepend()'),
        ];
    }
}
