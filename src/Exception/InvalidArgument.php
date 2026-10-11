<?php declare(strict_types=1);

namespace Flora\Exception;

use InvalidArgumentException;

/**
 * An expression received an argument of the wrong type or shape.
 */
final class InvalidArgument extends InvalidArgumentException implements FloraException
{

    public static function expected(string $what, string $expected, mixed $actual): self
    {
        return new self(sprintf('%s must be %s, %s given.', $what, $expected, get_debug_type($actual)));
    }
}
