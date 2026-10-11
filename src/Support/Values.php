<?php declare(strict_types=1);

namespace Flora\Support;

use DateTimeInterface;
use Flora\Exception\InvalidArgument;
use Flora\Value\{JoinItem, Ref};

/**
 * Equality of query values, as Equals() sees it.
 *
 * @internal
 */
final class Values
{

    /**
     * Arrays are equal when they have the same keys and equal items, refs when they point to the same document, other
     * objects (collection and index refs, pages) when they have the same properties, anything else when identical.
     */
    public static function equal(mixed $a, mixed $b): bool
    {
        // An item of a Join() stands for many values: comparing it would decide once for all of them.
        if ($a instanceof JoinItem || $b instanceof JoinItem) {
            throw JoinItem::misuse();
        }

        if (is_array($a) && is_array($b)) {
            return array_keys($a) === array_keys($b)
                && array_all($a, static fn (mixed $item, int|string $key): bool => self::equal($item, $b[$key]));
        }

        if ($a instanceof Ref && $b instanceof Ref) {
            return $a->equals($b);
        }

        return is_object($a) && is_object($b) ? $a == $b : $a === $b;
    }

    /**
     * A JSON object: an array with fields, or the empty array. Fields such as "1" are integer keys in PHP: they are
     * fields all the same.
     *
     * @return array<string, mixed>
     */
    public static function object(mixed $value, string $what): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw InvalidArgument::expected($what, 'an object (associative array)', $value);
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * A string that is the same for equal values, and differs for others: values are told apart by it in one pass.
     */
    public static function key(mixed $value): string
    {
        return match (true) {
            $value instanceof JoinItem => throw JoinItem::misuse(),
            // Equal instants can be in different time zones.
            $value instanceof DateTimeInterface => 'T' . Times::format($value),
            is_array($value) => 'A' . serialize(array_map(self::key(...), $value)),
            default => 'V' . serialize($value),
        };
    }
}
