<?php declare(strict_types=1);

namespace Flora\Driver;

/**
 * The SQL reading the union of the branches of a set.
 *
 * Each branch reads at most one page after the cursor, through its own index; the union of these pages, sorted again,
 * starts with the page of the whole set. A page therefore costs as many index reads as there are branches, however far
 * it is in the set. Entries found in several branches are removed by IndexEntry::distinct().
 *
 * @internal
 */
final class Union
{

    /**
     * @param list<string> $branches the SQL of each branch, sorted and limited, selecting the same columns
     * @param list<string> $order the result columns to sort on, with their direction
     */
    public static function of(array $branches, array $order): string
    {
        if (count($branches) === 1) {
            return $branches[0];
        }

        $selects = [];
        foreach ($branches as $i => $branch) {
            $selects[] = sprintf('SELECT * FROM (%s) AS b%d', $branch, $i);
        }

        return implode(' UNION ALL ', $selects) . ' ORDER BY ' . implode(', ', $order);
    }
}
