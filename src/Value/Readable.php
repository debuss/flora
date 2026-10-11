<?php declare(strict_types=1);

namespace Flora\Value;

use Flora\Schema\IndexField;

/**
 * Entries read in order with Paginate(), Get() or Exists(): a Set, which drivers read with SQL, or a FilteredSet,
 * whose entries are checked one by one.
 */
interface Readable
{

    /**
     * The collections the entries point to, each once: none for the sets of the schema, whose entries are schema refs.
     *
     * @return list<CollectionRef>
     */
    public function collections(): array;

    /**
     * The fields each entry holds before its ref.
     *
     * @return list<IndexField>
     */
    public function values(): array;
}
