<?php declare(strict_types=1);

namespace Flora\Tests\Integration\Postgres;

use PDOException;
use Flora\Tests\Integration\IntegrationTestCase;
use Pdo\Pgsql;

use function Flora\{Between, Collection, CreateCollection, CreateIndex, Index, MatchIndex, Paginate, Reverse, Union};

/**
 * Checks the plans PostgreSQL picks for the SQL the driver generates.
 */
final class QueryPlansTest extends IntegrationTestCase
{

    use UsesPostgres;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client->query(CreateCollection(['name' => 'users']));
    }

    public function testQueriesUseTheIndex(): void
    {
        $this->client->query(CreateIndex([
            'name' => 'users_by_role',
            'source' => Collection('users'),
            'terms' => [['field' => ['data', 'role']]],
            'values' => [['field' => ['data', 'age'], 'reverse' => true]],
        ]));
        $this->pdo->exec("INSERT INTO users (id, ts, data) SELECT i, 0, jsonb_build_object('role', 'r' || (i % 100), 'age', i % 90) FROM generate_series(1, 20000) i");
        $this->pdo->exec('ANALYZE users');
        $set = MatchIndex(Index('users_by_role'), 'r42');

        $plans = $this->plansOf(fn () => $this->client->query(Paginate($set, ['size' => 5])));
        self::assertStringContainsString('Index Scan using flora_idx_users_by_role', $plans);
        self::assertStringNotContainsString('Sort', $plans);

        // Resuming from a cursor seeks into the index rather than filtering what comes before.
        $after = $this->client->query(Paginate($set, ['size' => 5]))->after;
        $plans = $this->plansOf(fn () => $this->client->query(Paginate($set, ['size' => 5, 'after' => $after])));
        self::assertMatchesRegularExpression('/Index Cond: .*ROW\\(COALESCE/', $plans);
    }

    public function testRangesAreReadFromTheIndex(): void
    {
        $this->client->query(CreateIndex(['name' => 'users_by_age', 'source' => Collection('users'), 'values' => [['field' => ['data', 'age']]]]));
        $this->pdo->exec("INSERT INTO users (id, ts, data) SELECT i, 0, jsonb_build_object('age', i % 90) FROM generate_series(1, 20000) i");
        $this->pdo->exec('ANALYZE users');
        $set = Between(MatchIndex(Index('users_by_age')), 40, 45);

        // The bounds start and stop the scan of the index, like a cursor.
        $after = $this->client->query(Paginate($set, ['size' => 5]))->after;
        $plans = $this->plansOf(fn () => $this->client->query(Paginate($set, ['size' => 5, 'after' => $after])));
        self::assertStringContainsString('Index Scan using flora_idx_users_by_age', $plans);
        self::assertMatchesRegularExpression('/Index Cond: .*>= .*<= /', $plans);
        self::assertStringNotContainsString('Sort', $plans);
        self::assertStringNotContainsString('Seq Scan', $plans);
    }

    public function testReversedSetsReadTheIndexBackward(): void
    {
        $this->client->query(CreateIndex(['name' => 'users_by_age', 'source' => Collection('users'), 'values' => [['field' => ['data', 'age']]]]));
        $this->pdo->exec("INSERT INTO users (id, ts, data) SELECT i, 0, jsonb_build_object('age', i % 90) FROM generate_series(1, 20000) i");
        $this->pdo->exec('ANALYZE users');
        $set = Reverse(MatchIndex(Index('users_by_age')));

        $after = $this->client->query(Paginate($set, ['size' => 5]))->after;
        $plans = $this->plansOf(fn () => $this->client->query(Paginate($set, ['size' => 5, 'after' => $after])));
        self::assertStringContainsString('Index Scan Backward using flora_idx_users_by_age', $plans);
        self::assertStringNotContainsString('Sort', $plans);
    }

    public function testUnionPagesReadEachIndex(): void
    {
        foreach (['users_by_role' => 'role', 'users_by_city' => 'city'] as $name => $term) {
            $this->client->query(CreateIndex([
                'name' => $name,
                'source' => Collection('users'),
                'terms' => [['field' => ['data', $term]]],
                'values' => [['field' => ['data', 'age']]],
            ]));
        }
        $this->pdo->exec("INSERT INTO users (id, ts, data) SELECT i, 0, jsonb_build_object('role', 'r' || (i % 10), 'city', 'c' || (i % 13), 'age', i % 90) FROM generate_series(1, 20000) i");
        $this->pdo->exec('ANALYZE users');
        $set = Union(MatchIndex(Index('users_by_role'), 'r1'), MatchIndex(Index('users_by_city'), 'c2'));

        $after = $this->client->query(Paginate($set, ['size' => 5]))->after;
        $plans = $this->plansOf(fn () => $this->client->query(Paginate($set, ['size' => 5, 'after' => $after])));

        // Each branch reads one page of its own index, from the cursor.
        self::assertStringContainsString('Index Scan using flora_idx_users_by_role', $plans);
        self::assertStringContainsString('Index Scan using flora_idx_users_by_city', $plans);
        self::assertStringNotContainsString('Seq Scan', $plans);
    }

    /**
     * The plans PostgreSQL chose for the queries on "users" run by the callback.
     */
    private function plansOf(callable $callback): string
    {
        try {
            $this->pdo->exec("LOAD 'auto_explain'");
        } catch (PDOException) {
            self::markTestSkipped('auto_explain is not available.');
        }

        $pdo = $this->pdo;
        self::assertInstanceOf(Pgsql::class, $pdo);

        $plans = [];
        $this->pdo->exec("SET auto_explain.log_min_duration = 0; SET auto_explain.log_level = 'notice'");
        $pdo->setNoticeCallback(static function (string $message) use (&$plans): void {
            $plans[] = $message;
        });

        try {
            $callback();
        } finally {
            $pdo->setNoticeCallback(null);
            $this->pdo->exec('RESET auto_explain.log_min_duration');
        }

        return implode("\n", array_filter($plans, static fn (string $plan): bool => str_contains($plan, 'FROM "users"')));
    }
}
