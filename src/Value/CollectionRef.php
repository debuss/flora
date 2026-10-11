<?php declare(strict_types=1);

namespace Flora\Value;

/**
 * Reference to a collection, as returned by Collection('name').
 */
final readonly class CollectionRef implements SchemaRef
{

    public function __construct(public string $name)
    {
        Identifier::assert($name, 'Collection name');
    }
}
