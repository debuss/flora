<?php declare(strict_types=1);

namespace Flora\Tests\Integration;

use LogicException;
use PDO;
use PDOException;
use PDOStatement;
use Flora\Client;
use Flora\Driver\Driver;
use Flora\Value\Page;
use PHPUnit\Framework\TestCase;

use function Flora\Paginate;

/**
 * Runs against a database of compose.yaml, recreated for each test. The tests extending it are run on every driver by
 * a subclass using one of the Uses* traits.
 */
abstract class IntegrationTestCase extends TestCase
{

    protected PDO $pdo;

    protected Client $client;

    /**
     * @throws PDOException when the database is not reachable
     */
    abstract protected static function connect(): PDO;

    /**
     * Empties the database the connection works in.
     */
    abstract protected static function reset(PDO $pdo): void;

    abstract protected static function driver(PDO $pdo): Driver;

    /**
     * Runs SQL directly: PDO throws on errors, so the statement is there.
     */
    protected static function select(PDO $pdo, string $sql): PDOStatement
    {
        return $pdo->query($sql) ?: throw new LogicException(sprintf('The query failed: %s', $sql));
    }

    protected function setUp(): void
    {
        try {
            $this->pdo = static::connect();
        } catch (PDOException $e) {
            self::markTestSkipped('The database is not reachable (run `docker compose up -d`): ' . $e->getMessage());
        }

        static::reset($this->pdo);

        $driver = static::driver($this->pdo);
        $driver->install();
        $this->client = new Client($driver);
    }

    /**
     * Reads a whole set page by page, checking that going back gives the previous pages again.
     *
     * @return list<mixed>
     */
    protected function collectAll(mixed $set, int $size): array
    {
        $pages = [$this->client->query(Paginate($set, ['size' => $size]))];
        while (end($pages)->after !== null) {
            $pages[] = $this->client->query(Paginate($set, ['size' => $size, 'after' => end($pages)->after]));
        }

        for ($i = count($pages) - 1; $i > 0; --$i) {
            self::assertEquals($pages[$i - 1]->data, $this->client->query(Paginate($set, ['size' => $size, 'before' => $pages[$i]->before]))->data);
        }

        return array_merge(...array_map(static fn (Page $page): array => $page->data, $pages));
    }
}
