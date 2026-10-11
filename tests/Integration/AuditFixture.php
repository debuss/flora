<?php declare(strict_types=1);

namespace Flora\Tests\Integration;

use DateTimeImmutable;
use DateTimeInterface;
use Flora\Client;
use Flora\Value\{CollectionRef, Date, IndexRef, Page, Ref as RefValue};

use function Flora\{
    Between,
    Collection,
    Create,
    CreateCollection,
    CreateIndex,
    Delete,
    Difference,
    Distinct,
    Documents,
    Equals,
    Filter,
    Index,
    Intersection,
    Join,
    Lambda,
    MatchIndex,
    Ref,
    Reverse,
    Singleton,
    Timestamp,
    ToDate,
    Union,
    Update,
    Variable,
};

/**
 * Data meant to catch what a database does differently: every kind of JSON value as the value of an index (numbers
 * near the limits of integers and doubles, strings in several scripts, booleans, null, missing fields, lists, objects,
 * refs, instants, dates), and the sets of all the kinds Flora reads.
 */
final class AuditFixture
{

    /**
     * The value of "v" in each document, by id; document 22 has none.
     *
     * @return array<int, mixed>
     */
    public static function values(): array
    {
        return [
            1 => 1, 2 => 1.0, 3 => 1.5, 4 => -2, 5 => 0, 6 => 9_007_199_254_740_993, 7 => 9_007_199_254_740_992, 8 => 1e300,
            9 => '1', 10 => '10', 11 => '9', 12 => '', 13 => 'a', 14 => 'B', 15 => 'É', 16 => 'é', 17 => 'zoë', 18 => '日本',
            19 => true, 20 => false, 21 => null, 23 => [1, 2], 24 => [], 25 => ['x' => 1],
            27 => new RefValue(new CollectionRef('items'), '1'), 28 => new DateTimeImmutable('2026-10-11T12:00:00Z'),
            29 => new Date('2026-10-11'), 30 => str_repeat('x', 200), 31 => str_repeat('x', 200) . 'y', 32 => '🙂',
            33 => -0.5, 34 => 'a', 35 => 1, 36 => ['y' => 2, 'x' => 1], 37 => [[1], 2], 38 => -9_007_199_254_740_993,
            39 => 0.1 + 0.2, 40 => -1e300, 41 => PHP_INT_MAX, 42 => PHP_INT_MIN,
        ];
    }

    public static function create(Client $client): void
    {
        $client->query(CreateCollection(['name' => 'items']));
        $client->query(CreateCollection(['name' => 'others']));

        foreach ([
            'by_v' => ['values' => [['field' => ['data', 'v']], ['field' => ['ref']]]],
            'by_v_desc' => ['values' => [['field' => ['data', 'v'], 'reverse' => true]]],
            'by_v_name' => ['values' => [['field' => ['data', 'v']], ['field' => ['data', 'name'], 'reverse' => true]]],
            'items_by_v' => ['terms' => [['field' => ['data', 'v']]]],
            'by_group_v' => ['terms' => [['field' => ['data', 'group']]], 'values' => [['field' => ['data', 'v']]]],
            'by_tag' => ['terms' => [['field' => ['data', 'tags'], 'unroll' => true]]],
            'tags' => ['values' => [['field' => ['data', 'tags'], 'unroll' => true], ['field' => ['ref']]]],
        ] as $name => $params) {
            $client->query(CreateIndex(['name' => $name, 'source' => Collection('items'), ...$params]));
        }
        $client->query(CreateIndex([
            'name' => 'others_by_item',
            'source' => Collection('others'),
            'terms' => [['field' => ['data', 'item']]],
            'values' => [['field' => ['data', 'n']]],
        ]));

        $creates = [];
        foreach ([...array_keys(self::values()), 22] as $id) {
            $data = [
                'group' => $id % 3,
                'name' => 'n' . ($id % 5),
                'tags' => match ($id % 4) {
                    0 => ['t' . ($id % 3), 't1'],
                    1 => 't' . ($id % 2),
                    2 => [],
                    default => [['t1'], 't2', 't2'],
                },
            ];
            if ($id !== 22) {
                $data['v'] = self::values()[$id];
            }
            $creates[] = Create(Ref(Collection('items'), (string) $id), ['data' => $data]);
        }
        $client->query($creates);
        $client->query(array_map(
            static fn (int $n): mixed => Create(Ref(Collection('others'), (string) $n), ['data' => ['item' => Ref(Collection('items'), (string) ($n % 7 + 1)), 'n' => $n]]),
            range(1, 20),
        ));
    }

