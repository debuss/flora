<?php declare(strict_types=1);

namespace Flora\Tests\Integration\Sqlite;

use PDO;
use Flora\Tests\Integration\IntegrationTestCase;

use function Flora\{Between, Collection, CreateCollection, CreateIndex, Index, MatchIndex, Paginate, Reverse, Union};

/**
 * Checks the plans SQLite picks for the SQL the driver generates.
 */
final class QueryPlansTest extends IntegrationTestCase
{

    use UsesSqlite;

    protected static function connect(): PDO
    {
        return new RecordingPdo((string) getenv('FLORA_SQLITE_DSN'));
    }

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

        $this->assertPagesUseTheIndex(MatchIndex(Index('users_by_role'), 'r42'), 'flora_idx_users_by_role');
    }

    public function testPagesAreReadFromTheIndexWithMixedDirections(): void
    {
        $this->client->query(CreateIndex([
            'name' => 'users_by_age_and_name',
            'source' => Collection('users'),
            'values' => [['field' => ['data', 'age']], ['field' => ['data', 'name'], 'reverse' => true]],
        ]));

        $this->assertPagesUseTheIndex(MatchIndex(Index('users_by_age_and_name')), 'flora_idx_users_by_age_and_name');
    }

    public function testRangesAreReadFromTheIndex(): void
    {
        $this->client->query(CreateIndex(['name' => 'users_by_age', 'source' => Collection('users'), 'values' => [['field' => ['data', 'age']]]]));

        $this->assertPagesUseTheIndex(Between(MatchIndex(Index('users_by_age')), 40, 45), 'flora_idx_users_by_age');
        // The bounds start and stop the scan of the index.
        self::assertMatchesRegularExpression('/SEARCH \w+ USING INDEX flora_idx_users_by_age \(.*>.*<.*\)/', $this->lastPlan());
    }

    public function testReversedSetsReadTheIndexBackward(): void
    {
        $this->client->query(CreateIndex(['name' => 'users_by_age', 'source' => Collection('users'), 'values' => [['field' => ['data', 'age']]]]));

        $this->assertPagesUseTheIndex(Reverse(MatchIndex(Index('users_by_age'))), 'flora_idx_users_by_age');
    }

    public function testUnionPagesReadEachIndex(): void
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

        $after = $this->client->query(Paginate($set, ['size' => 5]))->after;
        $this->client->query(Paginate($set, ['size' => 5, 'after' => $after]));

        $pdo = $this->pdo;
        self::assertInstanceOf(RecordingPdo::class, $pdo);
        $unions = array_filter($pdo->statements, static fn (string $sql): bool => str_contains($sql, ' UNION ALL '));
        $plan = implode("\n", self::select($pdo, 'EXPLAIN QUERY PLAN ' . end($unions))->fetchAll(PDO::FETCH_COLUMN, 3));

        // Each branch reads one page of its own index, from the cursor.
        self::assertMatchesRegularExpression('/SEARCH \w+ USING INDEX flora_idx_users_by_role \(.*\) ?> ?\(/', $plan);
        self::assertMatchesRegularExpression('/SEARCH \w+ USING INDEX flora_idx_users_by_name \(.*\) ?> ?\(/', $plan);
        self::assertDoesNotMatchRegularExpression('/^SCAN flora_t\d+$/m', $plan);
    }

    public function testUnrolledIndexPagesReadTheirEntries(): void
    {
        $this->pdo->exec(<<<'SQL'
            WITH RECURSIVE n (i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < 5000)
            INSERT INTO users (id, ts, data)
            SELECT i, 0, json_object('tags', json_array('a' || (i % 50), 'b' || (i % 7)), 'age', i % 90) FROM n
            SQL);

        // Created after the users: it is filled with their entries.
        $this->client->query(CreateIndex([
            'name' => 'users_by_tag',
            'source' => Collection('users'),
            'terms' => [['field' => ['data', 'tags'], 'unroll' => true]],
            'values' => [['field' => ['data', 'age']]],
        ]));
        $this->pdo->exec('ANALYZE');

        $this->assertPagesUseTheIndex(MatchIndex(Index('users_by_tag'), 'a7'), 'flora_idx_users_by_tag', insert: false);
        // The index of the table of entries, searched by tag.
        self::assertMatchesRegularExpression('/SEARCH \w+ USING INDEX flora_idx_users_by_tag \(t0=\?/', $this->lastPlan());
    }

    /**
     * The first page and a page after a cursor are both read from the index, without sorting, and the page after the
     * cursor starts from a range of the index rather than from its beginning.
     */
    private function assertPagesUseTheIndex(mixed $set, string $index, bool $insert = true): void
    {
        if ($insert) {
            $this->insertUsers();
        }

        $page = $this->client->query(Paginate($set, ['size' => 5]));
        $this->assertLastScanUses($index);

        $this->client->query(Paginate($set, ['size' => 5, 'after' => $page->after]));
        self::assertMatchesRegularExpression('/\) ?[<>]=? ?\(\?/', $this->assertLastScanUses($index));
    }

    /**
     * @return string the plan
     */
    private function assertLastScanUses(string $index): string
    {
        $pdo = $this->pdo;
        self::assertInstanceOf(RecordingPdo::class, $pdo);

        $scans = array_filter($pdo->statements, static fn (string $sql): bool => preg_match('/^SELECT (DISTINCT )?id, /', $sql) === 1);
        $plan = implode("\n", self::select($pdo, 'EXPLAIN QUERY PLAN ' . end($scans))->fetchAll(PDO::FETCH_COLUMN, 3));

        self::assertStringContainsString("USING INDEX {$index}", $plan);
        self::assertStringNotContainsString('TEMP B-TREE', $plan);

        return $plan;
    }

    private function insertUsers(): void
    {
        $this->pdo->exec(<<<'SQL'
            WITH RECURSIVE n (i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < 10000)
            INSERT INTO users (id, ts, data)
            SELECT i, 0, json_object('role', 'r' || (i % 100), 'age', i % 90, 'name', 'n' || (i % 7)) FROM n
            SQL);
        $this->pdo->exec('ANALYZE');
    }

    private function lastPlan(): string
    {
        $pdo = $this->pdo;
        self::assertInstanceOf(RecordingPdo::class, $pdo);
        $scans = array_filter($pdo->statements, static fn (string $sql): bool => preg_match('/^SELECT (DISTINCT )?id, /', $sql) === 1);

        return implode("\n", self::select($pdo, 'EXPLAIN QUERY PLAN ' . end($scans))->fetchAll(PDO::FETCH_COLUMN, 3));
    }
}
