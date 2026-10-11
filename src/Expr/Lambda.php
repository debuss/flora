<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;
use Flora\Exception\InvalidArgument;
use Flora\Value\BoundLambda;

/**
 * An anonymous function: Lambda('x', ...) takes one value, Lambda(['a', 'b'], ...) destructures a list ("_" skips an
 * item). Parameters are read with Variable().
 */
final readonly class Lambda implements Expr
{

    /** @var string|list<string> */
    private string|array $params;

    /**
     * @param string|array<mixed> $params a name, or a list of names
     */
    public function __construct(string|array $params, private mixed $body)
    {
        if (is_array($params) && (!array_is_list($params) || !array_all($params, static fn (mixed $param): bool => is_string($param)))) {
            throw InvalidArgument::expected('Lambda() params', 'a string or a list of strings', $params);
        }

        /** @var string|list<string> $params */
        $this->params = $params;
    }

    public function evaluate(Context $context): BoundLambda
    {
        return new BoundLambda($this->params, $this->body, $context);
    }
}
