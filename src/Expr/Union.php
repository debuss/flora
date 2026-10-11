<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Support\Values;
use Flora\Value\{Set, UnionSet};

/**
 * The entries of any of the sets, each once, or the items of any of the lists, each once, in the order they first
 * appear.
 */
final readonly class Union extends SetOperation
{

    protected function combineSets(array $sets): Set
    {
        return new UnionSet($sets);
    }

    protected function combineLists(array $lists): array
    {
        $union = [];
        foreach (array_merge(...$lists) as $item) {
            if (!array_any($union, static fn (mixed $kept): bool => Values::equal($kept, $item))) {
                $union[] = $item;
            }
        }

        return $union;
    }

    protected function name(): string
    {
        return 'Union';
    }
}
