<?php declare(strict_types=1);

namespace Flora\Value;

/**
 * A lazy set of index entries: MatchIndex(), Documents(), Join(), or a Union(), Intersection() or Difference() of
 * them. It is read with Paginate(), Get() or Exists().
 *
 * Entries are compared by their tuple, the values of the index followed by the ref, and sorted by it.
 *
 * Sets are read with SQL by drivers, so they combine with Union(), Intersection(), Difference() and Join().
 */
interface Set extends Readable
{

    /**
     * @return non-empty-list<CollectionRef>
     */
    public function collections(): array;

    /**
     * The set as a union of branches, which is how drivers read it: each branch is an intersection of index matches,
     * minus excluded sets. (A ∪ B) ∩ C is read as (A ∩ C) ∪ (B ∩ C), and A − B as one branch, A, excluding B.
     *
     * @return non-empty-list<Branch>
     */
    public function branches(): array;
}
