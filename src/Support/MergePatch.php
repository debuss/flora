<?php declare(strict_types=1);

namespace Flora\Support;

/**
 * Deep merge used by Update(): objects are merged recursively, null removes a key, anything else (lists included)
 * replaces the previous value. This is RFC 7386 (JSON Merge Patch), which is also how FaunaDB's Update behaved.
 *
 * @internal
 */
final class MergePatch
{

    /**
     * @param array<string, mixed> $target
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    public static function apply(array $target, array $patch): array
    {
        foreach ($patch as $key => $value) {
            if ($value === null) {
                unset($target[$key]);
            } elseif (self::isObject($value)) {
                $target[$key] = self::apply(self::isObject($target[$key] ?? null) ? $target[$key] : [], $value);
            } else {
                $target[$key] = $value;
            }
        }

        return $target;
    }

    /**
     * @phpstan-assert-if-true array<string, mixed> $value
     */
    private static function isObject(mixed $value): bool
    {
        return is_array($value) && $value !== [] && !array_is_list($value);
    }
}
