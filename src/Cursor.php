<?php declare(strict_types=1);

namespace Flora;

use DateTimeInterface;
use JsonException;
use Flora\Exception\InvalidArgument;
use Flora\Support\Times;
use Flora\Value\{CollectionRef, Date, FunctionRef, IndexRef, Ref};

/**
 * The cursors of pages as URL-safe strings, to send them to the clients of an application and read them back:
 *
 *     $page = $client->query(Paginate($set, ['after' => Cursor::decode($_GET['after'] ?? null)]));
 *     $next = Cursor::encode($page->after);
 *
 * A cursor is the values of an entry followed by its ref: the string is the base64url of their JSON, where refs,
 * instants and dates are tagged. It is not signed: a client may change it, but only to start a page elsewhere in the set
 * it paginates. Decoding reads nothing but the values an index holds, and throws InvalidArgument for anything else.
 */
final class Cursor
{

    private const int FLAGS = JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /**
     * @param list<mixed>|null $cursor the "before" or "after" of a page
     * @throws InvalidArgument when the cursor holds a value that no index holds
     */
    public static function encode(?array $cursor): ?string
    {
        if ($cursor === null) {
            return null;
        }

        try {
            $json = json_encode(array_map(self::write(...), $cursor), self::FLAGS);
        } catch (JsonException $e) {
            throw new InvalidArgument(sprintf('This cursor cannot be written as JSON: %s.', $e->getMessage()), 0, $e);
        }

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * @return list<mixed>|null the cursor, to pass to Paginate() as "before" or "after"
     * @throws InvalidArgument when the string is not a cursor
     */
    public static function decode(?string $cursor): ?array
    {
        if ($cursor === null) {
            return null;
        }

        $json = preg_match('/^[A-Za-z0-9_-]+$/', $cursor) === 1 ? base64_decode(strtr($cursor, '-_', '+/'), true) : false;
        try {
            $values = $json === false ? null : json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $values = null;
        }

        if (!is_array($values) || $values === [] || !array_is_list($values)) {
            throw self::malformed();
        }

        return array_map(self::read(...), $values);
    }

    private static function write(mixed $value): mixed
    {
        if (is_array($value) && !array_is_list($value)) {
            $object = [];
            foreach ($value as $field => $item) {
                $object[is_string($field) && str_starts_with($field, '@') ? '@' . $field : $field] = self::write($item);
            }

            return $object;
        }

        return match (true) {
            $value === null, is_scalar($value) => $value,
            is_array($value) => array_map(self::write(...), $value),
            $value instanceof Ref => ['@ref' => [$value->collection->name, $value->id]],
            $value instanceof CollectionRef => ['@collection' => $value->name],
            $value instanceof IndexRef => ['@index' => $value->name],
            $value instanceof FunctionRef => ['@function' => $value->name],
            $value instanceof DateTimeInterface => ['@ts' => Times::format($value)],
            $value instanceof Date => ['@date' => $value->value],
            default => throw new InvalidArgument(sprintf('A cursor cannot hold %s: it is not a value of an index.', get_debug_type($value))),
        };
    }

    private static function read(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::read(...), $value);
        }

        $tag = (string) array_key_first($value);
        if (str_starts_with($tag, '@') && !str_starts_with($tag, '@@')) {
            try {
                $tagged = count($value) === 1 ? self::tagged($tag, $value[$tag]) : null;
            } catch (InvalidArgument $e) {
                throw self::malformed($e);
            }

            return $tagged ?? throw self::malformed();
        }

        $object = [];
        foreach ($value as $field => $item) {
            if (is_string($field) && str_starts_with($field, '@') && !str_starts_with($field, '@@')) {
                throw self::malformed();
            }
            $object[is_string($field) && str_starts_with($field, '@@') ? substr($field, 1) : $field] = self::read($item);
        }

        return $object;
    }

    /**
     * The value of a tag, null when the tag or its content is unknown.
     *
     * @throws InvalidArgument when the content is not a valid name, id, instant or date
     */
    private static function tagged(string $tag, mixed $content): Ref|CollectionRef|IndexRef|FunctionRef|DateTimeInterface|Date|null
    {
        if ($tag === '@ref') {
            return is_array($content) && array_is_list($content) && count($content) === 2 && is_string($content[0]) && is_string($content[1])
                ? new Ref(new CollectionRef($content[0]), $content[1])
                : null;
        }

        if (!is_string($content)) {
            return null;
        }

        return match ($tag) {
            '@collection' => new CollectionRef($content),
            '@index' => new IndexRef($content),
            '@function' => new FunctionRef($content),
            '@ts' => Times::parse($content),
            '@date' => new Date($content),
            default => null,
        };
    }

    private static function malformed(?InvalidArgument $previous = null): InvalidArgument
    {
        return new InvalidArgument('This is not a cursor of Flora: it may have been cut or changed.', 0, $previous);
    }
}
