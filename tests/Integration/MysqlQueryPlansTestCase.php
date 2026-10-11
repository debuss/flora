<?php declare(strict_types=1);

namespace Flora\Tests\Integration;

use PDO;

use function Flora\{Between, Collection, CreateCollection, CreateIndex, Index, MatchIndex, Paginate, Reverse, Union};

/**
 * Checks, with the handler counters of the session, that the SQL the MySQL and MariaDB drivers generate reads the
 * indexed virtual columns rather than the table.
 */
abstract class MysqlQueryPlansTestCase extends IntegrationTestCase
{

    private const int SIZE = 5;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client->query(CreateCollection(['name' => 'users']));
    }

    public function testPagesAreReadFromTheIndex(): void
    {
        $this->client->query(CreateIndex([
            'name' => 'users_by_role',
            'source' => Collection('users'),
            'terms' => [['field' => ['data', 'role']]],
            'values' => [['field' => ['data', 'age'], 'reverse' => true]],
        ]));
        $this->insertUsers();

        $this->assertPagesReadOnlyTheirEntries(MatchIndex(Index('users_by_role'), 'r42'));
    }

    public function testPagesAreReadFromTheIndexWithMixedDirections(): void
    {
        $this->client->query(CreateIndex([
            'name' => 'users_by_age_and_name',
            'source' => Collection('users'),
            'values' => [['field' => ['data', 'age']], ['field' => ['data', 'name'], 'reverse' => true]],
        ]));
        $this->insertUsers();

        $this->assertPagesReadOnlyTheirEntries(MatchIndex(Index('users_by_age_and_name')));
    }

    public function testRangesAreReadFromTheIndex(): void
    {
        $this->client->query(CreateIndex(['name' => 'users_by_age', 'source' => Collection('users'), 'values' => [['field' => ['data', 'age']]]]));
        $this->insertUsers();

        $this->assertPagesReadOnlyTheirEntries(Between(MatchIndex(Index('users_by_age')), 40, 45));
    }

    public function testReversedSetsReadTheIndexBackward(): void
    {
        $this->client->query(CreateIndex([
            'name' => 'users_by_role',
            'source' => Collection('users'),
            'terms' => [['field' => ['data', 'role']]],
            'values' => [['field' => ['data', 'age']]],
        ]));
        $this->insertUsers();

        $this->assertPagesReadOnlyTheirEntries(Reverse(MatchIndex(Index('users_by_role'), 'r42')));
    }

    public function testUnionPagesReadOnlyOnePagePerIndex(): void
    {
        foreach (['users_by_role' => 'role', 'users_by_name' => 'name'] as $name => $term) {
            $this->client->query(CreateIndex([
                'name' => $name,
                'source' => Collection('users'),
                'terms' => [['field' => ['data', $term]]],
                'values' => [['field' => ['data', 'age']]],
            ]));
        }
        $this->insertUsers();
        $set = Union(MatchIndex(Index('users_by_role'), 'r42'), MatchIndex(Index('users_by_name'), 'n3'));

        $page = $this->client->query(Paginate($set, ['size' => self::SIZE]));
        for ($i = 0; $i < 20; ++$i) {
            $page = $this->client->query(Paginate($set, ['size' => self::SIZE, 'after' => $page->after]));
        }

        // Each branch reads one page of its own index into a temporary table; only the union of these pages is sorted.
        $after = $page->after;
        [$page, $status] = $this->measure(fn () => $this->client->query(Paginate($set, ['size' => self::SIZE, 'after' => $after])));

        $pages = 2 * (self::SIZE + 1);
        self::assertCount(self::SIZE, $page->data);
        self::assertLessThanOrEqual($pages, $status['Handler_read_next'] + $status['Handler_read_prev'], 'An index was read beyond a page.');
        self::assertLessThanOrEqual($pages, $status['Sort_rows'], 'More than the pages of the branches were sorted.');
        self::assertLessThanOrEqual(3 * $pages, $status['Handler_read_rnd_next'], 'A table was scanned.');
    }

    /**
     * Reading the first page, then a page far into the set, examines no more rows than the page holds: the database
     * seeks into the index instead of scanning or sorting.
     */
    private function assertPagesReadOnlyTheirEntries(mixed $set): void
    {
        $page = $this->assertReadFromTheIndex(fn () => $this->client->query(Paginate($set, ['size' => self::SIZE])));

        for ($i = 0; $i < 20; ++$i) {
            $page = $this->client->query(Paginate($set, ['size' => self::SIZE, 'after' => $page->after]));
        }

        $page = $this->assertReadFromTheIndex(fn () => $this->client->query(Paginate($set, ['size' => self::SIZE, 'after' => $page->after])));
        self::assertCount(self::SIZE, $page->data);
    }

    /**
     * Runs the query and checks that it read no table row, sorted nothing, and went through no more index entries than
     * the page holds (plus the lookup of the index definition).
     */
    private function assertReadFromTheIndex(callable $query): mixed
    {
        [$result, $status] = $this->measure($query);

        self::assertSame(0, $status['Handler_read_rnd_next'], 'A table was scanned.');
        self::assertSame(0, $status['Sort_rows'], 'Rows were sorted.');
        self::assertLessThanOrEqual(self::SIZE + 1, $status['Handler_read_next'] + $status['Handler_read_prev']);

        return $result;
    }

    /**
     * Runs the query, and returns its result and the handler counters of the session for it.
     *
     * The counters also count the reads of its own metadata a server does when it opens a table, the first time or
     * after updating its statistics in the background. Those only add reads, while a bad plan reads every time: the
     * query is measured a few times, and the smallest counts kept.
     *
     * @return array{mixed, array<string, int>}
     */
    private function measure(callable $query): array
    {
        $result = null;
        $least = null;
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $this->pdo->exec('FLUSH STATUS');
            $result = $query();
            $status = array_map(intval(...), self::select($this->pdo, "SHOW SESSION STATUS WHERE Variable_name LIKE 'Handler_read%' OR Variable_name = 'Sort_rows'")
                ->fetchAll(PDO::FETCH_KEY_PAIR));

            if ($least === null || array_sum($status) < array_sum($least)) {
                $least = $status;
            }
        }

        return [$result, $least];
    }

    /**
     * Inserts 10,000 users, numbered with the digits of four joined tables (both databases support this).
     */
    private function insertUsers(): void
    {
        $digits = '(' . implode(' UNION ALL ', array_map(static fn (int $n): string => "SELECT {$n} AS n", range(0, 9))) . ')';

        $this->pdo->exec(<<<SQL
            INSERT INTO users (id, ts, data)
            SELECT i, 0, JSON_OBJECT('role', CONCAT('r', i % 100), 'age', i % 90, 'name', CONCAT('n', i % 7))
            FROM (SELECT 1 + a.n + 10 * b.n + 100 * c.n + 1000 * d.n AS i FROM {$digits} a, {$digits} b, {$digits} c, {$digits} d) n
            SQL);
        self::select($this->pdo, 'ANALYZE TABLE users')->fetchAll();
    }
}
