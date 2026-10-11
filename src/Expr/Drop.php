<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;
use Flora\Exception\InvalidArgument;
use Flora\Value\Page;

/**
 * The items of a list or a page (which keeps its cursors) but the first ones: Drop(2, [1, 2, 3]) is [3].
 */
final readonly class Drop implements Expr
{

    public function __construct(private mixed $count, private mixed $collection) {}

    public function evaluate(Context $context): mixed
    {
        return self::slice($context, $this->count, $this->collection, 'Drop()', static fn (array $items, int $count): array => array_slice($items, $count));
    }

    /**
     * Applies a slice to the items of a list or a page.
     *
     * @param callable(list<mixed>, int): list<mixed> $slice
     *
     * @internal
     */
    public static function slice(Context $context, mixed $count, mixed $collection, string $function, callable $slice): mixed
    {
        $count = $context->evaluate($count);
        if (!is_int($count) || $count < 0) {
            throw InvalidArgument::expected(sprintf('%s count', $function), 'a positive integer or zero', $count);
        }

        $collection = $context->evaluate($collection);

        return match (true) {
            $collection instanceof Page => new Page($slice($collection->data, $count), $collection->before, $collection->after),
            is_array($collection) && array_is_list($collection) => $slice($collection, $count),
            default => throw InvalidArgument::expected(sprintf('%s collection', $function), 'a list or a page', $collection),
        };
    }
}
