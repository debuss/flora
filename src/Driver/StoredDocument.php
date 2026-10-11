<?php declare(strict_types=1);

namespace Flora\Driver;

use Flora\Value\{CollectionRef, Ref};

/**
 * A row of a collection table.
 */
final readonly class StoredDocument
{

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(public string $id, public int $ts, public array $data) {}

    /**
     * The shape documents have in query results: ['ref' => Ref, 'ts' => int, 'data' => array].
     *
     * @return array{ref: Ref, ts: int, data: array<string, mixed>}
     */
    public function toDocument(CollectionRef $collection): array
    {
        return ['ref' => new Ref($collection, $this->id), 'ts' => $this->ts, 'data' => $this->data];
    }
}
