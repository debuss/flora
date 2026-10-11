<?php declare(strict_types=1);

namespace Flora\Id;

interface IdGenerator
{

    /**
     * Returns a new positive 64-bit document id, as a string.
     */
    public function next(): string;
}
