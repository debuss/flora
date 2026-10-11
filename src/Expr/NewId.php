<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;

/**
 * A new document id, as Create() would give: for a document whose ref is needed before it is created.
 */
final readonly class NewId implements Expr
{

    public function evaluate(Context $context): string
    {
        return $context->ids->next();
    }
}
