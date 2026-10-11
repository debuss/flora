<?php declare(strict_types=1);

namespace Flora\Value;

use Flora\Exception\InvalidArgument;
use Flora\Schema\{IndexDefinition, IndexField};

/**
 * One branch of a Join(): the entries of the body found for an entry of the source set. The body is evaluated once,
 * with JoinItems standing for the items of the source entry (its ref when the source index has no values, else its
 * values); it is read for all the entries at once.
 *
 * The items of the entry are terms of the matches of the body, so that a document is in the join when its terms equal
 * the items of a source entry: all the terms of the body refer to the same entry.
 */
final readonly class JoinSet implements IndexMatch
{

    public IndexDefinition $index;

    public function __construct(public Set $source, public Branch $body, public object $join)
    {
        $this->index = $body->matches[0]->index;

        if (array_any($source->values(), static fn (IndexField $value): bool => $value->isTs())) {
            throw new InvalidArgument('Join() matches the values of the source set on data or refs: it has a ts value.');
        }

        $items = max(1, count($source->values()));
        foreach ($body->matches as $k => $match) {
            if (!$match instanceof SetRef) {
                if (self::uses($match, $join)) {
                    throw new InvalidArgument('The parameters of a Join() lambda are terms of MatchIndex() in its result, not in a nested Join().');
                }

                continue;
            }

            foreach ($match->terms as $i => $term) {
                if (!$term instanceof JoinItem) {
                    continue;
                }

                if ($term->join !== $join) {
                    throw new InvalidArgument('A Join() lambda uses the parameters of an enclosing Join(): it can only use its own.');
                }

                // Matches are read on the same rows as the first one, unless one of them unrolls: it is then read by a
                // subquery of its own, which cannot refer to the source entry of the first one.
                if ($k > 0 && ($match->index->unrolls() || $this->index->unrolls())) {
                    throw new InvalidArgument('In the result of a Join() lambda, the parameters are terms of the first match, or of matches of indexes that do not unroll fields when it does not either.');
                }

                if ($term->position >= $items) {
                    throw new InvalidArgument(sprintf('The entries of the source set of Join() hold %d item(s).', $items));
                }

                $field = $match->index->terms[$i];
                if ($field->isRef() || $field->isTs()) {
                    throw new InvalidArgument(sprintf('Join() matches terms on data fields: index "%s" has a term on ref or ts.', $match->index->name));
                }
            }
        }

        foreach ($body->excluded as $set) {
            if (self::uses($set, $join)) {
                throw new InvalidArgument('The parameters of a Join() lambda cannot be terms of the sets a Difference() excludes.');
            }
        }
    }

    public function branches(): array
    {
        return [new Branch([$this])];
    }

    public function collections(): array
    {
        return [$this->index->source];
    }

    public function values(): array
    {
        return $this->index->values;
    }

    /**
     * Whether the items of a join are terms anywhere in a set.
     */
    private static function uses(Set $set, object $join): bool
    {
        return array_any($set->branches(), static fn (Branch $branch): bool => self::branchUses($branch, $join));
    }

    private static function branchUses(Branch $branch, object $join): bool
    {
        foreach ($branch->matches as $match) {
            $uses = $match instanceof self
                ? self::uses($match->source, $join) || self::branchUses($match->body, $join)
                : $match instanceof SetRef && array_any($match->terms, static fn (mixed $term): bool => $term instanceof JoinItem && $term->join === $join);

            if ($uses) {
                return true;
            }
        }

        return array_any($branch->excluded, static fn (Set $set): bool => self::uses($set, $join));
    }
}
