<?php declare(strict_types=1);

namespace Flora\Driver;

use Flora\Support\Values;
use Flora\Value\{Ref, SchemaRef};

/**
 * One entry of an index: the values it declares and the ref of the document it points to, or of a collection, an index
 * or a function in the sets of the schema.
 */
final readonly class IndexEntry
{

    /**
     * @param list<mixed> $values
     * @param array{ts: int, action: string, document: Ref, data: array<array-key, mixed>}|null $event the event this entry
     *        is, when it belongs to the events of a document
     */
    public function __construct(public Ref|SchemaRef $ref, public array $values = [], public ?array $event = null) {}

    /**
     * What Paginate() returns for this entry: the ref when the index has no values, the value when it has one, the
     * list of values otherwise.
     */
    public function item(): mixed
    {
        if ($this->event !== null) {
            return $this->event;
        }

        return match (count($this->values)) {
            0 => $this->ref,
            1 => $this->values[0],
            default => $this->values,
        };
    }

    /**
     * @return list<mixed>
     */
    public function cursor(): array
    {
        return [...$this->values, $this->ref];
    }

    /**
     * Removes the entries repeated in a list sorted by tuple, where they follow each other, then keeps the first ones.
     *
     * @param list<self> $entries
     * @return list<self>
     */
    public static function distinct(array $entries, int $limit): array
    {
        $distinct = [];
        foreach ($entries as $entry) {
            $last = $distinct[count($distinct) - 1] ?? null;
            if ($last === null || !Values::equal($last->ref, $entry->ref) || !Values::equal($last->values, $entry->values)) {
                $distinct[] = $entry;
            }
        }

        return array_slice($distinct, 0, $limit);
    }
}
