<?php declare(strict_types=1);

namespace Flora\Tests\Integration\Sqlite;

use LogicException;
use PDO;
use Flora\Client;
use Flora\Driver\SqliteDriver;
use Flora\Exception\Contended;
use PHPUnit\Framework\TestCase;

use function Flora\{Collection, Create, CreateCollection, CreateIndex, Get, Index, MatchIndex, Select};

/**
 * Connections to one database file, as several processes have: a query that reads, then writes, waits for the one
 * writing.
 */
final class ConcurrencyTest extends TestCase
{

    private string $file;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'flora') ?: throw new LogicException('No temporary file.');

        $driver = new SqliteDriver(new PDO('sqlite:' . $this->file));
        $driver->install();
        new Client($driver)->query([
            CreateCollection(['name' => 'users']),
            CreateCollection(['name' => 'posts']),
            CreateIndex(['name' => 'users_by_name', 'source' => Collection('users'), 'terms' => [['field' => ['data', 'name']]]]),
            Create(Collection('users'), ['data' => ['name' => 'Ada']]),
        ]);
    }

    protected function tearDown(): void
    {
        unlink($this->file);
    }

    public function testAQueryWaitsForTheConnectionWriting(): void
    {
        // Another process writes for 300 ms, after the query below has started.
        $writer = proc_open([PHP_BINARY, '-r', <<<'PHP'
            $pdo = new PDO('sqlite:' . $argv[1]);
            $pdo->exec('BEGIN IMMEDIATE');
            $pdo->exec("INSERT INTO posts (id, ts, data) VALUES (1, 1, '{}')");
            echo "writing\n";
            usleep(300_000);
            $pdo->exec('COMMIT');
            PHP, $this->file], [1 => ['pipe', 'w']], $pipes);
        self::assertIsResource($writer);
        self::assertSame("writing\n", fgets($pipes[1]));

        $post = $this->client()->query(Create(Collection('posts'), ['data' => [
            'author' => Select('ref', Get(MatchIndex(Index('users_by_name'), 'Ada'))),
        ]]));

        self::assertSame(0, proc_close($writer));
        self::assertSame('users', $post['data']['author']->collection->name);
    }

    public function testAQueryWaitingTooLongIsContended(): void
    {
        $writer = new PDO('sqlite:' . $this->file);
        $writer->exec('BEGIN IMMEDIATE');

        try {
            $this->expectException(Contended::class);
            $this->client([PDO::ATTR_TIMEOUT => 1])->query(Get(MatchIndex(Index('users_by_name'), 'Ada')));
        } finally {
            $writer->exec('ROLLBACK');
        }
    }

    /**
     * @param array<int, mixed> $options
     */
    private function client(array $options = []): Client
    {
        return new Client(new SqliteDriver(new PDO('sqlite:' . $this->file, options: $options)));
    }
}
