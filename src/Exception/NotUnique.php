<?php declare(strict_types=1);

namespace Flora\Exception;

use RuntimeException;
use Throwable;

/**
 * A write would break the unique constraint of an index.
 */
final class NotUnique extends RuntimeException implements FloraException
{

    /**
     * @param string|null $index the name of the index, null when the database did not tell it
     */
    public function __construct(public readonly ?string $index = null, ?Throwable $previous = null)
    {
        parent::__construct(
            $index === null ? 'The document breaks the unique constraint of an index.' : sprintf('The document breaks the unique index "%s".', $index),
            0,
            $previous,
        );
    }
}
