<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;
use Flora\Exception\InvalidArgument;

/**
 * Whether all the conditions are true (All(), FQL's And) or any is (Any(), FQL's Or), which are reserved words in PHP.
 * Conditions are evaluated in order, and only until the result is known.
 */
final readonly class Logical implements Expr
{

    /** @var list<mixed> */
    private array $conditions;

    /**
     * @param bool $all true for All(), false for Any()
     */
    public function __construct(private bool $all, mixed ...$conditions)
    {
        $this->conditions = array_values($conditions);
    }

    public function evaluate(Context $context): bool
    {
        foreach ($this->conditions as $condition) {
            $value = $context->evaluate($condition);
            if (!is_bool($value)) {
                throw InvalidArgument::expected(sprintf('Each condition of %s', $this->all ? 'All()' : 'Any()'), 'a boolean', $value);
            }

            // All() is false as soon as a condition is, Any() true as soon as one is.
            if ($value !== $this->all) {
                return $value;
            }
        }

        return $this->all;
    }
}
