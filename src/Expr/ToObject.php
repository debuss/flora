<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;
use Flora\Exception\InvalidArgument;

/**
 * The object of a list of [field, value] pairs: ToObject([['a', 1], ['b', 2]]) is ['a' => 1, 'b' => 2]. A field
 * given twice keeps its last value.
 */
final readonly class ToObject implements Expr
{

    public function __construct(private mixed $pairs) {}

    /**
     * @return array<string, mixed>
     */
    public function evaluate(Context $context): array
    {
        $pairs = $context->evaluate($this->pairs);
        if (!is_array($pairs) || !array_is_list($pairs)) {
            throw InvalidArgument::expected('ToObject() pairs', 'a list of [field, value] pairs', $pairs);
        }

        $object = [];
        foreach ($pairs as $pair) {
            if (!is_array($pair) || !array_is_list($pair) || count($pair) !== 2 || !is_string($pair[0])) {
                throw InvalidArgument::expected('Each pair of ToObject()', 'a [field, value] pair, the field a string', $pair);
            }

            $object[$pair[0]] = $pair[1];
        }

        return $object;
    }
}
