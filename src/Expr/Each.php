<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;
use Flora\Exception\InvalidArgument;
use Flora\Value\{BoundLambda, Page, Readable};

/**
 * Runs a lambda on each item of a list or a page, for what it does (writes), and returns the list or the page (FQL's
 * Foreach, a reserved word in PHP).
 *
 *     Each(Paginate(MatchIndex(Index('users_by_role'), 'guest')), Lambda('ref', Delete(Variable('ref'))))
 *
 * Like FQL, it takes a list or a page, not a set: writing to the documents of a set while reading it would move their
 * entries, so that some would be seen twice and others not at all. The items of a page are read before the lambda runs.
 */
final readonly class Each implements Expr
{

    public function __construct(private mixed $collection, private mixed $lambda) {}

    /**
     * @return Page|list<mixed>
     */
    public function evaluate(Context $context): Page|array
    {
        $collection = $context->evaluate($this->collection);
        $lambda = $context->evaluate($this->lambda);

        if (!$lambda instanceof BoundLambda) {
            throw InvalidArgument::expected('Each() lambda', 'a Lambda()', $lambda);
        }

        if ($collection instanceof Readable) {
            throw new InvalidArgument('Each() takes a list or a page, not a set: paginate the set, and run Each() on each page.');
        }

        $items = match (true) {
            $collection instanceof Page => $collection->data,
            is_array($collection) && array_is_list($collection) => $collection,
            default => throw InvalidArgument::expected('Each() collection', 'a list or a page', $collection),
        };

        foreach ($items as $item) {
            $lambda->apply($item);
        }

        return $collection;
    }
}
