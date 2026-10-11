<?php declare(strict_types=1);

namespace Flora\Driver;

/**
 * The parameters of a statement being built, each with its own placeholder, and the aliases of its subqueries.
 *
 * @internal
 */
final class Parameters
{

    /** @var array<string, mixed> */
    private array $values = [];

    private int $aliases = 0;

    /**
     * @param int|null $at the time the statement reads the database at, inside At(), or until, for versions
     * @param bool $versions whether the statement reads every version of the documents, from their history
     */
    public function __construct(public readonly ?int $at = null, public readonly bool $versions = false) {}

    /**
     * Whether the statement reads the history of collections rather than their tables: indexes are not there.
     */
    public function past(): bool
    {
        return $this->at !== null || $this->versions;
    }

    /**
     * Adds a value and returns its placeholder.
     */
    public function add(mixed $value): string
    {
        $name = 'p' . count($this->values);
        $this->values[$name] = $value;

        return ':' . $name;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->values;
    }

    /**
     * A new alias for a table of the statement.
     */
    public function alias(): string
    {
        return 'flora_t' . $this->aliases++;
    }
}
