<?php declare(strict_types=1);

namespace Flora\Exception;

use RuntimeException;

/**
 * A query ran into other transactions running at the same time: it waited too long for a lock, or the database broke a
 * deadlock by stopping it. Its writes were rolled back, and it can be run again.
 */
final class Contended extends RuntimeException implements FloraException
{
}
