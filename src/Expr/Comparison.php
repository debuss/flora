<?php declare(strict_types=1);

namespace Flora\Expr;

use DateTimeInterface;
use Flora\Context;
use Flora\Exception\InvalidArgument;
use Flora\Value\{Date, JoinItem};

/**
 * Whether values are in order, as returned by LT(), LTE(), GT() and GTE(): LT(1, 2, 3) is true. Numbers compare as
 * numbers, strings by code point, instants by time and dates by day; comparing values of different kinds throws.
 */
final readonly class Comparison implements Expr
{

    /** @var list<mixed> */
    private array $values;

    /**
     * @param '<'|'<='|'>'|'>=' $operator
     */
    public function __construct(private string $operator, mixed ...$values)
    {
        $this->values = array_values($values);
    }

    public function evaluate(Context $context): bool
    {
        $values = $context->evaluateEach($this->values);

        foreach ($values as $value) {
            if ($value instanceof JoinItem) {
                throw JoinItem::misuse();
            }
        }

        $comparable = array_all($values, static fn (mixed $value): bool => is_int($value) || is_float($value))
            || array_all($values, static fn (mixed $value): bool => $value instanceof DateTimeInterface)
            || array_all($values, static fn (mixed $value): bool => $value instanceof Date);
        $strings = array_all($values, static fn (mixed $value): bool => is_string($value));
        if (!$comparable && !$strings) {
            throw new InvalidArgument(sprintf('%s compares numbers, strings, instants or dates, of one kind.', $this->name()));
        }

        for ($i = 1; $i < count($values); ++$i) {
            [$a, $b] = [$values[$i - 1], $values[$i]];
            $order = match (true) {
                is_string($a) && is_string($b) => strcmp($a, $b),
                $a instanceof Date && $b instanceof Date => strcmp($a->value, $b->value),
                default => $a <=> $b,
            };
            $holds = match ($this->operator) {
                '<' => $order < 0,
                '<=' => $order <= 0,
                '>' => $order > 0,
                '>=' => $order >= 0,
            };

            if (!$holds) {
                return false;
            }
        }

        return true;
    }

    private function name(): string
    {
        return match ($this->operator) {
            '<' => 'LT()',
            '<=' => 'LTE()',
            '>' => 'GT()',
            '>=' => 'GTE()',
        };
    }
}
