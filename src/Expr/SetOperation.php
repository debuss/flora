<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;
use Flora\Exception\InvalidArgument;
use Flora\Value\{Readable, Set};

/**
 * What Union() and Intersection() share: they combine either sets, into a set read with Paginate(), or lists, into a
 * list.
 *
 * @internal
 */
abstract readonly class SetOperation implements Expr
{

    /** @var list<mixed> */
    private array $operands;

    public function __construct(mixed ...$operands)
    {
        $this->operands = array_values($operands);
    }

    /**
     * @param non-empty-list<Set> $sets
     */
    abstract protected function combineSets(array $sets): Set;

    /**
     * @param non-empty-list<list<mixed>> $lists
     * @return list<mixed>
     */
    abstract protected function combineLists(array $lists): array;

    abstract protected function name(): string;

    /**
     * @return Set|list<mixed>
     */
    public function evaluate(Context $context): Set|array
    {
        $operands = $context->evaluateEach($this->operands);

        if ($operands === []) {
            throw new InvalidArgument(sprintf('%s() needs at least one set or list.', $this->name()));
        }

        if (array_all($operands, static fn (mixed $operand): bool => $operand instanceof Set)) {
            /** @var non-empty-list<Set> $operands */
            return $this->combineSets($operands);
        }

        if (array_all($operands, static fn (mixed $operand): bool => is_array($operand) && array_is_list($operand))) {
            /** @var non-empty-list<list<mixed>> $operands */
            return $this->combineLists($operands);
        }

        if (array_any($operands, static fn (mixed $operand): bool => $operand instanceof Readable && !$operand instanceof Set)) {
            throw new InvalidArgument(sprintf('%s() cannot combine a Filter(), Reverse() or Distinct(): apply it to the result instead.', $this->name()));
        }

        throw new InvalidArgument(sprintf('%s() combines either sets (MatchIndex(), Documents()...) or lists, not both.', $this->name()));
    }
}
