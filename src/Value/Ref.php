<?php declare(strict_types=1);

namespace Flora\Value;

use JsonSerializable;
use Flora\Exception\InvalidArgument;

/**
 * Reference to a document: a collection plus a 64-bit identifier, kept as a string like FaunaDB did.
 */
final readonly class Ref implements JsonSerializable
{

    public string $id;

    public function __construct(public CollectionRef $collection, string|int $id)
    {
        $id = (string) $id;
        if (!preg_match('/^[1-9][0-9]{0,18}$/', $id) || (string) (int) $id !== $id) {
            throw new InvalidArgument(sprintf('Document id "%s" must be a positive 64-bit integer.', $id));
        }

        $this->id = $id;
    }

    public function equals(self $other): bool
    {
        return $this->id === $other->id && $this->collection->name === $other->collection->name;
    }

    /**
     * Refs stored inside documents are encoded with the same tag FaunaDB used on the wire.
     *
     * @return array{'@ref': array{collection: string, id: string}}
     */
    public function jsonSerialize(): array
    {
        return ['@ref' => ['collection' => $this->collection->name, 'id' => $this->id]];
    }
}
