<?php

declare(strict_types=1);

namespace Tests\Feature\Partner;

use App\Modules\Partner\Application\Services\PartnerReferenceCounter;
use App\Shared\Application\Partner\TableBackedPartnerReferenceSource;
use App\Shared\Contracts\Partner\PartnerReferenceSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The ratchet the R2-S gates demanded (authz I-2 + treasury I-2): the
 * partner delete guard must be checked against the LIVE SCHEMA, not against
 * a list someone remembered to update.
 *
 * `PartnerReferenceSchemaContractTest` proves the guard's declarations point
 * at columns that exist. This test proves the converse, which is the half
 * that actually protects money: that no partner-shaped column exists in the
 * schema WITHOUT a decision attached to it. Every such column must be either
 *
 *  - declared by a tagged `PartnerReferenceSource` (it blocks the delete), or
 *  - listed in `PartnerReferenceCounter::EXCLUDED_PARTNER_COLUMNS` with a
 *    reason (it deliberately does not).
 *
 * The net is deliberately wider than "columns with a foreign key to
 * partners": the guard's worst gaps — `vouchers.partner_id`,
 * `promotion_usages.partner_id`, `payment_instruments.partner_id`,
 * `pos_*_receipts.customer_id` — carry NO foreign key at all, so an
 * FK-driven sweep would have missed exactly the columns that mattered. It
 * matches on NAME instead, which is why a partner-shaped column that is not
 * a partner reference (`tenant_subscriptions.stripe_customer_id`) has to be
 * recorded in the exclusion list rather than silently skipped.
 */
class PartnerReferenceSchemaSweepTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_partner_shaped_column_is_either_guarded_or_explicitly_excluded(): void
    {
        $declared = $this->declaredColumns();
        $excluded = PartnerReferenceCounter::EXCLUDED_PARTNER_COLUMNS;
        $swept = $this->partnerShapedColumns();

        // Self-guard: a sweep that matches nothing (schema introspection
        // returning empty, a driver without getForeignKeys support) would
        // pass this test while checking nothing at all.
        $this->assertGreaterThanOrEqual(
            count($declared),
            count($swept),
            'The schema sweep found fewer partner-shaped columns than the guard declares, which '
            .'means the sweep itself is broken rather than the guard being complete.',
        );

        $unaccounted = [];

        foreach ($swept as $key) {
            if (in_array($key, $declared, true) || array_key_exists($key, $excluded)) {
                continue;
            }

            $unaccounted[] = $key;
        }

        sort($unaccounted);

        $this->assertSame(
            [],
            $unaccounted,
            'These partner-shaped columns are neither counted by the partner delete guard nor '
            ."explicitly excluded:\n  - ".implode("\n  - ", $unaccounted)."\n\n"
            .'Decide for each one. If a lingering row can still act on the partner (money, a '
            .'fiscal record, an open operation, an identity mapping), declare it on the owning '
            ."module's PartnerReferenceSource. If not, add it to "
            .'PartnerReferenceCounter::EXCLUDED_PARTNER_COLUMNS with the reason. Note that many '
            .'of these columns carry no foreign key, so nothing else in the system will stop a '
            .'soft-deleted partner from orphaning them.',
        );
    }

    public function test_no_excluded_column_is_also_declared_by_a_source(): void
    {
        $declared = $this->declaredColumns();

        foreach (array_keys(PartnerReferenceCounter::EXCLUDED_PARTNER_COLUMNS) as $key) {
            $this->assertNotContains(
                $key,
                $declared,
                "`{$key}` is listed as deliberately excluded AND declared by a source. "
                .'The two records contradict each other — drop it from '
                .'EXCLUDED_PARTNER_COLUMNS if the guard should block on it.',
            );
        }
    }

    public function test_every_excluded_column_still_exists_in_the_schema(): void
    {
        // Stops the exclusion list from rotting into a set of assertions
        // about columns that were renamed or dropped years ago — a stale
        // entry silently widens the sweep's blind spot.
        foreach (PartnerReferenceCounter::EXCLUDED_PARTNER_COLUMNS as $key => $reason) {
            [$table, $column] = explode('.', $key, 2);

            $this->assertNotSame('', trim($reason), "`{$key}` is excluded without a reason.");

            if (! Schema::hasTable($table)) {
                // Central-DB tables are not present in every schema build;
                // an absent TABLE is acceptable, an absent COLUMN on a table
                // that does exist is not.
                continue;
            }

            $this->assertTrue(
                Schema::hasColumn($table, $column),
                "`{$key}` is listed in EXCLUDED_PARTNER_COLUMNS but `{$column}` no longer exists "
                ."on `{$table}`. Remove the stale entry.",
            );
        }
    }

    public function test_soft_delete_aware_tables_are_all_still_partner_reference_tables(): void
    {
        // The allowlist is a decision about tables the guard actually reads;
        // an entry for a table nobody declares any more is dead policy.
        $declaredTables = [];

        foreach ($this->taggedSources() as $source) {
            foreach ($source->tables() as $table) {
                $declaredTables[] = $table->table;
            }
        }

        foreach (array_keys(PartnerReferenceCounter::SOFT_DELETE_AWARE_TABLES) as $table) {
            $this->assertContains(
                $table,
                $declaredTables,
                "`{$table}` is on the soft-delete allowlist but no source declares it any more.",
            );
        }
    }

    /**
     * Every `table.column` in the live schema that holds a partner id — by
     * NAME or by FOREIGN KEY.
     *
     * Both nets are needed and neither subsumes the other:
     *  - name-only would miss `party_contacts.party_id`, a real FK to
     *    `partners` under a name no convention would guess;
     *  - FK-only would miss `vouchers.partner_id`, `promotion_usages.
     *    partner_id`, `payment_instruments.partner_id` and
     *    `pos_*_receipts.customer_id`, which have no FK at all — i.e. the
     *    exact columns the R2-S gate was worried about.
     *
     * @return list<string>
     */
    private function partnerShapedColumns(): array
    {
        $matches = [];

        foreach (Schema::getTables() as $table) {
            $tableName = $table['name'];

            if ($tableName === 'partners') {
                continue;
            }

            foreach (Schema::getColumns($tableName) as $column) {
                if ($this->isPartnerShaped($column['name'])) {
                    $matches[$tableName.'.'.$column['name']] = true;
                }
            }

            foreach (Schema::getForeignKeys($tableName) as $foreignKey) {
                if (($foreignKey['foreign_table'] ?? null) !== 'partners') {
                    continue;
                }

                foreach ($foreignKey['columns'] as $column) {
                    $matches[$tableName.'.'.$column] = true;
                }
            }
        }

        $matches = array_keys($matches);
        sort($matches);

        return $matches;
    }

    private function isPartnerShaped(string $column): bool
    {
        return $column === 'partner_id'
            || $column === 'customer_id'
            || str_ends_with($column, '_partner_id')
            || str_ends_with($column, '_customer_id');
    }

    /**
     * @return list<string> `table.column` for every column any source counts
     */
    private function declaredColumns(): array
    {
        $declared = [];

        foreach ($this->taggedSources() as $source) {
            foreach ($source->tables() as $table) {
                foreach ($table->referenceColumns() as $column) {
                    $declared[] = $table->table.'.'.$column->column;
                }
            }
        }

        return $declared;
    }

    /**
     * @return list<TableBackedPartnerReferenceSource>
     */
    private function taggedSources(): array
    {
        $sources = [];

        foreach ($this->app->tagged(PartnerReferenceSource::class) as $source) {
            $this->assertInstanceOf(TableBackedPartnerReferenceSource::class, $source);
            $sources[] = $source;
        }

        return $sources;
    }
}
