<?php

declare(strict_types=1);

namespace Tests\Feature\Partner;

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
                foreach ($table->columns as $column) {
                    $this->assertTrue(
                        Schema::hasColumn($table->table, $column),
                        $source::class.' declares `'.$table->table.'.'.$column.'`, which does not '
                        .'exist. A renamed column makes the guard silently count nothing and lets '
                        .'partners with live references be deleted.',
                    );
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
