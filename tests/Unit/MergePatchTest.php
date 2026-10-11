<?php declare(strict_types=1);

namespace Flora\Tests\Unit;

use Flora\Support\MergePatch;
use PHPUnit\Framework\TestCase;

final class MergePatchTest extends TestCase
{

    public function testMergesObjectsRecursively(): void
    {
        $target = ['name' => 'Ada', 'address' => ['city' => 'London', 'zip' => 'N1'], 'tags' => ['a', 'b']];
        $patch = ['address' => ['city' => 'Paris', 'zip' => null], 'tags' => ['c'], 'age' => 36];

        self::assertSame(
            ['name' => 'Ada', 'address' => ['city' => 'Paris'], 'tags' => ['c'], 'age' => 36],
            MergePatch::apply($target, $patch),
        );
    }

    public function testReplacesScalarsWithObjectsWithoutTheirNulls(): void
    {
        self::assertSame(['a' => ['b' => 1]], MergePatch::apply(['a' => 'x'], ['a' => ['b' => 1, 'c' => null]]));
    }
}
