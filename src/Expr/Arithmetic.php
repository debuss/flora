<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;
use Flora\Exception\InvalidArgument;
use Flora\Value\JoinItem;

/**
 * Arithmetic on numbers, folded from left to right: Add(1, 2, 3) is 6, Subtract(10, 2, 3) is 5. Dividing integers
 * gives an integer, truncated like FQL did: Divide(7, 2) is 3, Divide(7.0, 2) is 3.5.
 *
 * Greatest() and Least() are FQL's Max and Min, which would take the place of PHP's max() and min() where they are
 * imported.
 */
final readonly class Arithmetic implements Expr
{

    /** @var list<mixed> */
    private array $values;

    /**
     * @param '+'|'-'|'*'|'/'|'%'|'greatest'|'least' $operator
     */
    public function __construct(private string $operator, mixed ...$values)
    {
        $this->values = array_values($values);
    }

    public function evaluate(Context $context): int|float
    {
        $values = $context->evaluateEach($this->values);

        foreach ($values as $value) {
            if ($value instanceof JoinItem) {
                throw JoinItem::misuse();
            }

            if (!is_int($value) && !is_float($value)) {
                throw InvalidArgument::expected(sprintf('Each value of %s', $this->name()), 'a number', $value);
            }
        }

        if ($values === []) {
            throw new InvalidArgument(sprintf('%s needs at least one number.', $this->name()));
        }

        return array_reduce(array_slice($values, 1), $this->apply(...), $values[0]);
    }

    private function apply(int|float $a, int|float $b): int|float
    {
        if (($this->operator === '/' || $this->operator === '%') && $b == 0) {
            throw new InvalidArgument(sprintf('%s by zero.', $this->name()));
        }

        return match ($this->operator) {
            '+' => $a + $b,
            '-' => $a - $b,
            '*' => $a * $b,
            '/' => is_int($a) && is_int($b) ? intdiv($a, $b) : $a / $b,
            '%' => is_int($a) && is_int($b) ? $a % $b : fmod($a, $b),
            'greatest' => max($a, $b),
            'least' => min($a, $b),
        };
    }

    private function name(): string
    {
        return match ($this->operator) {
            '+' => 'Add()',
            '-' => 'Subtract()',
            '*' => 'Multiply()',
            '/' => 'Divide()',
            '%' => 'Modulo()',
            'greatest' => 'Greatest()',
            'least' => 'Least()',
        };
    }
}
