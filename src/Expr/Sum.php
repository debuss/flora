<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;
use Flora\Exception\InvalidArgument;

/**
 * The sum of the numbers of a list, a page or a set, as returned by Sum(), or their mean, by Mean(). The sum of nothing
 * is 0; nothing has no mean. A set is read whole.
 */
final readonly class Sum implements Expr
{

    public function __construct(private mixed $collection, private bool $mean = false) {}

    public function evaluate(Context $context): int|float
    {
        $function = $this->mean ? 'Mean()' : 'Sum()';
        $sum = 0;
        $count = 0;
        foreach ($context->items($context->evaluate($this->collection), sprintf('%s collection', $function)) as $item) {
            if (!is_int($item) && !is_float($item)) {
                throw InvalidArgument::expected(sprintf('Each item of %s', $function), 'a number', $item);
            }

            $sum += $item;
            ++$count;
        }

        if (!$this->mean) {
            return $sum;
        }

        return $count === 0 ? throw new InvalidArgument('Mean() needs at least one number.') : $sum / $count;
    }
}
