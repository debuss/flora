<?php declare(strict_types=1);

namespace Flora\Schema;

use Flora\Exception\InvalidArgument;
use Flora\Support\{Json, Values};
use Flora\Value\{CollectionRef, Identifier};

/**
 * An index over a collection.
 *
 * Terms are the fields MatchIndex() searches on, values are the fields each entry returns and is sorted by. Entries
 * with equal values are sorted by ref (in the direction of the last value), so pagination is stable.
 */
final readonly class IndexDefinition
{

    /**
     * @param list<IndexField> $terms
     * @param list<IndexField> $values
     */
    public function __construct(
        public string $name,
        public CollectionRef $source,
        public array $terms = [],
        public array $values = [],
        public bool $unique = false,
    ) {
        if ($name !== '') {
            Identifier::assert($name, 'Index name');
        }
    }

    /**
     * The implicit index behind Documents(Collection('name')): every document, sorted by ref.
     */
    public static function documents(CollectionRef $source): self
    {
        return new self('', $source);
    }

    /**
     * Builds a definition from the parameters given to CreateIndex().
     *
     * @param array<array-key, mixed> $params
     */
    public static function fromParams(array $params): self
    {
        $unknown = array_diff(array_keys($params), ['name', 'source', 'terms', 'values', 'unique']);
        if ($unknown !== []) {
            throw new InvalidArgument(sprintf('Unknown index parameter(s): %s.', implode(', ', $unknown)));
        }

        $name = $params['name'] ?? null;
        if (!is_string($name)) {
            throw InvalidArgument::expected('Index "name"', 'a string', $name);
        }

        $source = $params['source'] ?? null;
        if (!$source instanceof CollectionRef) {
            throw InvalidArgument::expected('Index "source"', 'a Collection()', $source);
        }

        $definition = new self(
            $name,
            $source,
            self::fields($params['terms'] ?? [], 'terms'),
            self::fields($params['values'] ?? [], 'values'),
            (bool) ($params['unique'] ?? false),
        );

        if ($definition->unique && $definition->terms === [] && $definition->values === []) {
            throw new InvalidArgument('A unique index needs at least one term or value.');
        }

        return $definition;
    }

    /**
     * A definition as toArray() writes it: its source is the name of a collection.
     *
     * @param array<array-key, mixed> $array
     */
    public static function fromArray(array $array): self
    {
        $source = $array['source'] ?? null;

        return self::fromParams([...$array, 'source' => new CollectionRef(is_string($source) ? $source : throw InvalidArgument::expected('Index "source"', 'a name', $source))]);
    }

    /**
     * A definition as databases store it: the JSON of toArray().
     */
    public static function fromJson(string $json): self
    {
        return self::fromArray(Values::object(Json::decode($json), 'An index definition'));
    }

    /**
     * Whether the index unrolls a field: a document may then have several entries, which are stored in a table of their
     * own rather than computed from the document.
     */
    public function unrolls(): bool
    {
        return array_any([...$this->terms, ...$this->values], static fn (IndexField $field): bool => $field->unroll);
    }

    /**
     * Entries with equal values are sorted by ref in the direction of the last value: when all the values go the same
     * way, the whole index does, and pagination is a plain range.
     */
    public function refReversed(): bool
    {
        return self::refReversedAfter($this->values);
    }

    /**
     * Whether refs sort in reverse after these values: in the direction of the last one.
     *
     * @param list<IndexField> $values
     */
    public static function refReversedAfter(array $values): bool
    {
        return $values !== [] && $values[count($values) - 1]->reverse;
    }

    /**
     * @return array{name: string, source: string, terms: list<array<string, mixed>>, values: list<array<string, mixed>>, unique: bool}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'source' => $this->source->name,
            'terms' => array_map(static fn (IndexField $field): array => $field->toArray(), $this->terms),
            'values' => array_map(static fn (IndexField $field): array => $field->toArray(), $this->values),
            'unique' => $this->unique,
        ];
    }

    /**
     * @return list<IndexField>
     */
    private static function fields(mixed $fields, string $what): array
    {
        if (!is_array($fields) || !array_is_list($fields)) {
            throw InvalidArgument::expected(sprintf('Index "%s"', $what), 'a list of {field: path}', $fields);
        }

        return array_map(static function (mixed $field) use ($what): IndexField {
            if (!is_array($field)) {
                throw InvalidArgument::expected(sprintf('Each of the index "%s"', $what), 'a {field: path} array', $field);
            }

            return IndexField::fromArray($field);
        }, $fields);
    }
}
