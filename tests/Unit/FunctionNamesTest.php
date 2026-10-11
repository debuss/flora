<?php declare(strict_types=1);

namespace Flora\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Function names are case-insensitive in PHP: a function of Flora named like a function of PHP would take its place in
 * a file importing it, and silently return an expression instead.
 */
final class FunctionNamesTest extends TestCase
{

    /** join() is an alias of implode(), rarely used: Join keeps its FQL name. */
    private const array ALLOWED = ['join'];

    public function testNoFunctionTakesThePlaceOfAFunctionOfPhp(): void
    {
        $internal = array_map(strtolower(...), get_defined_functions()['internal']);
        $flora = array_filter(get_defined_functions()['user'], static fn (string $name): bool => str_starts_with($name, 'flora\\'));

        $collisions = array_values(array_filter(
            array_map(static fn (string $name): string => substr($name, strlen('flora\\')), $flora),
            static fn (string $name): bool => in_array($name, $internal, true) && !in_array($name, self::ALLOWED, true),
        ));

        self::assertSame([], $collisions);
    }
}
