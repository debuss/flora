<?php declare(strict_types=1);

namespace Flora\Value;

/**
 * Reference to an index, as returned by Index('name').
 */
final readonly class IndexRef implements SchemaRef
{

    public function __construct(public string $name)
    {
        Identifier::assert($name, 'Index name');
    }
}
