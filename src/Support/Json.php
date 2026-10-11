<?php declare(strict_types=1);

namespace Flora\Support;

use DateTimeInterface;
use JsonException;
use Flora\Exception\InvalidArgument;
use Flora\Value\{CollectionRef, Date, Ref};

/**
 * JSON encoding of document data, which turns Refs into {"@ref": {...}}, instants into {"@ts": "..."} and dates into
 * {"@date": "..."}, and back.
 *
 * @internal
 */
final class Json
{

    private const int FLAGS = JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /**
     * @throws InvalidArgument for what JSON cannot hold: infinite numbers, NAN, resources...
     */
    public static function encode(mixed $value): string
    {
        try {
            return json_encode(self::tag($value), self::FLAGS);
        } catch (JsonException $e) {
            throw new InvalidArgument(sprintf('This value cannot be written as JSON: %s.', $e->getMessage()), 0, $e);
        }
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
     * Writes instants as {"@ts": ...}: DateTimeInterface has no JSON of its own.
     */
    private static function tag(mixed $value): mixed
    {
        return match (true) {
            $value instanceof DateTimeInterface => ['@ts' => Times::format($value)],
            is_array($value) => array_map(self::tag(...), $value),
            default => $value,
        };
    }

    private static function revive(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (count($value) === 1 && is_string($value['@ts'] ?? null)) {
            return Times::parse($value['@ts']);
        }

        if (count($value) === 1 && is_string($value['@date'] ?? null)) {
            return new Date($value['@date']);
        }

        if (count($value) === 1 && is_array($value['@ref'] ?? null)
            && is_string($value['@ref']['collection'] ?? null) && is_string($value['@ref']['id'] ?? null)) {
            return new Ref(new CollectionRef($value['@ref']['collection']), $value['@ref']['id']);
        }

        return array_map(self::revive(...), $value);
    }
}
