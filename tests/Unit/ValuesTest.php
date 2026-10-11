<?php declare(strict_types=1);

namespace Flora\Tests\Unit;

use Flora\Exception\InvalidArgument;
use Flora\Schema\IndexDefinition;
use Flora\Support\Json;
use Flora\Value\{CollectionRef, Ref};
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ValuesTest extends TestCase
{

    public function testRefsRoundTripThroughJson(): void
    {
        $data = ['author' => new Ref(new CollectionRef('users'), '123'), 'tags' => ['x']];

        self::assertEquals($data, Json::decode(Json::encode($data)));
        self::assertSame('{"author":{"@ref":{"collection":"users","id":"123"}},"tags":["x"]}', Json::encode($data));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidIds(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-1'];
        yield 'leading zero' => ['0123'];
        yield 'not a number' => ['abc'];
        yield 'beyond 64 bits' => ['9223372036854775808'];
    }

    #[DataProvider('invalidIds')]
    public function testRejectsInvalidIds(string $id): void
    {
        $this->expectException(InvalidArgument::class);

        new Ref(new CollectionRef('users'), $id);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'uppercase' => ['Users'];
        yield 'quote' => ['users"; DROP TABLE x; --'];
        yield 'reserved prefix' => ['flora_users'];
        yield 'too long' => [str_repeat('a', 49)];
    }

    #[DataProvider('invalidNames')]
    public function testRejectsInvalidNames(string $name): void
    {
        $this->expectException(InvalidArgument::class);

        new CollectionRef($name);
    }

    public function testIndexDefinitionRoundTrips(): void
    {
        $index = IndexDefinition::fromParams([
            'name' => 'users_by_email',
            'source' => new CollectionRef('users'),
            'terms' => [['field' => ['data', 'email']]],
            'values' => [['field' => ['data', 'age'], 'reverse' => true], ['field' => ['ref']]],
            'unique' => true,
        ]);

        self::assertEquals($index, IndexDefinition::fromArray($index->toArray()));
    }

    public function testIndexFieldsMustStartWithData(): void
    {
        $this->expectException(InvalidArgument::class);

        IndexDefinition::fromParams(['name' => 'x', 'source' => new CollectionRef('users'), 'terms' => [['field' => ['email']]]]);
    }
}
