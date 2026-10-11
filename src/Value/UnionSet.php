<?php declare(strict_types=1);

namespace Flora\Value;

/**
 * The entries of any of the sets, each once, as returned by Union().
 */
final readonly class UnionSet implements Set
{

    /** @var non-empty-list<Branch> */
    private array $branches;

    /**
     * @param non-empty-list<Set> $sets
     */
    public function __construct(public array $sets)
    {
        SetShape::assertCompatible('Union', $sets);

        $this->branches = SetShape::limit(array_merge(...array_map(static fn (Set $set): array => $set->branches(), $sets)));
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
