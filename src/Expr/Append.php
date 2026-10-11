<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;
use Flora\Exception\InvalidArgument;

/**
 * The base list followed by the elements, in FQL's order of arguments: Append([4, 5], [1, 2, 3]) is [1, 2, 3, 4, 5].
 */
final readonly class Append implements Expr
{

    public function __construct(private mixed $elements, private mixed $base) {}

    /**
     * @return list<mixed>
     */
    public function evaluate(Context $context): array
    {
        return [...self::list($context->evaluate($this->base), 'base'), ...self::list($context->evaluate($this->elements), 'elements')];
    }

    /**
     * @return list<mixed>
     *
     * @internal
     */
    public static function list(mixed $value, string $what, string $function = 'Append()'): array
    {
        return is_array($value) && array_is_list($value) ? $value : throw InvalidArgument::expected(sprintf('%s %s', $function, $what), 'a list', $value);
    }
}
