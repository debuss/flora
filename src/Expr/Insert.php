<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;
use Flora\Exception\InvalidArgument;
use Flora\Value\Ref as RefValue;

/**
 * Adds an event to the history of a document, at a time, replacing the event already there: an action (create, update
 * or delete) and the whole document after it. The document becomes what its last event says, so inserting an event
 * after the others changes it.
 *
 *     Insert($ref, $ts, 'update', ['data' => ['name' => 'Ada Lovelace']])
 */
final readonly class Insert implements Expr
{

    public const array ACTIONS = ['create', 'update', 'delete'];

    public function __construct(private mixed $ref, private mixed $ts, private mixed $action, private mixed $params = []) {}

    /**
     * @return array{ts: int, action: string, document: RefValue, data: array<string, mixed>}
     */
    public function evaluate(Context $context): array
    {
        $context->assertWritable('Insert()');

        $ref = $context->evaluateRef($this->ref, 'Insert() ref');
        $ts = self::time($context->evaluate($this->ts), 'Insert()');
        $action = self::action($context->evaluate($this->action), 'Insert()');
        $data = DocumentParams::data($context->evaluateArray($this->params, 'Insert() params'), 'Insert');

        $context->driver->insertEvent($ref, $ts, $action, $data);

        return ['ts' => $ts, 'action' => $action, 'document' => $ref, 'data' => $data];
    }

    /**
     * @internal
     */
    public static function time(mixed $ts, string $function): int
    {
        return is_int($ts) && $ts >= 0 ? $ts : throw InvalidArgument::expected(sprintf('%s time', $function), 'a positive integer, in microseconds', $ts);
    }

    /**
     * @return 'create'|'update'|'delete'
     *
     * @internal
     */
    public static function action(mixed $action, string $function): string
    {
        return match ($action) {
            'create', 'update', 'delete' => $action,
            default => throw InvalidArgument::expected(sprintf('%s action', $function), 'create, update or delete', $action),
        };
    }
}
