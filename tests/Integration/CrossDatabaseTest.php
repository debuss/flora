<?php declare(strict_types=1);

namespace Flora\Tests\Integration;

use PDOException;
use Flora\Client;
use PHPUnit\Framework\TestCase;

use function Flora\{Collection, ContainsValue, Events, Index, Lambda, Map, MatchIndex, Paginate, Ref, Select, Variable};

/**
 * The same data and queries on the four databases give the same results: the drivers differ only where the README
 * says so (Drivers), which is checked too. Needs all four databases, like the CI has them.
 */
final class CrossDatabaseTest extends TestCase
{

    /** @var array<string, Client> */
    private array $clients = [];

    protected function setUp(): void
    {
        foreach (Database::cases() as $database) {
            try {
                $pdo = $database->connect();
            } catch (PDOException $e) {
                self::markTestSkipped(sprintf('%s is not reachable (run `docker compose up -d`): %s', $database->name, $e->getMessage()));
            }

            $database->reset($pdo);
            $driver = $database->driver($pdo);
            $driver->install();
            $this->clients[$database->value] = new Client($driver);
        }

        foreach ($this->clients as $client) {
            AuditFixture::create($client);
            AuditFixture::write($client);
        }
    }

    public function testSetsHoldTheSameEntries(): void
    {
        foreach (AuditFixture::sets() as $label => $set) {
            $results = $this->query(Paginate($set, ['size' => 1000]));

            foreach ($results as $database => $page) {
                self::assertIsArray($page);
                self::assertIsArray($page['data']);
                $entries = $page['data'];
                usort($entries, static fn (mixed $a, mixed $b): int => serialize($a) <=> serialize($b));
                $results[$database] = $entries;
            }

            $this->assertAlike($results, "$label: entries");
        }
    }

    /**
     * Entries are in the same order everywhere, but for the values whose order the README says differs.
     */
    public function testSetsSortTheSame(): void
    {
        foreach (AuditFixture::sets() as $label => $set) {
            $values = AuditFixture::valueCounts()[$label];
            $orders = [];
            foreach ($this->query(Paginate($set, ['size' => 1000])) as $database => $page) {
                self::assertIsArray($page);
                self::assertIsArray($page['data']);
                $orders[$database] = array_values(array_filter(
                    $page['data'],
                    static fn (mixed $item): bool => AuditFixture::sortsAlike(match (true) {
                        $values === 0 => null,
                        $values === 1 => $item,
                        default => is_array($item) ? $item[0] : $item,
                    }),
                ));
            }

            $this->assertAlike($orders, "$label: order");
        }
    }

    public function testTermsMatchTheSame(): void
    {
        foreach (AuditFixture::terms() as $id => $term) {
            $this->assertAlike($this->query(Paginate(MatchIndex(Index('items_by_v'), $term), ['size' => 1000])), "match the value of $id");
            $this->assertAlike($this->query(ContainsValue($term, MatchIndex(Index('by_v_desc')))), "the value of $id in a set");
        }
    }

    public function testEventsAreTheSame(): void
    {
        // The times of the writes differ between databases: their actions, documents and data are compared.
        $withoutTime = Lambda('event', [Select('action', Variable('event')), Select('document', Variable('event')), Select('data', Variable('event'))]);

        foreach ([
            'a document' => Events(Ref(Collection('items'), '1')),
            'a deleted document' => Events(Ref(Collection('items'), '2')),
            'a match' => Events(MatchIndex(Index('items_by_v'), 1)),
            'a set of values' => Events(MatchIndex(Index('by_group_v'), 1)),
            'an unrolled index' => Events(MatchIndex(Index('by_tag'), 't1')),
        ] as $label => $events) {
            $this->assertAlike($this->query(Map(Paginate($events, ['size' => 1000]), $withoutTime)), "events of $label");
        }
    }

    /**
     * What the README says of the order of values on each database.
     */
    public function testTheDocumentedDifferences(): void
    {
        $orders = [];
        foreach ($this->query(Paginate(MatchIndex(Index('by_v')), ['size' => 1000])) as $database => $page) {
            self::assertIsArray($page);
            self::assertIsArray($page['data']);
            $orders[$database] = array_map(static fn (mixed $entry): string => is_array($entry) && is_string($entry[1]) ? substr($entry[1], strlen('@ref:items/')) : '', $page['data']);
        }

        $before = static fn (array $order, string $a, string $b): bool => array_search($a, $order, true) < array_search($b, $order, true);
        foreach ($orders as $database => $order) {
            // Lists: by size on PostgreSQL, where the empty list comes before null; by JSON text elsewhere.
            self::assertSame($database === 'postgres', $before($order, '24', '21'), "$database: [] before null");
            self::assertSame($database !== 'postgres', $before($order, '23', '24'), "$database: [1, 2] before []");

            // Integers beyond 2^53: exact on PostgreSQL and SQLite, as doubles, so tied and sorted by ref, on MySQL and MariaDB.
            self::assertSame(in_array($database, ['mysql', 'mariadb'], true), $before($order, '6', '7'), "$database: 2^53 + 1 before 2^53");
        }

        // Strings sharing their first 128 characters: tied on MySQL and MariaDB, sorted by the next value (the name of
        // document 31 comes first, in reverse), exact elsewhere.
        foreach ($this->query(Paginate(MatchIndex(Index('by_v_name')), ['size' => 1000])) as $database => $page) {
            self::assertIsArray($page);
            self::assertIsArray($page['data']);
            $longest = array_values(array_filter(array_column($page['data'], 0), static fn (mixed $value): bool => is_string($value) && strlen($value) > 128));
            self::assertSame(in_array($database, ['mysql', 'mariadb'], true), strlen((string) $longest[0]) === 201, "$database: long strings tied on 128 characters");
        }
    }

    /**
     * Runs a query on every database.
     *
     * @return array<string, mixed> the normalized result on each database
     */
    private function query(mixed $query): array
    {
        return array_map(static fn (Client $client): mixed => AuditFixture::normalize($client->query($query)), $this->clients);
    }

    /**
     * @param array<string, mixed> $results
     */
    private function assertAlike(array $results, string $what): void
    {
        foreach ($results as $database => $result) {
            self::assertSame($results['sqlite'], $result, "$what: $database differs from SQLite");
        }
    }
}
