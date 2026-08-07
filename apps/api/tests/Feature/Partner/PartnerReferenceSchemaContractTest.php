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
 * The guard that makes the partner delete guard's schema dependency a
 * GUARDED one.
 *
 * The L6 merge gate accepted `PartnerReferenceCounter`'s query-builder reads
 * (house precedent: `Fiscal\...\OutboxIngestor` reads `pos_terminals` the
 * same way) but ruled that widening the sweep trades a compile-time
 * dependency for an UNGUARDED schema dependency — rename a column in the
 * owning module and the delete guard silently stops guarding, with nothing
 * failing. Lane R2-S answers that on two axes:
 *
 *  1. Ownership: every table is declared by the module that owns it
 *     (`PartnerReferenceSource` implementations), not by the Partner module.
 *  2. THIS TEST: every declared table and column is checked against the
 *     real migrated schema. A rename or a drop turns the silent failure
 *     into a red test that names the table and the column.
 *
 * It also pins the soft-delete flag in both directions, because both
 * mistakes are silent in production: a table that soft-deletes but is not
 * flagged over-blocks (deleted history vetoes the partner forever), and a
 * flagged table without `deleted_at` would throw at runtime — inside a
 * DELETE endpoint, which is the worst place to find out.
 */
class PartnerReferenceSchemaContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_declared_partner_reference_table_exists_in_the_schema(): void
    {
        foreach ($this->taggedSources() as $source) {
            foreach ($source->tables() as $table) {
                $this->assertTrue(
                    Schema::hasTable($table->table),
                    $source::class.' declares table `'.$table->table.'`, which does not exist. '
                    .'Either the table was renamed/dropped without updating the source, or the '
                    .'source has a typo — the partner delete guard would 500 on every delete.',
                );
            }
        }
    }

    public function test_every_declared_partner_column_exists_on_its_table(): void
    {
        foreach ($this->taggedSources() as $source) {
            foreach ($source->tables() as $table) {
                foreach ($table->referenceColumns() as $column) {
                    $this->assertTrue(
                        Schema::hasColumn($table->table, $column->column),
                        $source::class.' declares `'.$table->table.'.'.$column->column.'`, which does not '
                        .'exist. A renamed column makes the guard silently count nothing and lets '
                        .'partners with live references be deleted.',
                    );

                    // A polymorphic anchor's discriminator is load-bearing:
                    // if it is renamed the count query throws inside DELETE.
                    foreach (array_keys($column->where) as $guardColumn) {
                        $this->assertTrue(
                            Schema::hasColumn($table->table, $guardColumn),
                            $source::class.' guards `'.$table->table.'.'.$column->column.'` on `'
                            .$guardColumn.'`, which does not exist on that table.',
                        );
                    }
                }
            }
        }
    }

    public function test_soft_delete_flags_match_the_real_schema(): void
    {
        foreach ($this->taggedSources() as $source) {
            foreach ($source->tables() as $table) {
                $hasDeletedAt = Schema::hasColumn($table->table, 'deleted_at');

                $this->assertSame(
                    $hasDeletedAt,
                    $table->hasSoftDeletes,
                    $source::class.' declares `'.$table->table.'` with hasSoftDeletes='
                    .var_export($table->hasSoftDeletes, true).' but the table '
                    .($hasDeletedAt ? 'HAS' : 'does NOT have').' a `deleted_at` column. '
                    .'Flag it correctly: a missing flag makes soft-deleted history block the '
                    .'partner forever; a spurious flag makes the guard throw at delete time.',
                );
            }
        }
    }

    public function test_ignoring_soft_deleted_rows_requires_an_explicit_allowlist_entry(): void
    {
        // R2-S treasury m-1 — the biconditional above is a trap on its own.
        // The day a MONEY table (payments, payment_instruments, journal_lines,
        // the pos_*_receipts family) gains a `deleted_at` column, that rule
        // alone would invite flipping `hasSoftDeletes` to true just to keep
        // the suite green — silently weakening the guard, because
        // soft-deleted money rows would stop blocking the delete.
        //
        // So the weakening direction has to be allowlisted explicitly: a
        // table may only stop counting its soft-deleted rows if someone has
        // written down why that is safe.
        foreach ($this->taggedSources() as $source) {
            foreach ($source->tables() as $table) {
                if (! $table->hasSoftDeletes) {
                    continue;
                }

                $this->assertArrayHasKey(
                    $table->table,
                    PartnerReferenceCounter::SOFT_DELETE_AWARE_TABLES,
                    $source::class.' declares `'.$table->table.'` with hasSoftDeletes: true, so '
                    .'soft-deleted rows there no longer block a partner delete. That is a '
                    .'deliberate weakening of the guard and must be recorded in '
                    .'PartnerReferenceCounter::SOFT_DELETE_AWARE_TABLES with the reason it is '
                    .'safe. If this table carries money or a fiscal record, the answer is '
                    .'probably to leave the flag false and keep counting.',
                );

                $this->assertNotSame(
                    '',
                    trim(PartnerReferenceCounter::SOFT_DELETE_AWARE_TABLES[$table->table]),
                    '`'.$table->table.'` is on the soft-delete allowlist without a reason.',
                );
            }
        }
    }

    public function test_each_source_declares_exactly_the_tables_its_module_owns(): void
    {
        // Owning-side pin (R2-S authz m-1). The covered-list test in
        // DeletePartnerReferenceGuardTest catches a table entering or leaving
        // the guard, but it reports it as one anonymous set difference — and
        // the tempting "fix" is to edit the list. This names the SOURCE, so a
        // module quietly dropping one of its own tables fails as that
        // module's regression rather than as a bookkeeping mismatch.
        $expected = [
            'DocumentPartnerReferenceSource' => ['documents'],
            'TreasuryPartnerReferenceSource' => ['payments', 'payment_instruments'],
            'PosPartnerReferenceSource' => [
                'pos_receipts',
                'pos_orders',
                'pos_customer_aliases',
                'pos_deposit_receipts',
                'pos_account_charge_receipts',
                'pos_account_payment_receipts',
            ],
            'AccountingPartnerReferenceSource' => ['journal_lines'],
            'VoucherPartnerReferenceSource' => ['vouchers'],
            'WorkshopPartnerReferenceSource' => ['workshop_work_orders', 'workshop_work_order_lines'],
            'SchedulingPartnerReferenceSource' => ['scheduling_appointments'],
            'TaxationPartnerReferenceSource' => ['withholding_certificates', 'sales_withholding_tracking'],
            'PromotionPartnerReferenceSource' => ['promotion_usages'],
            'CouponPartnerReferenceSource' => ['coupon_usages'],
            'VehiclePartnerReferenceSource' => ['vehicles', 'vehicle_ownership_history'],
            'LoyaltyPartnerReferenceSource' => ['loyalty_members'],
            'ExpensePartnerReferenceSource' => ['expense_recurrence_templates'],
            'MarketplacePartnerReferenceSource' => ['buyer_seller_mappings'],
            'PlatformIntegrationPartnerReferenceSource' => ['platform_supplier_mappings'],
        ];

        $actual = [];

        foreach ($this->taggedSources() as $source) {
            $shortName = class_basename($source);

            $actual[$shortName] = array_map(
                static fn ($table): string => $table->table,
                $source->tables(),
            );

            sort($actual[$shortName]);
        }

        foreach ($expected as $shortName => $tables) {
            sort($tables);
            $expected[$shortName] = $tables;
        }

        ksort($expected);
        ksort($actual);

        $this->assertSame($expected, $actual);
    }

    public function test_the_partners_table_itself_is_never_declared_as_a_reference(): void
    {
        // A source declaring `partners` would make every partner block its
        // own deletion (its own row matches on `id`... or worse, silently
        // match nothing on a `partner_id` column that does not exist there).
        foreach ($this->taggedSources() as $source) {
            foreach ($source->tables() as $table) {
                $this->assertNotSame('partners', $table->table, $source::class.' declares the `partners` table itself.');
            }
        }
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

        $this->assertNotSame([], $sources, 'No PartnerReferenceSource is tagged — the partner delete guard would allow every delete.');

        return $sources;
    }
}
