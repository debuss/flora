<?php declare(strict_types=1);

namespace Flora\Tests\Unit;

use Flora\Driver\Entries;
use Flora\Schema\{IndexDefinition, IndexField};
use Flora\Value\CollectionRef;
use PHPUnit\Framework\TestCase;

final class EntriesTest extends TestCase
{

    public function testUnrolledFieldsGiveAnEntryPerItem(): void
    {
        $index = self::index([new IndexField(['data', 'tags'], unroll: true)], [new IndexField(['data', 'name'])]);

        self::assertSame([['php', 'Ada'], ['sql', 'Ada']], Entries::of($index, ['tags' => ['php', 'sql', 'php'], 'name' => 'Ada']));
        self::assertSame([['php', 'Ada']], Entries::of($index, ['tags' => 'php', 'name' => 'Ada']));
        self::assertSame([], Entries::of($index, ['tags' => [], 'name' => 'Ada']));
        self::assertSame([], Entries::of($index, ['name' => 'Ada']));
        self::assertSame([['php', null]], Entries::of($index, ['tags' => ['php']]));
    }

    public function testMissingUnrolledValuesGiveNoEntry(): void
    {
        $index = self::index([], [new IndexField(['data', 'tags'], unroll: true), new IndexField(['data', 'name'])]);

        self::assertSame([], Entries::of($index, ['name' => 'Ada']));
        self::assertSame([['php', null]], Entries::of($index, ['tags' => ['php']]));
    }

    public function testSeveralUnrolledFieldsGiveEveryCombination(): void
    {
        $index = self::index([new IndexField(['data', 'a'], unroll: true)], [new IndexField(['data', 'b'], unroll: true), new IndexField(['ref'])]);

        self::assertSame([[1, 'x', null], [1, 'y', null], [2, 'x', null], [2, 'y', null]], Entries::of($index, ['a' => [1, 2], 'b' => ['x', 'y']]));
    }

    public function testFieldsNotUnrolledKeepTheirLists(): void
    {
        $index = self::index([new IndexField(['data', 'tags'])], [new IndexField(['data', 'tags'], unroll: true)]);

        self::assertSame([[['a', 'b'], 'a'], [['a', 'b'], 'b']], Entries::of($index, ['tags' => ['a', 'b']]));
    }

    /**
     * @param list<IndexField> $terms
     * @param list<IndexField> $values
     */
    private static function index(array $terms, array $values): IndexDefinition
    {
        return new IndexDefinition('test', new CollectionRef('posts'), $terms, $values);
    }
}
