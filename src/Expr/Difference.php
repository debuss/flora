<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Support\Values;
use Flora\Value\{DifferenceSet, Set};

/**
 * The entries of the first set found in none of the others, or the items of the first list found in none of the
 * others, each once.
 */
final readonly class Difference extends SetOperation
{

    protected function combineSets(array $sets): Set
    {
        return new DifferenceSet($sets);
    }

    protected function combineLists(array $lists): array
    {
        $others = array_merge(...array_slice($lists, 1));

        $difference = [];
        foreach ($lists[0] as $item) {
            $excluded = array_any($others, static fn (mixed $other): bool => Values::equal($other, $item))
                || array_any($difference, static fn (mixed $kept): bool => Values::equal($kept, $item));

            if (!$excluded) {
                $difference[] = $item;
            }
        }

        return $difference;
    }

    protected function name(): string
    {
        return 'Difference';
    }
}
