<?php declare(strict_types=1);

namespace Flora\Schema;

use Flora\Exception\InvalidArgument;

/**
 * A term or value of an index: a path into the document, either ['ref'], ['ts'] or ['data', ...].
 *
 * An unrolled field gives an entry per item when it holds a list, instead of one for the whole list.
 */
final readonly class IndexField
{

    /** @var non-empty-list<string|int> */
    public array $path;

    /**
     * @param list<mixed> $path
     */
    public function __construct(array $path, public bool $reverse = false, public bool $unroll = false)
    {
        $valid = match ($path[0] ?? null) {
            'ref', 'ts' => count($path) === 1,
            'data' => array_all($path, static fn (mixed $key): bool => is_string($key) || is_int($key)),
            default => false,
        };

        if (!$valid) {
            throw new InvalidArgument(sprintf(
                'Index field %s must be ["ref"], ["ts"] or a path starting with "data".',
                json_encode($path),
            ));
        }

        if ($unroll && $path[0] !== 'data') {
            throw new InvalidArgument(sprintf('Index field %s cannot be unrolled: only data holds lists.', json_encode($path)));
        }

        /** @var non-empty-list<string|int> $path checked above */
        $this->path = $path;
    }

    public static function ref(): self
    {
        return new self(['ref']);
    }

    /**
     * @param array{field?: mixed, reverse?: mixed, unroll?: mixed} $definition
     */
    public static function fromArray(array $definition): self
    {
        $field = $definition['field'] ?? null;
        if (is_string($field)) {
            $field = [$field];
        }

        if (!is_array($field) || !array_is_list($field)) {
            throw InvalidArgument::expected('Index field "field"', 'a path', $field);
        }

        return new self($field, (bool) ($definition['reverse'] ?? false), (bool) ($definition['unroll'] ?? false));
    }

    public function isRef(): bool
    {
        return $this->path === ['ref'];
    }

    public function isTs(): bool
    {
        return $this->path === ['ts'];
    }

    /**
     * The path inside the "data" column.
     *
     * @return list<string|int>
     */
    public function dataPath(): array
    {
        return array_slice($this->path, 1);
    }

    /**
     * @return array{field: list<string|int>, reverse?: true, unroll?: true}
     */
    public function toArray(): array
    {
        return ['field' => $this->path] + ($this->reverse ? ['reverse' => true] : []) + ($this->unroll ? ['unroll' => true] : []);
    }
}
