<?php declare(strict_types=1);

namespace Flora\Exception;

use RuntimeException;

/**
 * A write would break the unique constraint of an index.
 */
final class NotUnique extends RuntimeException implements FloraException
{
}
