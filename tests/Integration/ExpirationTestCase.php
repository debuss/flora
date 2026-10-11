<?php declare(strict_types=1);

namespace Flora\Tests\Integration;

use Flora\Exception\{InvalidArgument, NotFound};
use Flora\Value\Ref as RefValue;

use function Flora\{
    At,
    Collection,
    CreateCollection,
    Events,
    Exists,
    Get,
    Insert,
    Paginate,
    Ref,
    Update,
};

/**
 * Expiring the history of collections: events older than history_days are removed, but the version each document had
 * then is kept.
 */
abstract class ExpirationTestCase extends IntegrationTestCase
{

    private const int DAY = 86_400_000_000;

    private int $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = (int) (microtime(true) * 1_000_000);
        $this->client->query(CreateCollection(['name' => 'users']));
        $this->client->query(CreateCollection(['name' => 'logs', 'history_days' => 2]));
    }

    public function testHistoryIsKeptForEverByDefault(): void
    {
        self::assertNull($this->client->query(Get(Collection('users')))['history_days']);
        self::assertSame(2, $this->client->query(Get(Collection('logs')))['history_days']);

        $ref = $this->history('users', '1', [-5 => 'create', -4 => 'update']);

        self::assertSame(0, $this->client->expireHistory());
        self::assertCount(2, $this->events($ref));
    }

    public function testExpiringHistoryKeepsTheVersionAtTheCutoff(): void
    {
        $ref = $this->history('logs', '1', [-5 => 'create', -4 => 'update', -3 => 'update', -1 => 'update']);

        // Two days ago, the document was its version of three days ago: the two before it go.
        self::assertSame(2, $this->client->expireHistory());

        self::assertSame([$this->ago(3), $this->ago(1)], array_column($this->events($ref), 'ts'));
        self::assertSame(-3, $this->client->query(At($this->ago(2), Get($ref)))['data']['day']);
        self::assertSame(-1, $this->client->query(Get($ref))['data']['day']);
    }

    public function testDeletedDocumentsLeaveNoHistory(): void
    {
        $gone = $this->history('logs', '1', [-5 => 'create', -4 => 'delete']);
        $back = $this->history('logs', '2', [-5 => 'create', -4 => 'delete', -3 => 'create', -1 => 'update']);

        self::assertSame(4, $this->client->expireHistory());

        self::assertSame([], $this->events($gone));
        self::assertFalse($this->client->query(At($this->ago(3), Exists($gone))));
        self::assertSame(['create', 'update'], array_column($this->events($back), 'action'));
    }

    public function testZeroDaysKeepsOnlyTheCurrentVersions(): void
    {
        $ref = $this->history('logs', '1', [-3 => 'create', -1 => 'update']);

        $this->client->query(Update(Collection('logs'), ['history_days' => 0]));

        self::assertSame(1, $this->client->expireHistory());
        self::assertSame([$this->ago(1)], array_column($this->events($ref), 'ts'));
        self::assertSame(0, $this->client->expireHistory());
    }

    public function testUpdatingTheSettingsOfACollection(): void
    {
        $updated = $this->client->query(Update(Collection('logs'), ['history_days' => 7]));

        self::assertSame(7, $updated['history_days']);
        self::assertSame(7, $this->client->query(Get(Collection('logs')))['history_days']);
        self::assertNull($this->client->query(Update(Collection('logs'), ['history_days' => null]))['history_days']);
    }

    public function testHistoryDaysIsANumberOfDays(): void
    {
        $this->expectException(InvalidArgument::class);

        $this->client->query(Update(Collection('logs'), ['history_days' => -1]));
    }

    public function testUpdatingACollectionThatDoesNotExist(): void
    {
        $this->expectException(NotFound::class);

        $this->client->query(Update(Collection('nope'), ['history_days' => 1]));
    }

    /**
     * Writes the history of a document: an event each given day, before now, with its day in its data.
     *
     * @param array<int, string> $events the action of each event, by day
     */
    private function history(string $collection, string $id, array $events): RefValue
    {
        $ref = $this->client->query(Ref(Collection($collection), $id));
        foreach ($events as $day => $action) {
            $this->client->query(Insert($ref, $this->ago(-$day), $action, ['data' => ['day' => $day]]));
        }

        return $ref;
    }

    private function ago(int $days): int
    {
        return $this->now - $days * self::DAY;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function events(RefValue $ref): array
    {
        return $this->client->query(Paginate(Events($ref)))->data;
    }
}
