<?php declare(strict_types=1);

namespace Flora\Tests\Unit;

use DateTimeImmutable;
use Flora\Exception\InvalidArgument;
use Flora\Support\Serializer;
use Flora\Value\{CollectionRef, Date, Page};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Flora\{
    Add,
    Between,
    Collection,
    Format,
    FunctionRef,
    Index,
    Intersection,
    Lambda,
    Let,
    MatchIndex,
    Query,
    Ref,
    Select,
    Timestamp,
    Union,
    Variable,
};

final class SerializerTest extends TestCase
{

    public function testExpressionsAreReadBackAsWritten(): void
    {
        $expr = Lambda(['a', 'b'], Let(['x' => Add(Variable('a'), 1.0, 2)], [
            Union(MatchIndex(Index('i'), Variable('x')), Intersection(MatchIndex(Index('j')), MatchIndex(Index('k'), null))),
            Between(MatchIndex(Index('j')), [], [Timestamp('2026-01-01T00:00:00Z')]),
            Format('%s', ['@tagged' => Ref(Collection('users'), '12'), 'list' => [true, null, 'é']]),
            Select(['data', 0], Variable('b'), 'default'),
            [new CollectionRef('users'), new DateTimeImmutable('2026-10-11T12:00:00Z'), new Date('2026-10-11'), FunctionRef('f'), Query(Lambda('y', Variable('y')))],
        ]));

        $json = Serializer::serialize($expr);

        self::assertEquals($expr, Serializer::unserialize($json));
        self::assertSame($json, Serializer::serialize(Serializer::unserialize($json)));
    }

    public function testValuesMadeWhileAQueryRunsCannotBeStored(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('cannot hold Flora\Value\Page');

        Serializer::serialize(Lambda('x', new Page([])));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function corruptJson(): iterable
    {
        yield 'not JSON' => ['{"@expr": '];
        yield 'unknown expression' => ['{"@expr": "Nothing"}'];
        yield 'a class elsewhere' => ['{"@expr": "\\\\Flora\\\\Context"}'];
        yield 'an abstract one' => ['{"@expr": "SetOperation", "operands": []}'];
        yield 'missing properties' => ['{"@expr": "Lambda", "params": "x"}'];
        yield 'other properties' => ['{"@expr": "Variable", "name": "x", "evil": 1}'];
        yield 'wrong types' => ['{"@expr": "Variable", "name": ["x"]}'];
    }

    #[DataProvider('corruptJson')]
    public function testOnlyExpressionsOfFloraAreRead(string $json): void
    {
        $this->expectException(InvalidArgument::class);

        Serializer::unserialize($json);
    }
}
