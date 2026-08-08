<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementReasonCode;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
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

    private const JE_UNIQUE_MIGRATION = '2026_08_08_120100_unique_journal_entries_source_repository_adjustment.php';

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
                        // Gate I2 — shipped WITH the table so the G3
                        // shift-variance lane never has to ALTER a frozen table.
                        'pos_shift_id',
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

    /**
     * Gate I1 — the partial unique index guarding one POSTED journal entry per
     * repository adjustment round-trips, and its `up()` is `IF NOT EXISTS` so a
     * re-run of a partially applied `tenants:migrate` batch is a no-op.
     */
    public function test_journal_entry_uniqueness_migration_round_trips(): void
    {
        $this->assertTenantMigrationRoundTrips(
            self::JE_UNIQUE_MIGRATION,
            function (string $context): void {
                $this->assertTrue(
                    $this->indexExists('journal_entries_repository_adjustment_source_unique'),
                    "the partial unique index must exist {$context}",
                );
            },
            function (string $context): void {
                $this->assertFalse(
                    $this->indexExists('journal_entries_repository_adjustment_source_unique'),
                    "the partial unique index must be gone {$context}",
                );
            },
        );

        // Idempotent re-run (IF NOT EXISTS) — the unattended-deploy guard.
        [$migration] = $this->requireTenantMigrations(self::JE_UNIQUE_MIGRATION);
        $migration->up();
        $this->assertTrue($this->indexExists('journal_entries_repository_adjustment_source_unique'));
    }

    /**
     * Gate C2 / I4 — the pgsql-only CHECK block is invisible to the sqlite fast
     * loop, which is precisely how the `amount > 0` truncation defect reached a
     * 500 in the first place. Pin the constraints that DO exist, and pin the
     * absence of the `reason_code` CHECK that was removed so a future
     * `MovementReasonCode` case cannot 23514 in production while every sqlite
     * test stays green.
     */
    public function test_pgsql_check_constraints_are_exactly_amount_and_direction(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('CHECK constraints on this table are pgsql-only DDL.');
        }

        /** @var list<object{conname: string}> $constraints */
        $constraints = DB::select(<<<'SQL'
            SELECT conname
            FROM pg_constraint
            WHERE conrelid = 'repository_adjustments'::regclass AND contype = 'c'
            ORDER BY conname
        SQL);

        $names = array_map(static fn (object $row): string => $row->conname, $constraints);

        $this->assertContains('repository_adjustments_amount_positive', $names);
        $this->assertContains('repository_adjustments_direction_valid', $names);
        $this->assertNotContains(
            'repository_adjustments_reason_code_valid',
            $names,
            'the reason_code CHECK was deliberately removed (gate I4) — the sibling repository_movements leaves reason_code unconstrained, and a hardcoded vocabulary would 23514 on a new enum case that every sqlite test passes.',
        );
    }

    /**
     * Gate I4 — every `MovementReasonCode` case must actually be storable on
     * PostgreSQL, which is the guarantee the removed CHECK was silently
     * jeopardising. Runs the real enum, so adding a case extends the proof
     * automatically.
     */
    public function test_every_reason_code_case_is_storable_on_pgsql(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The vocabulary drift this guards against is pgsql-only.');
        }

        // `payment_repository_id` is NOT NULL with a real FK, so the probe needs
        // a real repository (and therefore a real company — `companies` is a
        // tenant-database table; `tenant_id` stays a plain UUID by the T6
        // Phase 0b no-cross-database-FK rule).
        $company = Company::create([
            'tenant_id' => (string) Str::uuid(),
            'name' => 'Vocabulary Probe',
            'legal_name' => 'Vocabulary Probe LLC',
            'tax_id' => 'TAX-PROBE',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        $repository = PaymentRepository::factory()->create([
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
            'currency' => 'TND',
            'type' => RepositoryType::CashRegister,
        ]);

        foreach (MovementReasonCode::cases() as $case) {
            DB::table('repository_adjustments')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $company->tenant_id,
                'company_id' => $company->id,
                'payment_repository_id' => $repository->id,
                'direction' => MovementDirection::Out->value,
                'amount' => '1.000',
                'currency' => 'TND',
                'reason_code' => $case->value,
                'reason_text' => "vocabulary probe: {$case->value}",
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->assertSame(
            count(MovementReasonCode::cases()),
            DB::table('repository_adjustments')->count(),
        );
    }

    private function indexExists(string $name): bool
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            return DB::select('SELECT 1 FROM pg_indexes WHERE indexname = ?', [$name]) !== [];
        }

        return DB::select('SELECT 1 FROM sqlite_master WHERE type = ? AND name = ?', ['index', $name]) !== [];
    }
}
