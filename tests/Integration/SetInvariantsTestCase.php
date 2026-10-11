<?php declare(strict_types=1);

namespace Flora\Tests\Integration;

use Flora\Value\Page;

use function Flora\{IsEmpty, Paginate, Reverse, Size};

/**
 * What every set must do on every database, whatever its entries: pages read forward or backward, of any size, give
 * the whole set once and in order; Size(), Reverse() and IsEmpty() agree with it. Run on AuditFixture, made to catch
 * what databases do differently.
 */
abstract class SetInvariantsTestCase extends IntegrationTestCase
{

    private const int PAGES = 200;

    public function testSetsReadTheSameWhateverTheWay(): void
    {
        AuditFixture::create($this->client);
        AuditFixture::write($this->client);

        foreach (AuditFixture::sets() as $label => $set) {
            $all = AuditFixture::normalize($this->client->query(Paginate($set, ['size' => 1000]))->data);
            self::assertIsArray($all);

            foreach ([1, 3, 7] as $size) {
                $pages = $this->pages($set, $size, $label);
                self::assertSame($all, array_merge(...array_map(static fn (Page $page): array => (array) AuditFixture::normalize($page->data), $pages)), "$label: pages of $size");

                for ($i = count($pages) - 1; $i > 0; --$i) {
                    $before = $this->client->query(Paginate($set, ['size' => $size, 'before' => $pages[$i]->before]));
                    self::assertSame(AuditFixture::normalize($pages[$i - 1]->data), AuditFixture::normalize($before->data), "$label: page $i of $size, read backward");
                }
            }

            self::assertSame(count($all), $this->client->query(Size($set)), "$label: Size()");
            self::assertSame(array_reverse($all), AuditFixture::normalize($this->client->query(Paginate(Reverse($set), ['size' => 1000]))->data), "$label: Reverse()");
            self::assertSame($all === [], $this->client->query(IsEmpty($set)), "$label: IsEmpty()");
        }
    }

    /**
     * The pages of a set read forward, which must end.
     *
     * @return list<Page>
     */
    private function pages(mixed $set, int $size, string $label): array
    {
        $pages = [$this->client->query(Paginate($set, ['size' => $size]))];
        while (end($pages)->after !== null) {
            self::assertLessThan(self::PAGES, count($pages), "$label: pages of $size do not end");
            $pages[] = $this->client->query(Paginate($set, ['size' => $size, 'after' => end($pages)->after]));
        }

        return $pages;
    }
}
