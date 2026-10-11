<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;
use Flora\Exception\InvalidArgument;
use Flora\Value\{BoundLambda, FilteredSet, Page, Readable};

/**
 * The items of a list or a page (its cursors are kept), or the entries of a set, for which a lambda returns true.
 *
 *     Filter(MatchIndex(Index('users_by_role'), 'admin'), Lambda('ref', Equals(Select(['data', 'city'], Get(Variable('ref'))), 'Paris')))
 *
 * A filtered set is read with Paginate(), Get() or Exists(), running the lambda for each entry of the set.
 */
final readonly class Filter implements Expr
{

    public function __construct(private mixed $collection, private mixed $lambda) {}

    /**
     * @return FilteredSet|Page|list<mixed>
     */
    public function evaluate(Context $context): FilteredSet|Page|array
    {
        $collection = $context->evaluate($this->collection);
        $lambda = $context->evaluate($this->lambda);

        if (!$lambda instanceof BoundLambda) {
            throw InvalidArgument::expected('Filter() lambda', 'a Lambda()', $lambda);
        }

        if ($collection instanceof Readable) {
            return new FilteredSet($collection, $lambda);
        }

        if ($collection instanceof Page) {
            return new Page(self::filter($collection->data, $lambda), $collection->before, $collection->after);
        }

        if (!is_array($collection) || !array_is_list($collection)) {
            throw InvalidArgument::expected('Filter() collection', 'a list, a page or a set', $collection);
        }

        return self::filter($collection, $lambda);
    }

    /**
     * @param list<mixed> $items
     * @return list<mixed>
     */
    private static function filter(array $items, BoundLambda $lambda): array
    {
        return array_values(array_filter($items, static function (mixed $item) use ($lambda): bool {
            $accepted = $lambda->apply($item);

            return is_bool($accepted) ? $accepted : throw InvalidArgument::expected('The result of a Filter() lambda', 'a boolean', $accepted);
        }));
    }
}
