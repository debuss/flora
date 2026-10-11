<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;
use Flora\Exception\InvalidArgument;
use Flora\Value\Ref as RefValue;

final readonly class Ref implements Expr
{

    public function __construct(private mixed $collection, private mixed $id) {}

    public function evaluate(Context $context): RefValue
    {
        $id = $context->evaluate($this->id);
        if (!is_string($id) && !is_int($id)) {
            throw InvalidArgument::expected('Ref() id', 'a string or an integer', $id);
        }

        return new RefValue($context->evaluateCollection($this->collection, 'Ref() collection'), $id);
    }
}
