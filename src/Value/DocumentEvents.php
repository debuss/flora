<?php declare(strict_types=1);

namespace Flora\Value;

use Flora\Schema\IndexField;

/**
 * The events of a document, as returned by Events(): read by time, their cursors are a time followed by the ref.
 */
final readonly class DocumentEvents implements Readable
{

    public function __construct(public Ref $document) {}

    public function collections(): array
    {
        return [$this->document->collection];
    }

    public function values(): array
    {
        return [new IndexField(['ts'])];
    }
}
