<?php declare(strict_types=1);

namespace Flora\Tests\Unit;

use DateTimeImmutable;
use Flora\Support\Json;
use Flora\Value\{CollectionRef, Date, Ref};
use PHPUnit\Framework\TestCase;

final class JsonTest extends TestCase
{

    public function testFieldsStartingWithAtAreEscaped(): void
    {
        $data = ['@type' => 'Person', 'value' => ['@ts' => 'oops'], 'list' => [['@ref' => 1]], '@@x' => 1];

        self::assertSame('{"@@type":"Person","value":{"@@ts":"oops"},"list":[{"@@ref":1}],"@@@x":1}', Json::encode($data));
        self::assertSame($data, Json::decode(Json::encode($data)));
    }

    public function testTagsAreWhatFloraWrote(): void
    {
        $data = [
            'instant' => new DateTimeImmutable('2026-01-01T00:00:00Z'),
            'ref' => new Ref(new CollectionRef('users'), '1'),
            'date' => new Date('2026-01-01'),
        ];

        self::assertSame(
            '{"instant":{"@ts":"2026-01-01T00:00:00.000000Z"},"ref":{"@ref":{"collection":"users","id":"1"}},"date":{"@date":"2026-01-01"}}',
            Json::encode($data),
        );
        self::assertEquals($data, Json::decode(Json::encode($data)));
    }

    public function testUnescapedTagsOfFlora010AreReadWhenValid(): void
    {
        self::assertEquals(['x' => new Date('2026-01-01')], Json::decode('{"x":{"@date":"2026-01-01"}}'));
        self::assertSame(['x' => ['@ts' => 'oops'], '@type' => 'Person'], Json::decode('{"x":{"@ts":"oops"},"@type":"Person"}'));
        self::assertSame(['@ref' => ['collection' => 'users', 'id' => '1', 'more' => true]], Json::decode('{"@ref":{"collection":"users","id":"1","more":true}}'));
    }

    public function testFieldsOfIndexPathsAreEscaped(): void
    {
        self::assertSame('@@type', Json::escape('@type'));
        self::assertSame('type', Json::escape('type'));
        self::assertSame(0, Json::escape(0));
    }
}
