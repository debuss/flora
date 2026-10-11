<?php declare(strict_types=1);

namespace Flora\Value;

use Flora\Expr\Lambda;

/**
 * A Lambda() kept as an expression rather than evaluated, as returned by Query(): the body of a stored function.
 */
final readonly class Query
{

    public function __construct(public Lambda $lambda) {}
}
