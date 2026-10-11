<?php declare(strict_types=1);

namespace Flora\Driver;

use Flora\Exception\InvalidArgument;
use Flora\Schema\IndexDefinition;
use Flora\Value\{Bounds, Ref};

/**
 * Keyset pagination: the SQL condition selecting the entries that come after a cursor in scan order.
 *
 * @internal
 */
final class Keyset
{

    /**
     * @param list<array{sql: string, desc: bool, bind: callable(): string}> $keys the sort keys, in order, each with a
     *        callback adding a parameter holding the cursor's value for that key and returning its placeholder
     * @param bool $inclusive whether the entry at the cursor is selected too
     * @param bool $rowComparison whether the database turns (a, b) > (x, y) into an index range, in which case it is
     *        used when all keys go the same way, and to bound the scan otherwise
     */
    public static function after(array $keys, bool $inclusive, bool $rowComparison): string
    {
        $operator = static fn (bool $desc, bool $orEqual): string => ($desc ? '<' : '>') . ($orEqual ? '=' : '');

        if ($rowComparison && count(array_unique(array_column($keys, 'desc'))) === 1) {
            return sprintf(
                '(%s) %s (%s)',
                implode(', ', array_column($keys, 'sql')),
                $operator($keys[0]['desc'], $inclusive),
                implode(', ', array_map(static fn (array $key): string => ($key['bind'])(), $keys)),
            );
        }

        // (a > x) OR (a = x AND b < y) OR (a = x AND b = y AND c > z)...
        $alternatives = [];
        foreach ($keys as $i => $key) {
            $conditions = [];
            for ($j = 0; $j < $i; ++$j) {
                $conditions[] = sprintf('%s = %s', $keys[$j]['sql'], ($keys[$j]['bind'])());
            }

            $last = $i === count($keys) - 1;
            $conditions[] = sprintf('%s %s %s', $key['sql'], $operator($key['desc'], $last && $inclusive), ($key['bind'])());
            $alternatives[] = '(' . implode(' AND ', $conditions) . ')';
        }

        // The redundant bound in front gives the database a range to start the index scan from: the keys up to the
        // first change of direction when it handles row comparisons, the first key otherwise.
        $prefix = [$keys[0]];
        foreach (array_slice($keys, 1) as $key) {
            if (!$rowComparison || $key['desc'] !== $keys[0]['desc']) {
                break;
            }
            $prefix[] = $key;
        }

        return sprintf(
            '(%s) %s (%s) AND (%s)',
            implode(', ', array_column($prefix, 'sql')),
            $operator($keys[0]['desc'], true),
            implode(', ', array_map(static fn (array $key): string => ($key['bind'])(), $prefix)),
            implode(' OR ', $alternatives),
        );
    }

    /**
     * The SQL conditions selecting the entries of a branch within bounds, in the order of the index: from the first
     * bound on, like a cursor read forward, and up to the second, like a cursor read backward, both included.
     *
     * @param list<Bounds> $bounds
     * @param callable(list<mixed>, bool): list<array{sql: string, desc: bool, bind: callable(): string, position: int}> $keys
     *        the sort keys of the index bound to the values of a tuple, read forward or backward, each with the
     *        position in the tuple of the value it sorts
     * @return list<string>
     */
    public static function within(array $bounds, IndexDefinition $index, callable $keys, bool $rowComparison): array
    {
        $where = [];
        foreach ($bounds as $range) {
            foreach ([[$range->from, false], [$range->to, true]] as [$bound, $backward]) {
                if ($bound === []) {
                    continue;
                }

                // A bound is a prefix of the tuple: only its keys are compared, so that the entries it starts are in.
                $prefix = array_values(array_filter($keys($bound, $backward), static fn (array $key): bool => $key['position'] < count($bound)));
                $inclusive = count($bound) <= count($index->values)
                    || self::inclusive($index->source->name, $bound[count($index->values)], true, $index->refReversed() !== $backward);

                $where[] = self::after($prefix, $inclusive, $rowComparison);
            }
        }

        return $where;
    }

    /**
     * Whether the entry at the cursor, its values and id, is selected in a branch reading a collection.
     *
     * Entries sort by values, then by ref: id, then collection. A branch reads one collection, so the entries of other
     * collections are not there: with the values and id of the cursor, an entry of this branch comes after the cursor
     * when its collection does, in the direction of the ref.
     *
     * @param mixed $cursor the ref ending the cursor
     * @param bool $inclusive whether the entry at the cursor itself is selected
     * @param bool $desc whether refs are read in descending order
     */
    public static function inclusive(string $collection, mixed $cursor, bool $inclusive, bool $desc): bool
    {
        if (!$cursor instanceof Ref) {
            throw InvalidArgument::expected('The end of a cursor or a bound of documents', 'a Ref()', $cursor);
        }

        $order = strcmp($collection, $cursor->collection->name) <=> 0;

        return $order === 0 ? $inclusive : ($desc ? $order < 0 : $order > 0);
    }
}
