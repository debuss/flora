<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;
use Flora\Exception\InvalidArgument;
use Flora\Schema\IndexField;
use Flora\Support\Values;
use Flora\Value\{Bounds, DistinctSet, FilteredSet, Page, Readable, Ref as RefValue, ReversedSet, Set};

/**
 * Whether a value is an item of a list or a page, a value of an object, or an item of a set: the values of an entry
 * (the value when it has one), or its ref when it has none. A set is searched in its index, like Between() does.
 */
final readonly class ContainsValue implements Expr
{

    public function __construct(private mixed $value, private mixed $in) {}

    public function evaluate(Context $context): bool
    {
        $value = $context->evaluate($this->value);
        $in = $context->evaluate($this->in);

        if ($in instanceof Readable) {
            if (!self::searchable($in)) {
                return array_any([...$context->items($in, 'ContainsValue() in')], static fn (mixed $item): bool => Values::equal($item, $value));
            }

            $tuple = self::tuple($value, $in->values());

            return $tuple !== null && $context->scan(Between::bounded($in, new Bounds($tuple, $tuple)), null, true, false, 1) !== [];
        }

        $items = match (true) {
            $in instanceof Page => $in->data,
            is_array($in) => $in,
            default => throw InvalidArgument::expected('ContainsValue() in', 'a list, an object, a page or a set', $in),
        };

        return array_any($items, static fn (mixed $item): bool => Values::equal($item, $value));
    }

    /**
     * Whether a set can be searched in its index.
     */
    private static function searchable(Readable $set): bool
    {
        return match (true) {
            $set instanceof Set => true,
            $set instanceof FilteredSet, $set instanceof ReversedSet, $set instanceof DistinctSet => self::searchable($set->set),
            default => false,
        };
    }

    /**
     * The tuple of the entries a value is the item of, or null when it cannot be one.
     *
     * @param list<IndexField> $values
     * @return list<mixed>|null
     */
    private static function tuple(mixed $value, array $values): ?array
    {
        $tuple = match (count($values)) {
            0, 1 => [$value],
            default => is_array($value) && array_is_list($value) && count($value) === count($values) ? $value : null,
        };
        if ($tuple === null) {
            return null;
        }

        // Without values, the item is the ref.
        $fields = $values === [] ? [IndexField::ref()] : $values;
        $fits = array_all($fields, static fn (IndexField $field, int $i): bool => (!$field->isRef() || $tuple[$i] instanceof RefValue) && (!$field->isTs() || is_int($tuple[$i])));

        return $fits ? $tuple : null;
    }
}
