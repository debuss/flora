<?php declare(strict_types=1);

namespace Flora\Expr;

use Flora\Exception\InvalidArgument;
use Flora\Support\Values;

/**
 * Validation of the parameters given to Create(), Update() and Replace().
 *
 * @internal
 */
final class DocumentParams
{

    /**
     * @param array<array-key, mixed> $params
     * @return array<string, mixed>
     */
    public static function data(array $params, string $function): array
    {
        $unknown = array_diff(array_keys($params), ['data']);
        if ($unknown !== []) {
            throw new InvalidArgument(sprintf('Unknown %s() parameter(s): %s.', $function, implode(', ', $unknown)));
        }

        return Values::object($params['data'] ?? [], sprintf('%s() "data"', $function));
    }
}
