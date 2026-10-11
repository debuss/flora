<?php declare(strict_types=1);

namespace Flora\Value;

/**
 * One page of a set. Cursors are lists of the index values followed by the document ref, and are passed back to
 * Paginate() through its "after" or "before" option.
 */
final readonly class Page
{

    /**
     * @param list<mixed> $data
     * @param list<mixed>|null $before
     * @param list<mixed>|null $after
     */
    public function __construct(public array $data, public ?array $before = null, public ?array $after = null) {}
}
