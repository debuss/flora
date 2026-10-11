<?php declare(strict_types=1);

namespace Flora\Expr;

use DateTimeInterface;
use Flora\Context;
use Flora\Value\{BoundLambda, CollectionRef, Date, FunctionRef as FunctionRefValue, IndexRef, JoinItem, Readable, Ref as RefValue, SchemaRef};

/**
 * Whether a value is of a type, as IsString(), IsNumber()... return. IsSetRef() is FQL's IsSet: isset is a word of
 * PHP. An empty array is both a list and an object, as JSON tells them apart and PHP does not.
 */
final readonly class TypeCheck implements Expr
{

    /**
     * @param 'Array'|'Boolean'|'Collection'|'Date'|'Doc'|'Double'|'Function'|'Index'|'Integer'|'Lambda'|'Null'|'Number'|'Object'|'Ref'|'SetRef'|'String'|'Timestamp' $type
     *        the name of the function, without "Is"
     */
    public function __construct(private string $type, private mixed $value) {}

    public function evaluate(Context $context): bool
    {
        $value = $context->evaluate($this->value);
        if ($value instanceof JoinItem) {
            throw JoinItem::misuse();
        }

        return match ($this->type) {
            'Array' => is_array($value) && array_is_list($value),
            'Boolean' => is_bool($value),
            'Collection' => $value instanceof CollectionRef,
            'Date' => $value instanceof Date,
            // A document, as Get() and the writes return it.
            'Doc' => is_array($value) && ($value['ref'] ?? null) instanceof RefValue && is_int($value['ts'] ?? null) && is_array($value['data'] ?? null),
            'Double' => is_float($value),
            'Function' => $value instanceof FunctionRefValue,
            'Index' => $value instanceof IndexRef,
            'Integer' => is_int($value),
            'Lambda' => $value instanceof BoundLambda,
            'Null' => $value === null,
            'Number' => is_int($value) || is_float($value),
            'Object' => is_array($value) && ($value === [] || !array_is_list($value)),
            'Ref' => $value instanceof RefValue || $value instanceof SchemaRef,
            'SetRef' => $value instanceof Readable,
            'String' => is_string($value),
            'Timestamp' => $value instanceof DateTimeInterface,
        };
    }
}
