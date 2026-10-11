<?php declare(strict_types=1);

namespace Flora\Tests\Integration;

use Flora\Exception\{AlreadyExists, InvalidArgument, NotFound};
use Flora\Value\{CollectionRef, Ref as RefValue};

use function Flora\{Collection, Create, CreateCollection, Delete, Exists, Get, Multiply, Ref, Replace, Update};

abstract class DocumentsTestCase extends IntegrationTestCase
{

    protected function setUp(): void
    {
        parent::setUp();

        $this->client->query(CreateCollection(['name' => 'users']));
    }

    public function testCreateThenGet(): void
    {
        $created = $this->client->query(Create(Collection('users'), ['data' => ['name' => 'Ada', 'tags' => ['math']]]));

        self::assertInstanceOf(RefValue::class, $created['ref']);
        self::assertSame('users', $created['ref']->collection->name);
        self::assertSame(['name' => 'Ada', 'tags' => ['math']], $created['data']);

        $fetched = $this->client->query(Get(Ref(Collection('users'), $created['ref']->id)));

        self::assertEquals($created, $fetched);
    }

    public function testCreateAtAGivenRef(): void
    {
        $this->client->query(Create(Ref(Collection('users'), '9203420348023942'), ['data' => ['name' => 'Grace']]));

        self::assertSame('Grace', $this->client->query(Get(Ref(Collection('users'), '9203420348023942')))['data']['name']);

        $this->expectException(AlreadyExists::class);
        $this->client->query(Create(Ref(Collection('users'), '9203420348023942'), ['data' => []]));
    }

    public function testNumbersKeepTheirType(): void
    {
        // PostgreSQL writes large doubles with all their digits, which are no integer of PHP.
        $numbers = [1e300, -1e300, 1.5e20, 1.0, 0.1 + 0.2, PHP_INT_MAX, PHP_INT_MIN, 9_007_199_254_740_993];
        $document = $this->client->query(Create(Collection('users'), ['data' => ['numbers' => $numbers]]));

        self::assertSame($numbers, $this->client->query(Get($document['ref']))['data']['numbers']);
    }

    public function testDataHoldsFiniteNumbers(): void
    {
        $this->expectException(InvalidArgument::class);
        $this->expectExceptionMessage('cannot be written as JSON');

        $this->client->query(Create(Collection('users'), ['data' => ['x' => Multiply(1e300, 1e300)]]));
    }

    public function testEmptyDataIsAnObject(): void
    {
        $ref = $this->client->query(Create(Collection('users')))['ref'];

        self::assertSame('{}', self::select($this->pdo, "SELECT data FROM users WHERE id = {$ref->id}")->fetchColumn());
    }

    public function testUpdateMergesData(): void
    {
        $ref = $this->client->query(Create(Collection('users'), ['data' => [
            'name' => 'Ada',
            'address' => ['city' => 'London', 'zip' => 'N1'],
        ]]))['ref'];

        $updated = $this->client->query(Update($ref, ['data' => ['address' => ['city' => 'Paris', 'zip' => null], 'age' => 36]]));

        self::assertSame(['name' => 'Ada', 'address' => ['city' => 'Paris'], 'age' => 36], $updated['data']);
        // JSONB does not keep the order of object keys.
        self::assertEquals($updated['data'], $this->client->query(Get($ref))['data']);
    }

    public function testReplaceOverwritesData(): void
    {
        $ref = $this->client->query(Create(Collection('users'), ['data' => ['name' => 'Ada', 'age' => 36]]))['ref'];

        $this->client->query(Replace($ref, ['data' => ['name' => 'Ada Lovelace']]));

        self::assertSame(['name' => 'Ada Lovelace'], $this->client->query(Get($ref))['data']);
    }

    public function testDeleteReturnsTheDocument(): void
    {
        $ref = $this->client->query(Create(Collection('users'), ['data' => ['name' => 'Ada']]))['ref'];

        self::assertTrue($this->client->query(Exists($ref)));
        self::assertSame(['name' => 'Ada'], $this->client->query(Delete($ref))['data']);
        self::assertFalse($this->client->query(Exists($ref)));

        $this->expectException(NotFound::class);
        $this->client->query(Get($ref));
    }

    public function testRefsInsideDocumentsComeBackAsRefs(): void
    {
        $author = $this->client->query(Create(Collection('users'), ['data' => ['name' => 'Ada']]))['ref'];
        $this->client->query(CreateCollection(['name' => 'posts']));

        $post = $this->client->query(Create(Collection('posts'), ['data' => ['author' => $author]]));

        $fetched = $this->client->query(Get($post['ref']));
        self::assertEquals($author, $fetched['data']['author']);
        self::assertSame('Ada', $this->client->query(Get($fetched['data']['author']))['data']['name']);
    }

    public function testAllWritesOfAQueryShareItsTimestamp(): void
    {
        [$a, $b] = $this->client->query([
            Create(Collection('users'), ['data' => ['name' => 'Ada']]),
            Create(Collection('users'), ['data' => ['name' => 'Grace']]),
        ]);

        self::assertSame($a['ts'], $b['ts']);
        self::assertNotSame($a['ref']->id, $b['ref']->id);
    }

    public function testAFailingQueryWritesNothing(): void
    {
        try {
            $this->client->query([
                Create(Ref(Collection('users'), '1'), ['data' => ['name' => 'Ada']]),
                Get(Ref(Collection('users'), '2')),
            ]);
            self::fail('The query should have failed.');
        } catch (NotFound) {
        }

        self::assertFalse($this->client->query(Exists(Ref(Collection('users'), '1'))));
    }

    public function testUnknownCollection(): void
    {
        $this->expectException(NotFound::class);
        $this->expectExceptionMessage('Collection "nope" does not exist.');

        $this->client->query(Get(Ref(Collection('nope'), '1')));
    }

    public function testCollectionsAreCreatedOnce(): void
    {
        $this->expectException(AlreadyExists::class);

        $this->client->query(CreateCollection(['name' => 'users']));
    }

    public function testDataMustBeAnObject(): void
    {
        $this->expectException(InvalidArgument::class);

        // @phpstan-ignore argument.type (the data is not an object: that is the point)
        $this->client->query(Create(Collection('users'), ['data' => ['a', 'b']]));
    }

    public function testCollectionRefsAreValues(): void
    {
        self::assertEquals(new CollectionRef('users'), $this->client->query(Collection('users')));
    }
}
