<?php declare(strict_types=1);

namespace Flora\Value;

/**
 * Reference to something of the schema, by its name: a collection, an index or a function.
 */
interface SchemaRef
{

    public string $name { get; }
}
