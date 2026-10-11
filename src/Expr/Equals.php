<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;
use Flora\Support\Values;

/**
 * Whether all the values are equal. Refs are equal when they point to the same document.
 */
final readonly class Equals implements Expr
{

    /** @var list<mixed> */
    private array $values;

    public function __construct(mixed ...$values)
    {
        $this->values = array_values($values);
    }

    public function evaluate(Context $context): bool
    {
        $values = $context->evaluateEach($this->values);

        foreach ($values as $value) {
            if (!Values::equal($values[0], $value)) {
                return false;
            }
        }

        return true;
    }
}
