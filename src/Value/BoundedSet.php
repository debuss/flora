<?php declare(strict_types=1);

namespace Flora\Value;

use Flora\Exception\InvalidArgument;
use Flora\Schema\IndexField;

/**
 * The entries of a set between two bounds, both included, as returned by Between(). Each branch of the set is
 * bounded, so that drivers read the bounds as conditions on the index, like the cursor of a page.
 */
final readonly class BoundedSet implements Set
{

    /** @var non-empty-list<Branch> */
    private array $branches;

    public function __construct(public Set $set, public Bounds $bounds)
    {
        foreach (['from' => $bounds->from, 'to' => $bounds->to] as $name => $bound) {
            self::assertBound($name, $bound, $set->values());
        }

        $this->branches = array_map(static fn (Branch $branch): Branch => $branch->between($bounds), $set->branches());
    }

    public function branches(): array
    {
        return $this->branches;
    }

    public function collections(): array
    {
        return $this->set->collections();
    }

    public function values(): array
    {
        return $this->set->values();
    }

    /**
     * A bound holds, in order, values of the entries, of the kind of their field, then a ref.
     *
     * @param list<mixed> $bound
     * @param list<IndexField> $values
     */
    private static function assertBound(string $name, array $bound, array $values): void
    {
        if (count($bound) > count($values) + 1) {
            throw new InvalidArgument(sprintf(
                'The entries of the set hold %d value(s) and a ref: Between() %s holds %d.',
                count($values),
                $name,
                count($bound),
            ));
        }

        foreach ($bound as $position => $value) {
            $field = $values[$position] ?? IndexField::ref();
            $what = sprintf('Between() %s, at %d', $name, $position);

            match (true) {
                $value instanceof JoinItem => throw JoinItem::misuse(),
                $field->isRef() && !$value instanceof Ref => throw InvalidArgument::expected($what, 'a ref', $value),
                $field->isTs() && !is_int($value) => throw InvalidArgument::expected($what, 'a time in microseconds', $value),
                default => null,
            };
        }
    }
}
