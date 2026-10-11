<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;

/**
 * A node of a query. Functions such as Get() or Ref() build them, the Client evaluates them.
 */
interface Expr
{

    public function evaluate(Context $context): mixed;
}
