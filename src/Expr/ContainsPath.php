<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;
use Flora\Exception\InvalidArgument;

/**
 * Whether there is a value at a path, as Select() reads it: ContainsPath(['data', 'email'], Get(...)). ContainsField()
 * checks one field of an object.
 */
final readonly class ContainsPath implements Expr
{

    public function __construct(private mixed $path, private mixed $in, private bool $field = false) {}

    public function evaluate(Context $context): bool
    {
        $path = $context->evaluate($this->path);
        $value = $context->evaluate($this->in);

        if ($this->field) {
            if (!is_string($path)) {
                throw InvalidArgument::expected('ContainsField() field', 'a string', $path);
            }

            return Select::has($value, $path);
        }

        foreach (is_array($path) ? $path : [$path] as $key) {
            if (!is_string($key) && !is_int($key)) {
                throw InvalidArgument::expected('ContainsPath() path', 'a list of strings and integers', $key);
            }

            if (!Select::has($value, $key)) {
                return false;
            }

            $value = Select::at($value, $key);
        }

        return true;
    }
}
