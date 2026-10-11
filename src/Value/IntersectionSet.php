<?php declare(strict_types=1);

namespace Flora\Value;

/**
 * The entries found in all the sets, as returned by Intersection().
 */
final readonly class IntersectionSet implements Set
{

    /** @var non-empty-list<Branch> */
    private array $branches;

    /**
     * @param non-empty-list<Set> $sets
     */
    public function __construct(public array $sets)
    {
        SetShape::assertCompatible('Intersection', $sets);

        // Intersection distributes over union: (A ∪ B) ∩ C = (A ∩ C) ∪ (B ∩ C).
        $branches = $sets[0]->branches();
        foreach (array_slice($sets, 1) as $set) {
            $product = [];
            foreach ($branches as $branch) {
                foreach ($set->branches() as $other) {
                    $product[] = $branch->and($other);
                }
            }
            $branches = SetShape::limit($product);
        }

        $this->branches = $branches;
    }

    public function branches(): array
    {
        return $this->branches;
    }

    public function collections(): array
    {
        return SetShape::collections($this->sets);
    }

    public function values(): array
    {
        return $this->sets[0]->values();
    }
}
