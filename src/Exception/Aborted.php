<?php declare(strict_types=1);

namespace Flora\Exception;

use RuntimeException;

/**
 * A query called Abort(): its writes were rolled back.
 */
final class Aborted extends RuntimeException implements FloraException
{
}
