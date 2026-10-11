<?php declare(strict_types=1);

namespace Flora\Value;

use Flora\Schema\IndexDefinition;

/**
 * The entries of an index whose terms match: given values (SetRef) or the entries of another set (JoinSet).
 */
interface IndexMatch extends Set
{

    public IndexDefinition $index { get; }
}
