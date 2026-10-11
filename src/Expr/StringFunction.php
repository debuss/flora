<?php declare(strict_types=1);

namespace Flora\Expr;

use Normalizer;
use RuntimeException;
use Flora\Context;
use Flora\Exception\InvalidArgument;
use Flora\Support\Regex;

/**
 * The functions on strings, named like FQL's, except TrimStr(), LTrimStr() and RTrimStr(), which would take the place
 * of PHP's trim(), ltrim() and rtrim() where they are imported. Strings are read as UTF-8: positions and lengths count
 * code points. Optional arguments are null when not given.
 */
final readonly class StringFunction implements Expr
{

    /** The longest string Repeat(), Space() and the widths of Format() make: beyond, PHP would run out of memory. */
    public const int MAX_LENGTH = 16 * 1024 * 1024;

    /** @var list<mixed> */
    private array $arguments;

    /**
     * @param 'Casefold'|'ContainsStr'|'ContainsStrRegex'|'EndsWith'|'FindStr'|'FindStrRegex'|'Length'|'LowerCase'|'LTrimStr'|'RegexEscape'|'Repeat'|'ReplaceStr'|'ReplaceStrRegex'|'RTrimStr'|'Space'|'SplitStr'|'SplitStrRegex'|'StartsWith'|'SubString'|'TitleCase'|'TrimStr'|'UpperCase' $function
     *        the name of the function
     */
    public function __construct(private string $function, mixed ...$arguments)
    {
        $this->arguments = array_values($arguments);
    }

    public function evaluate(Context $context): mixed
    {
        $arguments = $context->evaluateEach($this->arguments);
        $string = fn (int $i, string $name): string => $context->evaluateString($arguments[$i], sprintf('%s() %s', $this->function, $name));
        $integer = fn (int $i, string $name, ?int $default = null, int $min = 0): int => $this->integer($arguments[$i] ?? null, $name, $default, $min);
        $regex = fn (int $i): string => Regex::compile($string($i, 'pattern'), $this->function . '()');

        return match ($this->function) {
            'Casefold' => self::casefold($string(0, 'value'), ($arguments[1] ?? null) === null ? 'NFKCCaseFold' : $string(1, 'normalizer')),
            'ContainsStr' => str_contains($string(0, 'value'), $string(1, 'search')),
            'ContainsStrRegex' => preg_match($regex(1), $string(0, 'value')) === 1,
            'EndsWith' => str_ends_with($string(0, 'value'), $string(1, 'search')),
            'FindStr' => self::find($string(0, 'value'), $string(1, 'find'), $integer(2, 'start', 0)),
            'FindStrRegex' => self::findRegex($string(0, 'value'), $regex(1), $integer(2, 'start', 0), $integer(3, 'number of results', 1024, 1)),
            'Length' => mb_strlen($string(0, 'value')),
            'LowerCase' => mb_strtolower($string(0, 'value')),
            'LTrimStr' => preg_replace('/^[\s\p{Z}]+/u', '', $string(0, 'value')),
            'RegexEscape' => preg_quote($string(0, 'value')),
            'Repeat' => self::repeat($string(0, 'value'), $integer(1, 'number', 2), 'Repeat()'),
            'ReplaceStr' => str_replace($string(1, 'find'), $string(2, 'replace'), $string(0, 'value')),
            'ReplaceStrRegex' => preg_replace($regex(1), $string(2, 'replace'), $string(0, 'value'), $this->first($arguments[3] ?? false) ? 1 : -1),
            'RTrimStr' => preg_replace('/[\s\p{Z}]+$/u', '', $string(0, 'value')),
            'Space' => self::repeat(' ', $integer(0, 'count'), 'Space()'),
            'SplitStr' => self::split($string(0, 'value'), $string(1, 'token'), $integer(2, 'count', PHP_INT_MAX, 1)),
            'SplitStrRegex' => preg_split($regex(1), $string(0, 'value'), $integer(2, 'count', -1, 1)),
            'StartsWith' => str_starts_with($string(0, 'value'), $string(1, 'search')),
            // mb_substr() refuses PHP_INT_MIN: so far from the end is the start all the same.
            'SubString' => mb_substr($string(0, 'value'), max(-PHP_INT_MAX, $integer(1, 'start', 0, PHP_INT_MIN)), ($arguments[2] ?? null) === null ? null : $integer(2, 'length')),
            'TitleCase' => mb_convert_case($string(0, 'value'), MB_CASE_TITLE),
            'TrimStr' => preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $string(0, 'value')),
            'UpperCase' => mb_strtoupper($string(0, 'value')),
        };
    }

    /**
     * An integer argument, its default when not given (which may be below the minimum, meaning "none").
     */
    private function integer(mixed $value, string $name, ?int $default, int $min): int
    {
        $value ??= $default;
        if (!is_int($value) || ($value < $min && $value !== $default)) {
            throw InvalidArgument::expected(sprintf('%s() %s', $this->function, $name), match ($min) {
                0 => 'a positive integer or zero',
                1 => 'a positive integer',
                default => 'an integer',
            }, $value);
        }

        return $value;
    }

    private function first(mixed $first): bool
    {
        return is_bool($first) ? $first : throw InvalidArgument::expected(sprintf('%s() first', $this->function), 'a boolean', $first);
    }

    /**
     * A string repeated, as long as it stays below MAX_LENGTH bytes.
     */
    private static function repeat(string $value, int $times, string $function): string
    {
        if ($value !== '' && $times > intdiv(self::MAX_LENGTH, strlen($value))) {
            throw new InvalidArgument(sprintf('%s would make a string longer than %d bytes.', $function, self::MAX_LENGTH));
        }

        return str_repeat($value, $times);
    }

    /**
     * Folds the case of a string for comparisons, then normalizes it: NFKCCaseFold (the default), NFC, NFD, NFKC or
     * NFKD.
     */
    private static function casefold(string $value, string $normalizer): string
    {
        $forms = ['NFKCCaseFold' => 'NFKC', 'NFC' => 'NFC', 'NFD' => 'NFD', 'NFKC' => 'NFKC', 'NFKD' => 'NFKD'];
        if (!isset($forms[$normalizer])) {
            throw InvalidArgument::expected('Casefold() normalizer', 'one of NFKCCaseFold, NFC, NFD, NFKC and NFKD', $normalizer);
        }

        if (!class_exists(Normalizer::class)) {
            throw new RuntimeException('Casefold() needs the intl extension of PHP.');
        }

        $form = constant(Normalizer::class . '::' . $forms[$normalizer]);
        // NFKC_Casefold folds what NFKC gives: compatibility characters fold like those they stand for.
        $value = $normalizer === 'NFKCCaseFold' ? (string) Normalizer::normalize($value, $form) : $value;

        return (string) Normalizer::normalize(mb_convert_case($value, MB_CASE_FOLD), $form);
    }

    /**
     * The position of the first occurrence of a string from a position, or -1.
     */
    private static function find(string $value, string $find, int $start): int
    {
        $position = $start > mb_strlen($value) ? false : mb_strpos($value, $find, $start);

        return $position === false ? -1 : $position;
    }

    /**
     * The matches of a pattern from a position: their start, end (included) and text.
     *
     * @return list<array{start: int, end: int, data: string}>
     */
    private static function findRegex(string $value, string $pattern, int $start, int $limit): array
    {
        preg_match_all($pattern, $value, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER, strlen(mb_substr($value, 0, $start)));

        return array_map(static function (array $match) use ($value): array {
            $start = Regex::codePoints($value, $match[0][1]);

            return ['start' => $start, 'end' => $start + mb_strlen($match[0][0]) - 1, 'data' => $match[0][0]];
        }, array_slice($matches, 0, $limit));
    }

    /**
     * @return list<string>
     */
    private static function split(string $value, string $token, int $count): array
    {
        return $token === '' ? throw new InvalidArgument('SplitStr() token cannot be empty.') : explode($token, $value, $count);
    }
}
