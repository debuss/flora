<?php declare(strict_types=1);

namespace Flora\Expr;

use stdClass;
use Flora\Context;
use Flora\Exception\InvalidArgument;
use Flora\Value\{BoundLambda, Branch, JoinItem, JoinSet, Set, SetRef, UnionSet};

/**
 * The entries found for each entry of the source set, in the detail: an index matched with the items of the entry (its
 * ref when the source index has no values, else its values, one per term), or a lambda receiving these items and
 * returning a set.
 *
 *     Join(MatchIndex(Index('users_by_role'), 'admin'), Index('posts_by_author'))
 *     Join(MatchIndex(Index('users_by_role'), 'admin'), Lambda('ref', MatchIndex(Index('posts_by_author'), Variable('ref'))))
 *
 * The lambda is evaluated once, with JoinItems standing for the items, which it can only use as terms of MatchIndex().
 */
final readonly class Join implements Expr
{

    public function __construct(private mixed $source, private mixed $detail) {}

    public function evaluate(Context $context): Set
    {
        $source = $context->evaluateSet($this->source, 'Join() source');
        $detail = $context->evaluate($this->detail);

        // Identifies the items of this join, which a nested join must tell from its own.
        $join = new stdClass();
        $count = max(1, count($source->values()));
        $items = array_map(static fn (int $position): JoinItem => new JoinItem($join, $position), range(0, $count - 1));

        if ($detail instanceof BoundLambda) {
            $body = $detail->apply($count === 1 ? $items[0] : $items);
            if (!$body instanceof Set) {
                throw InvalidArgument::expected('The result of a Join() lambda', 'a set (MatchIndex(), Union()...)', $body);
            }
        } else {
            $index = $context->index($context->evaluateIndex($detail, 'Join() detail'));
            if (count($index->terms) !== $count) {
                throw new InvalidArgument(sprintf(
                    'Join() matches the %d term(s) of index "%s" with the entries of the source set, which hold %d item(s).',
                    count($index->terms),
                    $index->name,
                    $count,
                ));
            }

            $body = new SetRef($index, $items);
        }

        // (A ∪ B) found for an entry = (A found for it) ∪ (B found for it).
        $joins = array_map(static fn (Branch $branch): JoinSet => new JoinSet($source, $branch, $join), $body->branches());

        return count($joins) === 1 ? $joins[0] : new UnionSet($joins);
    }
}
