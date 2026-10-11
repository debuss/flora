<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Context;
use Flora\Exception\InvalidArgument;
use Flora\Value\JoinItem;

/**
 * The strings of a list joined into one, with a separator between them: Concat(['Ada', 'Lovelace'], ' ').
 */
final readonly class Concat implements Expr
{

    public function __construct(private mixed $strings, private mixed $separator = '') {}

    public function evaluate(Context $context): string
    {
        $strings = $context->evaluate($this->strings);
        $separator = $context->evaluate($this->separator);

        if (!is_array($strings) || !array_is_list($strings)) {
            throw InvalidArgument::expected('Concat() strings', 'a list of strings', $strings);
        }

        $parts = [];
        foreach ([...$strings, $separator] as $string) {
            if ($string instanceof JoinItem) {
                throw JoinItem::misuse();
            }

            $parts[] = is_string($string) ? $string : throw InvalidArgument::expected('Concat() strings and separator', 'strings', $string);
        }
        $separator = array_pop($parts);

        return implode($separator, $parts);
    }
}
