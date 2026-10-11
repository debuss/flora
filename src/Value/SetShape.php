<?php declare(strict_types=1);

namespace Flora\Value;

use LogicException;
use Flora\Exception\InvalidArgument;
use Flora\Schema\IndexField;

/**
 * The rules sets follow to be combined.
 *
 * @internal
 */
final class SetShape
{

    /** How many branches a set may be read as, to keep its SQL reasonable. */
    public const int MAX_BRANCHES = 32;

    /**
     * Sets are combined when their entries can be compared: they hold as many values, of the same kind (data, ref or
     * ts), sorted the same way. Their entries can point to different collections: an intersection of sets of different
     * collections is empty, since their refs differ.
     *
     * @param list<Set> $sets
     */
    public static function assertCompatible(string $operation, array $sets): void
    {
        if ($sets === []) {
            throw new InvalidArgument(sprintf('%s() needs at least one set.', $operation));
        }

        foreach ($sets as $set) {
            if (self::shape($set->values()) !== self::shape($sets[0]->values())) {
                throw new InvalidArgument(sprintf(
                    '%s() combines sets whose entries compare: their indexes need as many values, of the same kind (data, ref or ts), sorted the same way.',
                    $operation,
                ));
            }
        }
    }

    /**
     * The collections the entries of sets point to, each once.
     *
     * @param non-empty-list<Readable> $sets
     * @return non-empty-list<CollectionRef>
     */
    public static function collections(array $sets): array
    {
        $collections = [];
        foreach ($sets as $set) {
            foreach ($set->collections() as $collection) {
                $collections[$collection->name] ??= $collection;
            }
        }

        return array_values($collections) ?: throw new LogicException('A set reads at least one collection.');
    }

    /**
     * @param list<Branch> $branches
     * @return non-empty-list<Branch>
     */
    public static function limit(array $branches): array
    {
        if (count($branches) > self::MAX_BRANCHES) {
            throw new InvalidArgument(sprintf('This set combines too many matches: it reads as more than %d branches.', self::MAX_BRANCHES));
        }

        return $branches ?: throw new LogicException('A set reads as at least one branch.');
    }

    /**
     * @param list<IndexField> $values
     * @return list<string>
     */
    private static function shape(array $values): array
    {
        return array_map(
            static fn (IndexField $field): string => ($field->isRef() ? 'ref' : ($field->isTs() ? 'ts' : 'data')) . ($field->reverse ? ' desc' : ''),
            $values,
        );
    }
}
