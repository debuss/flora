<?php declare(strict_types=1);

namespace Flora\Value;

/**
 * One branch of a set: the entries of documents matching all the index matches, with the same values in all of them,
 * found in none of the excluded sets, and within all the bounds. The first match gives the order of the entries.
 */
final readonly class Branch
{

    /**
     * @param non-empty-list<IndexMatch> $matches
     * @param list<Set> $excluded
     * @param list<Bounds> $bounds
     */
    public function __construct(public array $matches, public array $excluded = [], public array $bounds = []) {}

    /**
     * The branch whose entries are in both branches.
     */
    public function and(self $other): self
    {
        return new self([...$this->matches, ...$other->matches], [...$this->excluded, ...$other->excluded], [...$this->bounds, ...$other->bounds]);
    }

    /**
     * The branch without the entries of these sets.
     *
     * @param list<Set> $sets
     */
    public function without(array $sets): self
    {
        return new self($this->matches, [...$this->excluded, ...$sets], $this->bounds);
    }

    /**
     * The branch with only the entries within these bounds.
     */
    public function between(Bounds $bounds): self
    {
        return new self($this->matches, $this->excluded, [...$this->bounds, $bounds]);
    }
}
