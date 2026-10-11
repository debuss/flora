<?php declare(strict_types=1);

namespace Flora\Value;

/**
 * Reference to a stored function, as returned by FunctionRef('name').
 */
final readonly class FunctionRef implements SchemaRef
{

    public function __construct(public string $name)
    {
        Identifier::assert($name, 'Function name');
    }
}
