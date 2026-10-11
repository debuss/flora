<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;
use Flora\Exception\InvalidArgument;
use Flora\Value\FunctionRef as FunctionRefValue;

/**
 * Calls a stored function, by its ref or its name: Call(FunctionRef('transfer'), $from, $to, 100). A function taking
 * one parameter receives the argument, one destructuring several the list of the arguments.
 */
final readonly class Call implements Expr
{

    /** @var list<mixed> */
    private array $arguments;

    public function __construct(private mixed $function, mixed ...$arguments)
    {
        $this->arguments = array_values($arguments);
    }

    public function evaluate(Context $context): mixed
    {
        $function = $context->evaluate($this->function);
        $ref = match (true) {
            $function instanceof FunctionRefValue => $function,
            is_string($function) => new FunctionRefValue($function),
            default => throw InvalidArgument::expected('Call() function', 'a FunctionRef() or the name of a function', $function),
        };
        $arguments = $context->evaluateEach($this->arguments);

        return $context->function($ref)->apply(count($arguments) === 1 ? $arguments[0] : $arguments);
    }
}
