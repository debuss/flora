<?php declare(strict_types=1);

namespace Flora\Value;

use Flora\Context;
use Flora\Driver\IndexEntry;
use Flora\Exception\InvalidArgument;

/**
 * The collections, indexes or functions of the schema, as returned by Collections(), Indexes() and Functions(): their
 * refs, sorted by name. The schema has no history: inside At(), they are those of now.
 */
final readonly class SchemaSet implements Readable
{

    /**
     * @param 'collections'|'indexes'|'functions' $kind
     */
    public function __construct(public string $kind) {}

    public function collections(): array
    {
        return [];
    }

    public function values(): array
    {
        return [];
    }

    /**
     * Reads the refs like Driver::scan() reads a set, from a cursor holding a ref.
     *
     * @param list<mixed>|null $cursor
     * @return list<IndexEntry>
     */
    public function scan(Context $context, ?array $cursor, bool $inclusive, bool $backward, int $limit): array
    {
        $names = $this->names($context);
        if ($backward) {
            $names = array_reverse($names);
        }

        if ($cursor !== null) {
            $from = $cursor[0] instanceof SchemaRef ? $cursor[0]->name : throw InvalidArgument::expected('A cursor of the schema', 'a ref of it', $cursor[0]);
            $names = array_filter($names, static function (string $name) use ($from, $inclusive, $backward): bool {
                $order = strcmp($name, $from) * ($backward ? -1 : 1);

                return $order > 0 || ($inclusive && $order === 0);
            });
        }

        return array_map(fn (string $name): IndexEntry => new IndexEntry($this->ref($name)), array_slice(array_values($names), 0, $limit));
    }

    public function count(Context $context): int
    {
        return count($this->names($context));
    }

    /**
     * @return list<string>
     */
    private function names(Context $context): array
    {
        // Sorted by code point, whatever the collation of the database.
        $names = $context->driver->schema($this->kind);
        sort($names, SORT_STRING);

        return $names;
    }

    private function ref(string $name): SchemaRef
    {
        return match ($this->kind) {
            'collections' => new CollectionRef($name),
            'indexes' => new IndexRef($name),
            'functions' => new FunctionRef($name),
        };
    }
}
