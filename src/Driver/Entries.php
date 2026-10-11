<?php declare(strict_types=1);

namespace Flora\Driver;

use Flora\Schema\{IndexDefinition, IndexField};
use Flora\Support\Json;

/**
 * The entries a document has in an index that unrolls fields, which drivers store in a table of the index.
 *
 * Each entry holds a value per field of the index, terms first, then values. An unrolled field holding a list gives an
 * entry per item, and several of them every combination of their items. A document missing a term or an unrolled
 * field, or with an empty list in an unrolled field, has no entry; another missing value is null. The same entry is
 * given once.
 *
 * @internal
 */
final class Entries
{

    /**
     * @param array<string, mixed> $data
     * @return list<list<mixed>> the value of each field, null for ref and ts fields, which drivers read from the document
     */
    public static function of(IndexDefinition $index, array $data): array
    {
        $entries = [[]];
        foreach ([...$index->terms, ...$index->values] as $position => $field) {
            $choices = self::choices($field, $data, $position < count($index->terms));

            $product = [];
            foreach ($entries as $entry) {
                foreach ($choices as $choice) {
                    $product[] = [...$entry, $choice];
                }
            }
            $entries = $product;
        }

        $distinct = [];
        foreach ($entries as $entry) {
            $distinct[Json::encode($entry)] = $entry;
        }

        return array_values($distinct);
    }

    /**
     * The values a field takes in the entries of the document.
     *
     * @param array<string, mixed> $data
     * @return list<mixed>
     */
    private static function choices(IndexField $field, array $data, bool $term): array
    {
        if ($field->isRef() || $field->isTs()) {
            return [null];
        }

        $value = $data;
        foreach ($field->dataPath() as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return $term || $field->unroll ? [] : [null];
            }
            $value = $value[$key];
        }

        return $field->unroll && is_array($value) && array_is_list($value) ? $value : [$value];
    }
}
