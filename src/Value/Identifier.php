<?php declare(strict_types=1);

namespace Flora\Value;

use Flora\Exception\InvalidArgument;

/**
 * Collection and index names end up as SQL identifiers, so they are kept to a portable subset.
 *
 * @internal
 */
final class Identifier
{

    public const int MAX_LENGTH = 48;

    public static function assert(string $name, string $what): void
    {
        if (!preg_match('/^[a-z_][a-z0-9_]{0,' . (self::MAX_LENGTH - 1) . '}$/', $name)) {
            throw new InvalidArgument(sprintf(
                '%s "%s" must start with a lowercase letter or an underscore, contain only lowercase letters, digits and underscores, and be at most %d characters long.',
                $what,
                $name,
                self::MAX_LENGTH,
            ));
        }

        if (str_starts_with($name, 'flora_')) {
            throw new InvalidArgument(sprintf('%s "%s" is reserved: the "flora_" prefix is used internally.', $what, $name));
        }
    }
}
