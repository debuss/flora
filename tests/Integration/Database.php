<?php declare(strict_types=1);

namespace Flora\Tests\Integration;

use LogicException;
use PDO;
use Flora\Driver\{Driver, MariadbDriver, MysqlDriver, PostgresDriver, SqliteDriver};
use Pdo\Pgsql;

/**
 * The databases the integration tests run on, reached through the FLORA_* environment variables (phpunit.xml.dist).
 * Each test works in a database of its own: the flora_test schema or database, SQLite in memory by default.
 */
enum Database: string
{

    case Postgres = 'postgres';
    case Mysql = 'mysql';
    case Mariadb = 'mariadb';
    case Sqlite = 'sqlite';

    /**
     * @throws \PDOException when the database is not reachable
     */
    public function connect(): PDO
    {
        return match ($this) {
            self::Postgres => Pgsql::connect((string) getenv('FLORA_PG_DSN'), (string) getenv('FLORA_PG_USER'), (string) getenv('FLORA_PG_PASSWORD')),
            // Native prepares are the strictest: a named parameter cannot be used twice.
            self::Mysql => new PDO((string) getenv('FLORA_MYSQL_DSN'), (string) getenv('FLORA_MYSQL_USER'), (string) getenv('FLORA_MYSQL_PASSWORD'), [
                PDO::ATTR_EMULATE_PREPARES => false,
            ]),
            self::Mariadb => new PDO((string) getenv('FLORA_MARIADB_DSN'), (string) getenv('FLORA_MARIADB_USER'), (string) getenv('FLORA_MARIADB_PASSWORD'), [
                PDO::ATTR_EMULATE_PREPARES => false,
            ]),
            self::Sqlite => new PDO((string) getenv('FLORA_SQLITE_DSN')),
        };
    }

    /**
     * Empties the database the connection works in.
     */
    public function reset(PDO $pdo): void
    {
        match ($this) {
            self::Postgres => $pdo->exec('DROP SCHEMA IF EXISTS flora_test CASCADE; CREATE SCHEMA flora_test; SET search_path TO flora_test'),
            self::Mysql, self::Mariadb => array_map($pdo->exec(...), ['DROP DATABASE IF EXISTS flora_test', 'CREATE DATABASE flora_test', 'USE flora_test']),
            self::Sqlite => array_map(
                static fn (mixed $table): int|false => $pdo->exec(sprintf('DROP TABLE "%s"', is_string($table) ? $table : '')),
                ($pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'") ?: throw new LogicException('SQLite failed.'))
                    ->fetchAll(PDO::FETCH_COLUMN),
            ),
        };
    }

    public function driver(PDO $pdo): Driver
    {
        return match ($this) {
            self::Postgres => new PostgresDriver($pdo),
            self::Mysql => new MysqlDriver($pdo),
            self::Mariadb => new MariadbDriver($pdo),
            self::Sqlite => new SqliteDriver($pdo),
        };
    }
}
