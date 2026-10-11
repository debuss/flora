<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;
use Flora\Exception\InvalidArgument;
use Flora\Value\JoinItem;

/**
 * All the values at a path, through lists: a field of the path is read in each item of a list it meets, an index in
 * the list itself. Missing values are skipped.
 *
 *     SelectAll(['data', 'tags', 'name'], Get($post))           // the name of each tag
 *     SelectAll(['foo', 0], [['foo' => [0, 1]], ['foo' => [2, 3]]])   // [0, 2]
 */
final readonly class SelectAll implements Expr
{

    public function __construct(private mixed $path, private mixed $from) {}

    /**
     * @return list<mixed>
     */
    public function evaluate(Context $context): array
    {
        $path = $context->evaluate($this->path);
        $path = is_array($path) ? array_values($path) : [$path];
        foreach ($path as $key) {
            if (!is_string($key) && !is_int($key)) {
                throw InvalidArgument::expected('SelectAll() path', 'a list of strings and integers', $key);
            }
        }

        /** @var list<string|int> $path checked above */
        return self::select($context->evaluate($this->from), $path);
    }

    /**
     * @param list<string|int> $path
     * @return list<mixed>
     */
    private static function select(mixed $value, array $path): array
    {
        if ($value instanceof JoinItem) {
            throw JoinItem::misuse();
        }

        if ($path === []) {
            return [$value];
        }

        [$key, $rest] = [$path[0], array_slice($path, 1)];

        // A field is read in each item of a list.
        if (is_string($key) && is_array($value) && array_is_list($value)) {
            return array_merge(...array_map(static fn (mixed $item): array => self::select($item, $path), $value));
        }

        return Select::has($value, $key) ? self::select(Select::at($value, $key), $rest) : [];
    }
}
