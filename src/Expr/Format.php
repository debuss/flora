<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;
use Flora\Exception\InvalidArgument;
use Flora\Support\{Conversions, Json};

/**
 * A string built from a format and values, like FQL's Format, whose conversions are Java's:
 *
 *     Format('%s is %d years old', 'Ada', 36)
 *
 * A conversion is %[argument$][flags][width][.precision]type: s and S (a string, upper case), d (an integer), f, e and
 * E (a number), x, X and o (an integer in hexadecimal or octal), b (a boolean), @ (any value, written as JSON like
 * documents store it: refs, instants and dates tagged, fields starting with @ given another one), % (a percent sign)
 * and n (a new line). Flags are -, +, space and 0. Arguments are taken in order, or by position: %2$s.
 */
final readonly class Format implements Expr
{

    private const string CONVERSION = '/%(?:(\d+)\$)?([-+ 0]*)(\d+)?(?:\.(\d+))?(.)/u';

    /** @var list<mixed> */
    private array $values;

    public function __construct(private mixed $format, mixed ...$values)
    {
        $this->values = array_values($values);
    }

    public function evaluate(Context $context): string
    {
        $format = $context->evaluateString($this->format, 'Format() format');
        $values = $context->evaluateEach($this->values);
        $next = 0;

        return (string) preg_replace_callback(self::CONVERSION, function (array $conversion) use ($values, &$next): string {
            [, $position, $flags, $width, $precision, $type] = $conversion;

            if ($type === '%' || $type === 'n') {
                return $type === '%' ? '%' : "\n";
            }

            $index = $position === '' ? $next++ : (int) $position - 1;
            if (!array_key_exists($index, $values)) {
                throw new InvalidArgument(sprintf('Format() conversion "%s" has no value: %d given.', $conversion[0], count($values)));
            }

            return self::convert($values[$index], $conversion[0], $flags, $width, $precision, $type);
        }, $format);
    }

    private static function convert(mixed $value, string $conversion, string $flags, string $width, string $precision, string $type): string
    {
        // PHP runs out of memory on huge widths, and warns beyond 53 digits of precision.
        if ((int) $width > StringFunction::MAX_LENGTH || strlen($width) > 9) {
            throw new InvalidArgument(sprintf('Format() conversion "%s" is wider than %d characters.', $conversion, StringFunction::MAX_LENGTH));
        }
        if (in_array($type, ['f', 'e', 'E'], true) && ((int) $precision > 53 || strlen($precision) > 2)) {
            throw new InvalidArgument(sprintf('Format() conversion "%s" asks more than 53 digits.', $conversion));
        }

        $spec = static fn (string $type): string => '%' . $flags . $width . ($precision === '' ? '' : '.' . $precision) . $type;
        $integer = static fn (): int => is_int($value) ? $value : throw InvalidArgument::expected(sprintf('Format() "%s" value', $conversion), 'an integer', $value);

        return match ($type) {
            's', 'S' => self::pad(self::string($value, $precision, $type === 'S'), $flags, $width),
            '@' => self::pad(Json::encode($value), $flags, $width),
            'b' => self::pad(is_bool($value) ? ($value ? 'true' : 'false') : throw InvalidArgument::expected(sprintf('Format() "%s" value', $conversion), 'a boolean', $value), $flags, $width),
            'd' => sprintf($spec('d'), $integer()),
            'x', 'X', 'o' => sprintf($spec($type), $integer()),
            'f', 'e', 'E' => sprintf($spec($type), is_int($value) || is_float($value) ? $value : throw InvalidArgument::expected(sprintf('Format() "%s" value', $conversion), 'a number', $value)),
            default => throw new InvalidArgument(sprintf('Format() does not know the conversion "%s".', $conversion)),
        };
    }

    private static function string(mixed $value, string $precision, bool $upper): string
    {
        $string = Conversions::toString($value, 'Format()');
        $string = $precision === '' ? $string : mb_substr($string, 0, (int) $precision);

        return $upper ? mb_strtoupper($string) : $string;
    }

    /**
     * Pads to a width in code points, on the left unless the flag "-" is there.
     */
    private static function pad(string $value, string $flags, string $width): string
    {
        return $width === '' ? $value : mb_str_pad($value, (int) $width, ' ', str_contains($flags, '-') ? STR_PAD_RIGHT : STR_PAD_LEFT);
    }
}
