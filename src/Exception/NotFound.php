<?php declare(strict_types=1);

namespace Flora\Exception;

use RuntimeException;

/**
 * A document, collection, index, variable or value does not exist.
 */
final class NotFound extends RuntimeException implements FloraException
{
}
