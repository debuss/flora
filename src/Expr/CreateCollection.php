<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;
use Flora\Exception\{AlreadyExists, InvalidArgument};
use Flora\Value\CollectionRef;

/**
 * Creates a collection: CreateCollection(['name' => 'users', 'history_days' => 30]).
 *
 * The history of its documents is kept for history_days days, or for ever when it is null (the default): see
 * Client::expireHistory().
 */
final readonly class CreateCollection implements Expr
{

    public function __construct(private mixed $params) {}

    /**
     * @return array{ref: CollectionRef, name: string, ts: int, history_days: int|null}
     */
    public function evaluate(Context $context): array
    {
        $context->assertWritable('CreateCollection()');

        $params = $context->evaluateArray($this->params, 'CreateCollection() params');

        $unknown = array_diff(array_keys($params), ['name', 'history_days']);
        if ($unknown !== []) {
            throw new InvalidArgument(sprintf('Unknown CreateCollection() parameter(s): %s.', implode(', ', $unknown)));
        }

        $ref = new CollectionRef(is_string($params['name'] ?? null)
            ? $params['name']
            : throw InvalidArgument::expected('CreateCollection() "name"', 'a string', $params['name'] ?? null));
        $historyDays = self::historyDays($params['history_days'] ?? null, 'CreateCollection()');

        if (!$context->driver->createCollection($ref->name, $context->ts, $historyDays)) {
            throw new AlreadyExists(sprintf('Collection "%s" already exists.', $ref->name));
        }

        return self::document(['name' => $ref->name, 'ts' => $context->ts, 'history_days' => $historyDays]);
    }

    /**
     * The document describing a collection, as Get() and Update() of it return it.
     *
     * @param array{name: string, ts: int, history_days: int|null} $collection
     * @return array{ref: CollectionRef, name: string, ts: int, history_days: int|null}
     *
     * @internal
     */
    public static function document(array $collection): array
    {
        return ['ref' => new CollectionRef($collection['name']), ...$collection];
    }

    /**
     * @internal
     */
    public static function historyDays(mixed $days, string $function): ?int
    {
        return $days === null || (is_int($days) && $days >= 0)
            ? $days
            : throw InvalidArgument::expected(sprintf('%s "history_days"', $function), 'a number of days, or null to keep history for ever', $days);
    }
}
