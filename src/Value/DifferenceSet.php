<?php declare(strict_types=1);

namespace Flora\Value;

/**
 * The entries of the first set found in none of the others, as returned by Difference().
 */
final readonly class DifferenceSet implements Set
{

    /** @var non-empty-list<Branch> */
    private array $branches;

    /**
     * @param non-empty-list<Set> $sets
     */
    public function __construct(public array $sets)
    {
        SetShape::assertCompatible('Difference', $sets);

        // (A ∪ B) − C = (A − C) ∪ (B − C): each branch of the first set excludes the others, kept whole.
        $excluded = array_slice($sets, 1);
        $this->branches = array_map(static fn (Branch $branch): Branch => $branch->without($excluded), $sets[0]->branches());
    }

    public function branches(): array
    {
        return $this->branches;
    }

    public function collections(): array
    {
        return $this->sets[0]->collections();
    }

    public function values(): array
    {
        return $this->sets[0]->values();
    }
}
