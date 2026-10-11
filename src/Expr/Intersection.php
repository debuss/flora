<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Support\Values;
use Flora\Value\{IntersectionSet, Set};

/**
 * The entries found in all the sets, or the items of the first list found in all the others, each once.
 */
final readonly class Intersection extends SetOperation
{

    protected function combineSets(array $sets): Set
    {
        return new IntersectionSet($sets);
    }

    protected function combineLists(array $lists): array
    {
        $intersection = [];
        foreach ($lists[0] as $item) {
            $found = array_all(
                array_slice($lists, 1),
                static fn (array $list): bool => array_any($list, static fn (mixed $other): bool => Values::equal($other, $item)),
            );

            if ($found && !array_any($intersection, static fn (mixed $kept): bool => Values::equal($kept, $item))) {
                $intersection[] = $item;
            }
        }

        return $intersection;
    }

    protected function name(): string
    {
        return 'Intersection';
    }
}
