<?php declare(strict_types=1);

namespace Flora\Value;

use Flora\Schema\IndexDefinition;

/**
 * The entries of an index matching terms, as returned by MatchIndex() or Documents().
 */
final readonly class SetRef implements IndexMatch
{

    /**
     * @param list<mixed> $terms one value per term of the index
     */
    public function __construct(public IndexDefinition $index, public array $terms = []) {}

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
}
