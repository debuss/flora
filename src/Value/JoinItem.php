<?php declare(strict_types=1);

namespace Flora\Value;

use JsonSerializable;
use Flora\Exception\InvalidArgument;

/**
 * What the lambda of a Join() receives: one item of an entry of the source set, its ref or one of its values. It only
 * stands for them, since the lambda is evaluated once for all the entries, so it can only be a term of MatchIndex().
 */
final readonly class JoinItem implements JsonSerializable
{

    /**
     * @param object $join identifies the Join() the item belongs to, by identity
     * @param int $position which item of the entry: the ref or the first value is 0
     */
    public function __construct(public object $join, public int $position) {}

    public static function misuse(): InvalidArgument
    {
        return new InvalidArgument('The parameters of a Join() lambda stand for all the entries of the source set: they can only be terms of MatchIndex().');
    }

    public function jsonSerialize(): never
    {
        throw self::misuse();
    }
}
