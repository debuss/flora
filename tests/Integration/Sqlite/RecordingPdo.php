<?php declare(strict_types=1);

namespace Flora\Tests\Integration\Sqlite;

use PDOStatement;
use Pdo\Sqlite;

/**
 * A connection keeping the SQL of the statements it prepares.
 */
final class RecordingPdo extends Sqlite
{

    /** @var list<string> */
    public array $statements = [];

    /**
     * @param array<mixed> $options
     */
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->statements[] = $query;

        return parent::prepare($query, $options);
    }
}
