<?php declare(strict_types=1);

namespace Flora\Value;

use Flora\Context;
use Flora\Exception\InvalidArgument;

/**
 * A Lambda() once evaluated: its parameters, its body and the variables visible where it was declared.
 */
final readonly class BoundLambda
{

    /**
     * @param string|list<string> $params
     */
    public function __construct(private string|array $params, private mixed $body, private Context $context) {}

    public function apply(mixed $argument): mixed
    {
        if (is_string($this->params)) {
            return $this->context->withVariables([$this->params => $argument])->evaluate($this->body);
        }

        if (!is_array($argument) || !array_is_list($argument) || count($argument) !== count($this->params)) {
            throw new InvalidArgument(sprintf(
                'Lambda expects a list of %d values to destructure, %s given.',
                count($this->params),
                get_debug_type($argument),
            ));
        }

        $variables = [];
        foreach ($this->params as $i => $name) {
            if ($name !== '_') {
                $variables[$name] = $argument[$i];
            }
        }

        return $this->context->withVariables($variables)->evaluate($this->body);
    }
}
