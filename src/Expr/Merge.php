<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;
use Flora\Exception\InvalidArgument;
use Flora\Support\Values;
use Flora\Value\BoundLambda;

/**
 * An object with the fields of others, merged one level deep: Merge(['a' => 1, 'b' => 2], ['b' => 3, 'c' => null])
 * is ['a' => 1, 'b' => 3]. A null value removes the field. The values can be an object or a list of objects, merged in
 * order; a resolver, Lambda(['field', 'left', 'right'], ...), gives the value of a field both have.
 */
final readonly class Merge implements Expr
{

    public function __construct(private mixed $object, private mixed $values, private mixed $resolver = null) {}

    /**
     * @return array<string, mixed>
     */
    public function evaluate(Context $context): array
    {
        $merged = self::object($context->evaluate($this->object), 'Merge() object');
        $values = $context->evaluate($this->values);
        $resolver = $context->evaluate($this->resolver);
        if ($resolver !== null && !$resolver instanceof BoundLambda) {
            throw InvalidArgument::expected('Merge() resolver', 'a Lambda()', $resolver);
        }

        $objects = is_array($values) && $values !== [] && array_is_list($values) ? $values : [$values];
        foreach ($objects as $object) {
            foreach (self::object($object, 'Merge() values') as $field => $value) {
                if ($resolver !== null && array_key_exists($field, $merged)) {
                    $value = $resolver->apply([(string) $field, $merged[$field], $value]);
                }

                if ($value === null) {
                    unset($merged[$field]);
                } else {
                    $merged[$field] = $value;
                }
            }
        }

        return $merged;
    }

    /**
     * @return array<string, mixed>
     */
    private static function object(mixed $value, string $what): array
    {
        return Values::object($value, $what);
    }
}
