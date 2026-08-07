<?php

declare(strict_types=1);

namespace App\Shared\Application\Partner;

use App\Shared\Contracts\Partner\PartnerReferenceSource;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;

/**
 * Base for a module that answers the partner-reference question by counting
 * rows in its own tables.
 *
 * Subclasses declare their tables and nothing else. All of the parts that
 * are easy to get wrong live here exactly once:
 *
 *  - **Connection resolved at CALL time, never at construction time.** See
 *    the BUG-007 writeup on `PartnerReferenceSource` and on
 *    `PartnerReferenceCounter`. `DatabaseManager::connection()` re-reads
 *    `database.default` on every call, so a query issued after
 *    `ResolveTenancy` has swapped the default hits the tenant DB even
 *    though this object was built while the default was still `central`.
 *    Never cache the return value of `->connection()` on the instance.
 *  - **Multi-column tables are counted once per ROW**, not once per column.
 *  - **Soft-deleted rows never block**: an invisible row cannot be
 *    orphaned by making the partner invisible too.
 */
abstract class TableBackedPartnerReferenceSource implements PartnerReferenceSource
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    /**
     * The tables this module owns that point at `partners.id`.
     *
     * PUBLIC on purpose: `PartnerReferenceSchemaContractTest` walks every
     * tagged source's declaration and asserts each table/column actually
     * exists in the migrated schema (and that the soft-delete flag matches
     * reality). That test is what turns the query-builder reads back into a
     * GUARDED schema dependency — the L6 gate's objection to widening this
     * sweep. Do not narrow the visibility without replacing that guard.
     *
     * @return non-empty-list<PartnerReferenceTable>
     */
    abstract public function tables(): array;

    /**
     * @return array<string, int>
     */
    final public function countPartnerReferences(string $partnerId): array
    {
        $counts = [];

        foreach ($this->tables() as $table) {
            // `connection()` (no args) is deliberately called HERE, on every
            // query, rather than being hoisted into the constructor.
            $query = $this->db->connection()
                ->table($table->table)
                ->where(static function (Builder $builder) use ($table, $partnerId): void {
                    foreach ($table->columns as $column) {
                        $builder->orWhere($column, $partnerId);
                    }
                });

            if ($table->hasSoftDeletes) {
                $query->whereNull('deleted_at');
            }

            $counts[$table->table] = $query->count();
        }

        return $counts;
    }
}