    /**
     * Changes documents after their creation, for their events.
     */
    public static function write(Client $client): void
    {
        $client->query(Update(Ref(Collection('items'), '1'), ['data' => ['v' => 'changed', 'tags' => ['t9']]]));
        $client->query(Delete(Ref(Collection('items'), '2')));
        $client->query(Update(Ref(Collection('items'), '13'), ['data' => ['v' => 2]]));
    }

    /**
     * How many values the entries of each set of sets() hold: none for refs.
     *
     * @return array<string, int>
     */
    public static function valueCounts(): array
    {
        return [
            'by_v' => 2, 'by_v_desc' => 1, 'by_v_name' => 2, 'documents' => 0, 'group 1' => 1, 'tags' => 2, 'by_tag t1' => 0,
            'union' => 0, 'intersection' => 0, 'difference' => 0, 'distinct by_v_desc' => 1, 'distinct tags' => 2, 'filter' => 2,
            'join' => 1, 'join lambda' => 1, 'between numbers' => 2, 'between strings' => 2, 'between mixed' => 2,
            'between desc' => 1, 'between group' => 1, 'singletons' => 0, 'reverse union' => 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function sets(): array
    {
        $tag = static fn (string $tag): mixed => MatchIndex(Index('by_tag'), $tag);

        return [
            'by_v' => MatchIndex(Index('by_v')),
            'by_v_desc' => MatchIndex(Index('by_v_desc')),
            'by_v_name' => MatchIndex(Index('by_v_name')),
            'documents' => Documents(Collection('items')),
            'group 1' => MatchIndex(Index('by_group_v'), 1),
            'tags' => MatchIndex(Index('tags')),
            'by_tag t1' => $tag('t1'),
            'union' => Union($tag('t1'), $tag('t2'), MatchIndex(Index('items_by_v'), 'a')),
            'intersection' => Intersection($tag('t1'), $tag('t0')),
            'difference' => Difference(Documents(Collection('items')), $tag('t1')),
            'distinct by_v_desc' => Distinct(MatchIndex(Index('by_v_desc'))),
            'distinct tags' => Distinct(MatchIndex(Index('tags'))),
            'filter' => Filter(MatchIndex(Index('by_v')), Lambda(['v', 'ref'], Equals(Variable('v'), 1))),
            'join' => Join(Documents(Collection('items')), Index('others_by_item')),
            'join lambda' => Join($tag('t1'), Lambda('ref', MatchIndex(Index('others_by_item'), Variable('ref')))),
            'between numbers' => Between(MatchIndex(Index('by_v')), 0, 2),
            'between strings' => Between(MatchIndex(Index('by_v')), 'a', 'zz'),
            'between mixed' => Between(MatchIndex(Index('by_v')), -1, 'b'),
            'between desc' => Between(MatchIndex(Index('by_v_desc')), 2, 0),
            'between group' => Between(MatchIndex(Index('by_group_v'), 2), [], 1.5),
            'singletons' => Union(Singleton(Ref(Collection('items'), '3')), Singleton(Ref(Collection('items'), '22'))),
            'reverse union' => Reverse(Union($tag('t1'), $tag('t2'))),
        ];
    }

    /**
     * The terms of documents, one per kind of value, to match.
     *
     * @return array<int, mixed>
     */
    public static function terms(): array
    {
        return [...self::values(), 'missing' => 'nothing', 'instant' => Timestamp('2026-10-11T12:00:00Z'), 'date' => ToDate('2026-10-11')];
    }

    /**
     * A value as it compares between databases: refs, instants and dates as text, doubles marked, the fields of objects
     * sorted (databases may reorder them).
     */
    public static function normalize(mixed $value): mixed
    {
        if (is_array($value) && !array_is_list($value)) {
            ksort($value);
        }

        return match (true) {
            $value instanceof RefValue => '@ref:' . $value->collection->name . '/' . $value->id,
            $value instanceof CollectionRef => '@collection:' . $value->name,
            $value instanceof IndexRef => '@index:' . $value->name,
            $value instanceof DateTimeInterface => '@ts:' . $value->format('Y-m-d\TH:i:s.uP'),
            $value instanceof Date => '@date:' . $value->value,
            $value instanceof Page => ['data' => self::normalize($value->data), 'before' => self::normalize($value->before), 'after' => self::normalize($value->after)],
            is_float($value) => 'double:' . var_export($value, true),
            is_array($value) => array_map(self::normalize(...), $value),
            default => $value,
        };
    }

    /**
     * Whether the place of a value in an index is the same on every database (README, Drivers): lists and objects sort
     * by their JSON text, but by size on PostgreSQL; MySQL and MariaDB sort integers beyond 2^53 as doubles, and
     * strings on their first 128 characters.
     */
    public static function sortsAlike(mixed $value): bool
    {
        return !is_array($value) && !(is_int($value) && abs($value) > 2 ** 53) && !(is_string($value) && mb_strlen($value) > 128);
    }
}
