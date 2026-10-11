<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;
use Flora\Exception\InvalidArgument;
use Flora\Value\{BoundLambda, Page, Readable};

/**
 * Folds a list, a page or a set into a value: the reducer receives the value so far and an item, and returns the next
 * value. A set is read whole, in chunks, and its entries given like Map() gives the items of a page.
 *
 *     Reduce(Lambda(['total', 'age'], Add(Variable('total'), Variable('age'))), 0, MatchIndex(Index('ages_by_role'), 'admin'))
 */
final readonly class Reduce implements Expr
{

    public function __construct(private mixed $reducer, private mixed $initial, private mixed $collection) {}

    public function evaluate(Context $context): mixed
    {
        $reducer = $context->evaluate($this->reducer);
        if (!$reducer instanceof BoundLambda) {
            throw InvalidArgument::expected('Reduce() reducer', 'a Lambda()', $reducer);
        }

        $value = $context->evaluate($this->initial);
        $collection = $context->evaluate($this->collection);

        if ($collection instanceof Readable) {
            foreach ($context->entries($collection) as $entry) {
                $value = $reducer->apply([$value, $entry->item()]);
            }

            return $value;
        }

        $items = match (true) {
            $collection instanceof Page => $collection->data,
            is_array($collection) && array_is_list($collection) => $collection,
            default => throw InvalidArgument::expected('Reduce() collection', 'a list, a page or a set', $collection),
        };

        foreach ($items as $item) {
            $value = $reducer->apply([$value, $item]);
        }

        return $value;
    }
}
