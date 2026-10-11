<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;
use Flora\Exception\{InvalidArgument, NotFound};
use Flora\Value\{JoinItem, Page, Ref as RefValue, SchemaRef};

/**
 * Reads the value at a path: Select(['data', 'email'], Get(...)). Returns the default, when given, if there is none.
 */
final readonly class Select implements Expr
{

    public function __construct(private mixed $path, private mixed $from, private mixed $default = null, private bool $hasDefault = false) {}

    public function evaluate(Context $context): mixed
    {
        $path = $context->evaluate($this->path);
        $path = is_array($path) ? $path : [$path];
        $value = $context->evaluate($this->from);

        foreach ($path as $key) {
            if ($value instanceof JoinItem) {
                throw JoinItem::misuse();
            }

            if (!is_string($key) && !is_int($key)) {
                throw InvalidArgument::expected('Select() path', 'a list of strings and integers', $key);
            }

            if (!self::has($value, $key)) {
                return $this->hasDefault
                    ? $context->evaluate($this->default)
                    : throw new NotFound(sprintf('Value not found at path %s.', json_encode($path)));
            }

            $value = self::at($value, $key);
        }

        return $value;
    }

    /**
     * Whether a value has a value at a key: a field of an object, an item of a list, or of a ref (the id and collection
     * of a document, the name of a collection, an index or a function) or a page.
     *
     * @internal
     */
    public static function has(mixed $value, string|int $key): bool
    {
        return match (true) {
            is_array($value) => array_key_exists($key, $value),
            $value instanceof RefValue => in_array($key, ['id', 'collection'], true),
            $value instanceof SchemaRef => $key === 'name',
            $value instanceof Page => in_array($key, ['data', 'before', 'after'], true),
            default => false,
        };
    }

    /**
     * The value at a key the value has.
     *
     * @internal
     */
    public static function at(mixed $value, string|int $key): mixed
    {
        return match (true) {
            $value instanceof RefValue => $key === 'id' ? $value->id : $value->collection,
            $value instanceof SchemaRef => $value->name,
            $value instanceof Page => $value->{$key},
            is_array($value) => $value[$key],
            default => throw InvalidArgument::expected('Select() value', 'an array, a ref or a page', $value),
        };
    }
}
