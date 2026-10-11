<?php declare(strict_types=1);

namespace Flora\Support;

use Flora\Exception\InvalidArgument;

/**
 * Regular expressions of queries: PCRE patterns written without delimiters, matched on code points.
 *
 * @internal
 */
final class Regex
{

    /**
     * The pattern ready for preg_* functions.
     */
    public static function compile(string $pattern, string $function): string
    {
        // A delimiter no pattern holds, so that patterns need no escaping.
        $compiled = "\x01" . $pattern . "\x01u";
        if (@preg_match($compiled, '') === false) {
            throw new InvalidArgument(sprintf('%s pattern "%s" is not a valid regular expression.', $function, $pattern));
        }

        return $compiled;
    }

    /**
     * The code point offset of a byte offset in a string.
     */
    public static function codePoints(string $value, int $bytes): int
    {
        return mb_strlen(substr($value, 0, $bytes));
    }
}
