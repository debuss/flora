<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;
use Flora\Exception\{AlreadyExists, InvalidArgument};
use Flora\Support\Serializer;
use Flora\Value\{FunctionRef as FunctionRefValue, Identifier, Query as QueryValue};

/**
 * Stores a function, called with Call():
 *
 *     CreateFunction(['name' => 'adults', 'body' => Query(Lambda('age', Between(MatchIndex(Index('ages')), Variable('age'), [])))])
 *
 * The body is a Query() of a Lambda(), or a Lambda() itself; it sees none of the variables of the query creating it.
 * Values made while a query runs, such as sets or pages, cannot be part of it: it builds them when called.
 */
final readonly class CreateFunction implements Expr
{

    public function __construct(private mixed $params) {}

    /**
     * @return array{ref: FunctionRefValue, name: string, body: QueryValue, ts: int}
     */
    public function evaluate(Context $context): array
    {
        $context->assertWritable('CreateFunction()');

        $params = $context->evaluateArray(self::query($this->params), 'CreateFunction() params');
        $unknown = array_diff(array_keys($params), ['name', 'body']);
        if ($unknown !== []) {
            throw new InvalidArgument(sprintf('Unknown CreateFunction() parameter(s): %s.', implode(', ', $unknown)));
        }

        $name = $params['name'] ?? null;
        if (!is_string($name)) {
            throw InvalidArgument::expected('Function "name"', 'a string', $name);
        }
        Identifier::assert($name, 'Function name');
        $body = self::body($params['body'] ?? null, 'CreateFunction()');

        if (!$context->driver->createFunction($name, Serializer::serialize($body->lambda), $context->ts)) {
            throw new AlreadyExists(sprintf('Function "%s" already exists.', $name));
        }

        return self::document($name, $body, $context->ts);
    }

    /**
     * The parameters with a Lambda() body kept as written: evaluating it would bind it to the query.
     *
     * @internal
     */
    public static function query(mixed $params): mixed
    {
        return is_array($params) && ($params['body'] ?? null) instanceof Lambda ? ['body' => new Query($params['body'])] + $params : $params;
    }

    /**
     * @internal
     */
    public static function body(mixed $body, string $function): QueryValue
    {
        return $body instanceof QueryValue ? $body : throw InvalidArgument::expected(sprintf('%s "body"', $function), 'a Query() of a Lambda(), or a Lambda()', $body);
    }

    /**
     * The document describing a function, as CreateFunction(), Get(), Update() and Delete() return it.
     *
     * @return array{ref: FunctionRefValue, name: string, body: QueryValue, ts: int}
     *
     * @internal
     */
    public static function document(string $name, QueryValue $body, int $ts): array
    {
        return ['ref' => new FunctionRefValue($name), 'name' => $name, 'body' => $body, 'ts' => $ts];
    }

    /**
     * The document of a stored function, as the driver gives it.
     *
     * @param array{name: string, body: string, ts: int} $function
     * @return array{ref: FunctionRefValue, name: string, body: QueryValue, ts: int}
     *
     * @internal
     */
    public static function stored(array $function): array
    {
        $lambda = Serializer::unserialize($function['body']);

        return self::document($function['name'], new QueryValue($lambda instanceof Lambda ? $lambda : throw new InvalidArgument(sprintf('The body of function "%s" is not a Lambda().', $function['name']))), $function['ts']);
    }
}
