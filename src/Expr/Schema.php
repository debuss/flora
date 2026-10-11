<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;
use Flora\Value\SchemaSet;

/**
 * The set of the collections, indexes or functions, as returned by Collections(), Indexes() and Functions().
 */
final readonly class Schema implements Expr
{

    /**
     * @param 'collections'|'indexes'|'functions' $kind
     */
    public function __construct(private string $kind) {}

    public function evaluate(Context $context): SchemaSet
    {
        return new SchemaSet($this->kind);
    }
}
