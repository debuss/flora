<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;
use Flora\Exception\InvalidArgument;

/**
 * Evaluates expressions in order and returns the value of the last one: FQL's Do, a word of PHP. A list evaluates its
 * expressions in order too, but returns all their values.
 *
 *     Sequence(Update($ref, [...]), Get($ref))
 */
final readonly class Sequence implements Expr
{

    /** @var non-empty-list<mixed> */
    private array $exprs;

    public function __construct(mixed ...$exprs)
    {
        $this->exprs = array_values($exprs) ?: throw new InvalidArgument('Sequence() needs at least one expression.');
    }

    public function evaluate(Context $context): mixed
    {
        $value = null;
        foreach ($this->exprs as $expr) {
            $value = $context->evaluate($expr);
        }

        return $value;
    }
}
