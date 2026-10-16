<?php declare(strict_types=1);

namespace Flora\Tests\Unit;

use DateTimeImmutable;
use stdClass;
use Flora\Cursor;
use Flora\Exception\InvalidArgument;
use Flora\Value\{CollectionRef, Date, FunctionRef, IndexRef, Ref};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CursorTest extends TestCase
{

    public function testCursorsAreReadBackAsTheyWereWritten(): void
    {
        $cursor = [
            1, 1.0, -0.5, PHP_INT_MAX, 'é/+=', '', true, false, null, [], [1, [2]], ['@type' => 'x', '@@y' => 1, 'z' => ['@ts' => 'oops']],
            new DateTimeImmutable('2026-10-11T12:00:00.123456Z'), new Date('2026-10-11'),
            new CollectionRef('users'), new IndexRef('users_by_email'), new FunctionRef('greet'),
            new Ref(new CollectionRef('users'), '1068324738923651072'),
        ];

        $encoded = Cursor::encode($cursor);

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', (string) $encoded);
        self::assertEquals($cursor, Cursor::decode($encoded));
        self::assertSame([1, 1.0, -0.5], array_slice(Cursor::decode($encoded) ?? [], 0, 3));
    }

    public function testNoCursorIsNull(): void
    {
        self::assertNull(Cursor::encode(null));
        self::assertNull(Cursor::decode(null));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformed(): iterable
    {
        $encode = static fn (string $json): string => rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

        yield 'empty' => [''];
        yield 'not base64url' => ['a+b/c='];
        yield 'not JSON' => [$encode('[1,')];
        yield 'not a list' => [$encode('{"a":1}')];
        yield 'an empty list' => [$encode('[]')];
        yield 'an expression' => [$encode('[{"@expr":"Delete","ref":{"@ref":["users","1"]}}]')];
        yield 'an invalid id' => [$encode('[{"@ref":["users","abc"]}]')];
        yield 'a ref without its id' => [$encode('[{"@ref":["users"]}]')];
        yield 'an invalid name' => [$encode('[{"@collection":"Users!"}]')];
        yield 'an invalid instant' => [$encode('[{"@ts":"oops"}]')];
        yield 'a tag with another field' => [$encode('[{"@ts":"2026-10-11T12:00:00Z","x":1}]')];
        yield 'an unescaped field' => [$encode('[{"a":1,"@b":2}]')];
    }

    #[DataProvider('malformed')]
    public function testOnlyCursorsAreRead(string $cursor): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('This is not a cursor of Flora');

        Cursor::decode($cursor);
    }

    public function testCursorsHoldTheValuesOfIndexes(): void
    {
        $this->expectException(InvalidArgument::class);

        Cursor::encode([new stdClass()]);
    }
}
