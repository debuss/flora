<?php declare(strict_types=1);

namespace Flora;

use Flora\Driver\Driver;
use Flora\Id\{IdGenerator, Snowflake};

/**
 * Runs queries built with the Flora functions:
 *
 *     $client->query(Get(Ref(Collection('users'), '123')));
 *
 * Each query runs in a single transaction: either all its writes happen, or none of them do.
 */
final readonly class Client
{

    public function __construct(
        private Driver $driver,
        private IdGenerator $ids = new Snowflake()
    ) {}

    public function query(mixed $expr): mixed
    {
        return $this->driver->transaction(function () use ($expr): mixed {
            $ts = (int) (microtime(true) * 1_000_000);

            return Context::start($this->driver, $this->ids, $ts)->evaluate($expr);
        });
    }

    /**
     * Removes the events older than the history of their collections keeps (history_days), keeping for each document
     * the version it had then. Meant to run regularly, from a scheduled task.
     *
     * @return int how many events were removed
     */
    public function expireHistory(): int
    {
        return $this->driver->transaction(fn (): int => $this->driver->expireHistory((int) (microtime(true) * 1_000_000)));
    }
}
