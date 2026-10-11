<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;
use Flora\Exception\InvalidArgument;

/**
 * The [field, value] pairs of an object, in order: ToArray(['a' => 1, 'b' => 2]) is [['a', 1], ['b', 2]].
 */
final readonly class ToArray implements Expr
{

    public function __construct(private mixed $object) {}

    /**
     * @return list<array{string, mixed}>
     */
    public function evaluate(Context $context): array
    {
        $object = $context->evaluate($this->object);
        if (!is_array($object) || ($object !== [] && array_is_list($object))) {
            throw InvalidArgument::expected('ToArray() object', 'an object', $object);
        }

        // PHP turns fields such as "1" into integer keys: they are fields all the same.
        return array_map(static fn (int|string $field, mixed $value): array => [(string) $field, $value], array_keys($object), $object);
    }
}
