<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Tests\Traits\ProvesTenantMigrationRoundTrip;

/**
 * DPA lane V3 — schema proof for the `repository_adjustments` document table.
 *
 * The migration ships on a branch that auto-deploys `tenants:migrate` on push
 * to origin/dev with NO manual prerequisite, so it must be unattended-safe:
 * self-guarding on re-run, and cleanly reversible.
 */
final class RepositoryAdjustmentsSchemaTest extends TestCase
{
    use ProvesTenantMigrationRoundTrip;
    use RefreshDatabase;

    private const MIGRATION = '2026_08_08_120000_create_repository_adjustments_table.php';

    public function test_repository_adjustments_migration_round_trips(): void
    {
        $this->assertTenantMigrationRoundTrips(
            self::MIGRATION,
            function (string $context): void {
                $this->assertTrue(
                    Schema::hasTable('repository_adjustments'),
                    "repository_adjustments must exist {$context}",
                );
                $this->assertTrue(
                    Schema::hasColumns('repository_adjustments', [
                        'id',
                        'tenant_id',
                        'company_id',
                        'payment_repository_id',
                        'direction',
                        'amount',
                        'currency',
                        'reason_code',
                        'reason_text',
                        'journal_entry_id',
                        'movement_id',
                        'created_by',
                        'created_at',
                        'updated_at',
                    ]),
                    "repository_adjustments must carry the full document shape {$context}",
                );
            },
            function (string $context): void {
                $this->assertFalse(
                    Schema::hasTable('repository_adjustments'),
                    "repository_adjustments must be gone {$context}",
                );
            },
        );
    }

    /**
     * Unattended-safety: `tenants:migrate` re-running a partially applied batch
     * must no-op on an already-created table, not throw "relation already
     * exists". The baseline migrate has already applied it, so calling `up()`
     * again here is exactly that re-run.
     */
    public function test_migration_up_is_a_no_op_when_the_table_already_exists(): void
    {
        [$migration] = $this->requireTenantMigrations(self::MIGRATION);

        $migration->up();

        $this->assertTrue(Schema::hasTable('repository_adjustments'));
    }
}
