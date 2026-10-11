<?php declare(strict_types=1);

namespace Flora;

use ArrayObject;
use Generator;
use Flora\Driver\{Driver, IndexEntry};
use Flora\Exception\{InvalidArgument, NotFound};
use Flora\Expr\{Expr, Lambda};
use Flora\Id\IdGenerator;
use Flora\Schema\IndexDefinition;
use Flora\Support\Serializer;
use Flora\Value\{BoundLambda, CollectionRef, DistinctSet, DocumentEvents, FilteredSet, FunctionRef, IndexRef, Page, Readable, Ref, ReversedSet, SchemaRef, SchemaSet, Set, SetEvents};

// PHP looks for functions in this namespace first: count() is the global one, whatever this namespace defines.
use function count;

/**
 * The state an expression is evaluated in: the transaction it belongs to and the variables in scope.
 */
final readonly class Context
{

    /** How many entries are read at once when reading a whole set. */
    private const int CHUNK = 1024;

    /** How deep calls of stored functions nest: a function calling itself for ever stops there. */
    public const int MAX_CALLS = 64;

    /**
     * @param int $ts timestamp of the query in microseconds, shared by all its writes
     * @param ArrayObject<string, IndexDefinition> $indexes indexes already loaded during this query
     * @param ArrayObject<string, Lambda> $functions stored functions already loaded during this query
     * @param array<string, mixed> $variables
     * @param int|null $at the time reads see the database at, inside At()
     * @param int $calls how many calls of stored functions the expression is in
     */
    private function __construct(
        public Driver $driver,
        public IdGenerator $ids,
        public int $ts,
        private ArrayObject $indexes,
        private ArrayObject $functions,
        private array $variables,
        public ?int $at = null,
        private int $calls = 0,
    ) {}

    public static function start(Driver $driver, IdGenerator $ids, int $ts): self
    {
        return new self($driver, $ids, $ts, new ArrayObject(), new ArrayObject(), []);
    }

    /**
     * Evaluates expressions, including those nested in arrays. Anything else evaluates to itself.
     */
    public function evaluate(mixed $value): mixed
    {
        return match (true) {
            $value instanceof Expr => $value->evaluate($this),
            is_array($value) => array_map($this->evaluate(...), $value),
            default => $value,
        };
    }

    /**
     * Evaluates each value of an array, keeping its keys.
     *
     * @param array<array-key, mixed> $values
     * @return ($values is list<mixed> ? list<mixed> : array<array-key, mixed>)
     */
    public function evaluateEach(array $values): array
    {
        return array_map($this->evaluate(...), $values);
    }

    /**
     * @param array<string, mixed> $variables
     */
    public function withVariables(array $variables): self
    {
        return new self($this->driver, $this->ids, $this->ts, $this->indexes, $this->functions, [...$this->variables, ...$variables], $this->at, $this->calls);
    }

    /**
     * The context of At(): reads see the database as it was at a time.
     */
    public function withAt(int $at): self
    {
        return new self($this->driver, $this->ids, $this->ts, $this->indexes, $this->functions, $this->variables, $at, $this->calls);
    }

    /**
     * A stored function, ready to be applied: its body sees none of the variables of the query, and the database as
     * the query does (inside At(), as it was then).
     */
    public function function(FunctionRef $ref): BoundLambda
    {
        if ($this->calls >= self::MAX_CALLS) {
            throw new InvalidArgument(sprintf('Calls of stored functions nest more than %d deep: does "%s" call itself for ever?', self::MAX_CALLS, $ref->name));
        }

        $lambda = $this->functions[$ref->name] ?? null;
        if ($lambda === null) {
            $function = $this->driver->findFunction($ref->name) ?? throw new NotFound(sprintf('Function "%s" does not exist.', $ref->name));
            $lambda = Serializer::unserialize($function['body']);
            if (!$lambda instanceof Lambda) {
                throw new InvalidArgument(sprintf('The body of function "%s" is not a Lambda().', $ref->name));
            }
            $this->functions->offsetSet($ref->name, $lambda);
        }

        return $lambda->evaluate(new self($this->driver, $this->ids, $this->ts, $this->indexes, $this->functions, [], $this->at, $this->calls + 1));
    }

    /**
     * Forgets a stored function loaded during this query, which was changed or deleted.
     */
    public function forgetFunction(string $name): void
    {
        if (isset($this->functions[$name])) {
            $this->functions->offsetUnset($name);
        }
    }

    /**
     * Throws inside At(), which only reads the past.
     */
    public function assertWritable(string $what): void
    {
        if ($this->at !== null) {
            throw new InvalidArgument(sprintf('%s writes, and At() only reads the past.', $what));
        }
    }

    public function variable(string $name): mixed
    {
        if (!array_key_exists($name, $this->variables)) {
            throw new NotFound(sprintf('Variable "%s" is not defined.', $name));
        }

        return $this->variables[$name];
    }

    public function index(IndexRef $ref): IndexDefinition
    {
        $index = $this->indexes[$ref->name] ?? null;
        if ($index === null) {
            $index = $this->driver->findIndex($ref->name) ?? throw new NotFound(sprintf('Index "%s" does not exist.', $ref->name));
            $this->indexes->offsetSet($ref->name, $index);
        }

        return $index;
    }

    /**
     * Forgets the indexes loaded during this query which were deleted or changed: those named, or those of a
     * collection.
     */
    public function forgetIndexes(?string $name = null, ?string $collection = null): void
    {
        foreach ($this->indexes->getArrayCopy() as $loaded => $index) {
            if ($loaded === $name || $index->source->name === $collection) {
                $this->indexes->offsetUnset($loaded);
            }
        }
    }

    public function evaluateString(mixed $expr, string $what): string
    {
        $value = $this->evaluate($expr);

        return is_string($value) ? $value : throw InvalidArgument::expected($what, 'a string', $value);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function evaluateArray(mixed $expr, string $what): array
    {
        $value = $this->evaluate($expr);

        return is_array($value) ? $value : throw InvalidArgument::expected($what, 'an array', $value);
    }

    public function evaluateCollection(mixed $expr, string $what): CollectionRef
    {
        $value = $this->evaluate($expr);

        return $value instanceof CollectionRef ? $value : throw InvalidArgument::expected($what, 'a Collection()', $value);
    }

    public function evaluateIndex(mixed $expr, string $what): IndexRef
    {
        $value = $this->evaluate($expr);

        return $value instanceof IndexRef ? $value : throw InvalidArgument::expected($what, 'an Index()', $value);
    }

    public function evaluateRef(mixed $expr, string $what): Ref
    {
        $value = $this->evaluate($expr);

        return $value instanceof Ref ? $value : throw InvalidArgument::expected($what, 'a Ref()', $value);
    }

    public function evaluateSet(mixed $expr, string $what): Set
    {
        $value = $this->evaluate($expr);

        if ($value instanceof Readable && !$value instanceof Set) {
            throw new InvalidArgument(sprintf('%s cannot be a Filter(), Reverse() or Distinct(): apply it to the result instead.', $what));
        }

        return $value instanceof Set ? $value : throw InvalidArgument::expected($what, 'a set (MatchIndex(), Documents(), Union()...)', $value);
    }

    public function evaluateReadable(mixed $expr, string $what): Readable
    {
        $value = $this->evaluate($expr);

        return $value instanceof Readable ? $value : throw InvalidArgument::expected($what, 'a set (MatchIndex(), Documents(), Union(), Filter()...)', $value);
    }

    /**
     * Reads entries of a set in order: with SQL for a Set or the events of a document, through the set they come from
     * for the others.
     *
     * @param list<mixed>|null $cursor values followed by a Ref, where to start from
     * @return list<IndexEntry>
     */
    public function scan(Readable $set, ?array $cursor, bool $inclusive, bool $backward, int $limit): array
    {
        return match (true) {
            $set instanceof Set => $this->driver->scan($set, $cursor, $inclusive, $backward, $limit, $this->at),
            $set instanceof FilteredSet, $set instanceof ReversedSet, $set instanceof DistinctSet, $set instanceof SchemaSet => $set->scan($this, $cursor, $inclusive, $backward, $limit),
            $set instanceof DocumentEvents => $this->driver->events($set->document, $cursor, $inclusive, $backward, $limit, $this->at),
            $set instanceof SetEvents => $set->scan($this, $cursor, $inclusive, $backward, $limit),
            default => throw InvalidArgument::expected('A set', 'a Set or a FilteredSet', $set),
        };
    }

    /**
     * All the entries of a set, in order, read in chunks.
     *
     * @return Generator<int, IndexEntry>
     */
    public function entries(Readable $set): Generator
    {
        $cursor = null;
        do {
            // Each chunk starts after the last entry of the previous one.
            $entries = $this->scan($set, $cursor, false, false, self::CHUNK);
            foreach ($entries as $entry) {
                yield $entry;
            }

            $cursor = $entries === [] ? null : $entries[count($entries) - 1]->cursor();
        } while (count($entries) === self::CHUNK);
    }

    /**
     * The items of a list, of a page, or of the entries of a set, read whole in chunks.
     *
     * @return iterable<mixed>
     */
    public function items(mixed $collection, string $what): iterable
    {
        return match (true) {
            $collection instanceof Readable => (function () use ($collection): Generator {
                foreach ($this->entries($collection) as $entry) {
                    yield $entry->item();
                }
            })(),
            $collection instanceof Page => $collection->data,
            is_array($collection) && array_is_list($collection) => $collection,
            default => throw InvalidArgument::expected($what, 'a list, a page or a set', $collection),
        };
    }

    /**
     * How many entries a set has: counted with SQL for a Set, by checking them one by one for a FilteredSet.
     */
    public function count(Readable $set): int
    {
        return match (true) {
            $set instanceof Set => $this->driver->count($set, $this->at),
            $set instanceof FilteredSet, $set instanceof ReversedSet, $set instanceof DistinctSet, $set instanceof SchemaSet => $set->count($this),
            $set instanceof DocumentEvents => iterator_count($this->entries($set)),
            $set instanceof SetEvents => count($set->events($this)),
            default => throw InvalidArgument::expected('A set', 'a Set or a FilteredSet', $set),
        };
    }

    /**
     * Resolves a ref, or the first entry of a set, like FaunaDB's Get(Match(...)) did: of a document, or of a
     * collection, an index or a function in the sets of the schema.
     */
    public function evaluateDocumentRef(mixed $expr, string $what): Ref|SchemaRef|null
    {
        $value = $this->evaluate($expr);

        if ($value instanceof Readable) {
            return $this->scan($value, null, true, false, 1)[0]->ref ?? null;
        }

        return $value instanceof Ref || $value instanceof SchemaRef ? $value : throw InvalidArgument::expected($what, 'a ref or a set', $value);
    }
}
