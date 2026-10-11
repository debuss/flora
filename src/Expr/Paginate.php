<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;
use Flora\Driver\IndexEntry;
use Flora\Exception\InvalidArgument;
use Flora\Value\{Page, Readable, Ref as RefValue, SchemaRef};

/**
 * Reads one page of a set.
 *
 * Options: "size" (default 64), and "after" or "before" set to a cursor of a previous page.
 */
final readonly class Paginate implements Expr
{

    public const int DEFAULT_SIZE = 64;

    public const int MAX_SIZE = 100_000;

    public function __construct(private mixed $set, private mixed $options = []) {}

    public function evaluate(Context $context): Page
    {
        $set = $context->evaluateReadable($this->set, 'Paginate() set');
        $options = $context->evaluateArray($this->options, 'Paginate() options');

        $unknown = array_diff(array_keys($options), ['size', 'after', 'before']);
        if ($unknown !== []) {
            throw new InvalidArgument(sprintf('Unknown Paginate() option(s): %s.', implode(', ', $unknown)));
        }

        $size = $options['size'] ?? self::DEFAULT_SIZE;
        if (!is_int($size) || $size < 1 || $size > self::MAX_SIZE) {
            throw new InvalidArgument(sprintf('Paginate() size must be an integer between 1 and %d.', self::MAX_SIZE));
        }

        $after = self::cursor($options['after'] ?? null, 'after', $set);
        $before = self::cursor($options['before'] ?? null, 'before', $set);
        if ($after !== null && $before !== null) {
            throw new InvalidArgument('Paginate() accepts "after" or "before", not both.');
        }

        // One more entry than needed tells whether there is another page.
        if ($before !== null) {
            $entries = $context->scan($set, $before, false, true, $size + 1);
            $page = array_reverse(array_slice($entries, 0, $size));

            return new Page(
                array_map(static fn (IndexEntry $entry): mixed => $entry->item(), $page),
                count($entries) > $size ? $page[0]->cursor() : null,
                $before,
            );
        }

        $entries = $context->scan($set, $after, true, false, $size + 1);
        $page = array_slice($entries, 0, $size);

        // Starting from a cursor means there may be entries before it: "before" points to the first one we return.
        $previous = null;
        if ($after !== null) {
            $previous = $page === [] ? $after : $page[0]->cursor();
        }

        return new Page(
            array_map(static fn (IndexEntry $entry): mixed => $entry->item(), $page),
            $previous,
            isset($entries[$size]) ? $entries[$size]->cursor() : null,
        );
    }

    /**
     * @return list<mixed>|null
     */
    private static function cursor(mixed $cursor, string $option, Readable $set): ?array
    {
        if ($cursor === null) {
            return null;
        }

        // A cursor holds the values of an entry followed by its ref.
        $size = count($set->values()) + 1;
        if (!is_array($cursor) || !array_is_list($cursor) || count($cursor) !== $size
            || !($cursor[$size - 1] instanceof RefValue || $cursor[$size - 1] instanceof SchemaRef)) {
            throw InvalidArgument::expected(
                sprintf('Paginate() "%s"', $option),
                sprintf('a cursor of this set (%d value(s) followed by a ref)', $size - 1),
                $cursor,
            );
        }

        return $cursor;
    }
}
