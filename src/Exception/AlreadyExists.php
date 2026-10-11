<?php declare(strict_types=1);

namespace Flora\Exception;

use RuntimeException;

/**
 * A document, collection or index with the same identity already exists.
 */
final class AlreadyExists extends RuntimeException implements FloraException
{
}
