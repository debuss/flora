<?php declare(strict_types=1);

namespace Flora\Support;

use DateTimeInterface;
use JsonException;
use stdClass;
use Flora\Exception\InvalidArgument;
use Flora\Value\{CollectionRef, Date, JoinItem, Ref};

/**
 * JSON encoding of document data, which turns Refs into {"@ref": {...}}, instants into {"@ts": "..."} and dates into
 * {"@date": "..."}, and back.
 *
 * The fields of the application that start with "@" get another one ({"@ts": "oops"} is written {"@@ts": "oops"}), and
 * lose it on reading: only the values Flora wrote are read as tags. Index paths escape their fields the same way.
 *
 * @internal
 */
final class Json
{

    private const int FLAGS = JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /**
     * @throws InvalidArgument for what JSON cannot hold: infinite numbers, NAN, objects other than refs, instants and
     *     dates...
     */
    public static function encode(mixed $value): string
    {
        return self::raw(self::tag($value));
    }

    /**
     * Encodes JSON that Flora built itself, as it is: neither tagged nor escaped.
     *
     * @throws InvalidArgument for what JSON cannot hold
     */
    public static function raw(mixed $value): string
    {
        try {
            return json_encode($value, self::FLAGS);
        } catch (JsonException $e) {
            throw new InvalidArgument(sprintf('This value cannot be written as JSON: %s.', $e->getMessage()), 0, $e);
        }
    }

    /**
     * A field of an object as it is stored.
     */
    public static function escape(string|int $field): string|int
    {
        return is_string($field) && str_starts_with($field, '@') ? '@' . $field : $field;
    }

    /**
     * Encodes document data, which is always a JSON object even when empty.
     *
     * @param array<string, mixed> $data
     */
    public static function encodeObject(array $data): string
    {
        return $data === [] ? '{}' : self::encode($data);
    }

    /**
     * Integers beyond those of PHP are doubles: PHP wrote them as doubles, and PostgreSQL writes doubles such as 1e300
     * with all their digits.
     */
    public static function decode(string $json): mixed
    {
        return self::revive(json_decode($json, true, 512, JSON_THROW_ON_ERROR));
    }

    /**
     * Writes instants as {"@ts": ...}, DateTimeInterface having no JSON of its own, and escapes the fields of objects.
     * Refs and dates write their own tags.
     */
    private static function tag(mixed $value): mixed
    {
        if (is_array($value) && !array_is_list($value)) {
            $object = [];
            foreach ($value as $field => $item) {
                $object[self::escape($field)] = self::tag($item);
            }

            return $object;
        }

        return match (true) {
            $value === null, is_scalar($value), $value instanceof Ref, $value instanceof Date, $value instanceof JoinItem => $value,
            $value instanceof DateTimeInterface => ['@ts' => Times::format($value)],
            is_array($value) => array_map(self::tag(...), $value),
            default => throw new InvalidArgument(sprintf(
                '%s cannot be written as JSON: values are strings, numbers, booleans, null, arrays, refs, instants and dates.%s',
                get_debug_type($value),
                $value instanceof stdClass ? ' Objects are arrays with string keys: decode JSON with json_decode($json, true).' : '',
            )),
        };
    }

    private static function revive(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::revive(...), $value);
        }

        if (count($value) === 1) {
            $tag = self::tagged((string) array_key_first($value), $value[array_key_first($value)]);
            if ($tag !== null) {
                return $tag;
            }
        }

        $object = [];
        foreach ($value as $field => $item) {
            $object[is_string($field) && str_starts_with($field, '@@') ? substr($field, 1) : $field] = self::revive($item);
        }

        return $object;
    }

    /**
     * The value of a tag, or null when the object is not one: Flora 0.1.0 did not escape the fields of documents, which
     * may hold such objects with any content.
     */
    private static function tagged(string $tag, mixed $content): Ref|Date|DateTimeInterface|null
    {
        try {
            return match (true) {
                $tag === '@ts' && is_string($content) => Times::parse($content),
                $tag === '@date' && is_string($content) => new Date($content),
                $tag === '@ref' && is_array($content) && count($content) === 2
                    && is_string($content['collection'] ?? null) && is_string($content['id'] ?? null)
                    => new Ref(new CollectionRef($content['collection']), $content['id']),
                default => null,
            };
        } catch (InvalidArgument) {
            return null;
        }
    }
}
