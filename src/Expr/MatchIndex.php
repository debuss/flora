<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;
use Flora\Exception\InvalidArgument;
use Flora\Value\SetRef;

/**
 * The set of entries of an index whose terms equal the given values (FQL's Match, a reserved word in PHP).
 */
final readonly class MatchIndex implements Expr
{

    /** @var list<mixed> */
    private array $terms;

    public function __construct(private mixed $index, mixed ...$terms)
    {
        $this->terms = array_values($terms);
    }

    public function evaluate(Context $context): SetRef
    {
        $index = $context->index($context->evaluateIndex($this->index, 'MatchIndex() index'));
        $terms = $context->evaluateEach($this->terms);

        if (count($terms) !== count($index->terms)) {
            throw new InvalidArgument(sprintf(
                'Index "%s" has %d term(s), %d given to MatchIndex().',
                $index->name,
                count($index->terms),
                count($terms),
            ));
        }

        return new SetRef($index, $terms);
    }
}
