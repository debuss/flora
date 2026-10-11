<?php declare(strict_types=1);

namespace Flora\Tests\Unit;

use ReflectionFunction;
use PHPUnit\Framework\TestCase;

/**
 * AI.md is what assistants read to write queries in projects using Flora: it must name every function, call none that
 * does not exist, and give the right names for those FQL calls otherwise.
 */
final class AiGuideTest extends TestCase
{

    private const string GUIDE = __DIR__ . '/../../AI.md';

    public function testTheGuideNamesEveryFunction(): void
    {
        $guide = self::guide();

        $missing = array_values(array_filter(
            self::functions(),
            static fn (string $function): bool => !str_contains($guide, $function . '(') && !str_contains($guide, '`' . $function . '`'),
        ));

        self::assertSame([], $missing, 'Functions missing from AI.md.');
    }

    public function testTheExamplesCallOnlyFunctionsOfFlora(): void
    {
        preg_match_all('/```php\n(.*?)```/s', self::guide(), $blocks);
        // Calls of functions, not of methods (->x(), ::x()) nor constructors (new X()).
        preg_match_all('/(?<![\w>:\\\\$])(?<!new )\b([A-Z][A-Za-z]*)\(/', implode("\n", $blocks[1]), $calls);

        $unknown = array_values(array_unique(array_diff($calls[1], self::functions())));

        self::assertNotSame([], $blocks[1]);
        self::assertSame([], $unknown, 'Examples of AI.md calling functions Flora does not have.');
    }

    public function testTheRenamesTableGivesTheNamesOfFlora(): void
    {
        $functions = self::functions();
        $lowercase = array_map(strtolower(...), $functions);
        $pairs = 0;

        foreach (explode("\n", self::guide()) as $line) {
            // | `Match` | `MatchIndex` | `Count` | `Size` |, a cell holding several names separated by slashes.
            if (!preg_match('/^\s*\|\s*`/', $line)) {
                continue;
            }

            $cells = array_map(trim(...), array_slice(explode('|', $line), 1, -1));
            foreach (array_chunk($cells, 2) as [$fql, $flora]) {
                preg_match_all('/`(\w+)`/', $fql, $fqlNames);
                preg_match_all('/`(\w+)`/', $flora, $floraNames);
                self::assertCount(count($fqlNames[1]), $floraNames[1], $line);

                foreach (array_combine($fqlNames[1], $floraNames[1]) as $old => $new) {
                    self::assertContains($new, $functions, sprintf('AI.md renames %s to %s, which does not exist.', $old, $new));
                    self::assertNotContains(strtolower($old), $lowercase, sprintf('AI.md renames %s, which exists.', $old));
                    ++$pairs;
                }
            }
        }

        self::assertGreaterThanOrEqual(15, $pairs);
    }

    /**
     * The functions of Flora, named as declared.
     *
     * @return list<string>
     */
    private static function functions(): array
    {
        $functions = array_filter(get_defined_functions()['user'], static fn (string $name): bool => str_starts_with($name, 'flora\\'));

        return array_values(array_map(static fn (string $name): string => new ReflectionFunction($name)->getShortName(), $functions));
    }

    private static function guide(): string
    {
        return (string) file_get_contents(self::GUIDE);
    }
}
