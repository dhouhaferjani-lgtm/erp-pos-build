<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\HeldOrderStatus;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Q-8 — `pos_held_orders` schema hardening
 * (`2026_08_23_163000_harden_pos_held_orders_status_and_discard`).
 *
 * Pre-Q-8 the column was `$table->string('status', 20)->default('held')` with
 * no constraint of any kind, while `HeldOrderStatus` has exactly three cases —
 * so a typo'd or hand-written status was accepted by the database forever.
 * Contrast `pos_shifts_status`, which has had a CHECK since it was created.
 *
 * The CHECK is pgsql-only (the enum-constraint assertions call
 * `requirePostgres()`), but the column assertions are driver-agnostic on
 * purpose: they also pin that the SQLite fast loop receives `deleted_at` and
 * `discarded_by`, which the soft-delete path in `HeldOrderService` depends on.
 *
 * Run the PostgreSQL half with:
 *   DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5433 \
 *     DB_DATABASE=autoerp_test_sbq8 DB_CENTRAL_DATABASE=autoerp_test_sbq8 \
 *     DB_USERNAME=autoerp DB_PASSWORD=autoerp_secret \
 *     ./vendor/bin/phpunit -c phpunit-pgsql.xml tests/Feature/POS/PosHeldOrdersStatusCheckTest.php
 */
final class PosHeldOrdersStatusCheckTest extends TestCase
{
    use RefreshDatabase;

    private const CHECK = 'pos_held_orders_status_check';

    private string $tenantId;

    private string $companyId;

    private string $terminalId;

    private string $shiftId;

    private string $cashierId;

    private function requirePostgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped(self::CHECK.' is a pgsql-only CHECK constraint.');
        }
    }

    public function test_soft_delete_and_actor_columns_exist_on_every_driver(): void
    {
        $this->assertTrue(Schema::hasColumn('pos_held_orders', 'deleted_at'));
        $this->assertTrue(Schema::hasColumn('pos_held_orders', 'discarded_by'));
    }

    public function test_check_constraint_exists(): void
    {
        $this->requirePostgres();

        $rows = DB::select(
            'SELECT conname FROM pg_constraint WHERE conname = ? AND contype = \'c\'',
            [self::CHECK],
        );

        $this->assertCount(1, $rows, self::CHECK.' is missing from pos_held_orders.');
    }

    public function test_check_accepts_every_enum_case(): void
    {
        $this->requirePostgres();
        $this->seedFixtures();

        foreach (HeldOrderStatus::cases() as $case) {
            $id = (string) Str::uuid();
            $this->insertHeldOrder($id, $case->value);

            $this->assertDatabaseHas('pos_held_orders', ['id' => $id, 'status' => $case->value]);
        }
    }

    public function test_check_rejects_an_out_of_enum_status(): void
    {
        $this->requirePostgres();
        $this->seedFixtures();

        $this->expectException(QueryException::class);

        $this->insertHeldOrder((string) Str::uuid(), 'parked');
    }

    /**
     * The CHECK is generated from `HeldOrderStatus::cases()`, so adding a case
     * to the enum without a follow-up migration is a visible failure here
     * rather than a silent divergence between the enum and the column.
     */
    public function test_check_definition_is_derived_from_the_enum(): void
    {
        $this->requirePostgres();

        /** @var list<object{definition: string}> $rows */
        $rows = DB::select(
            'SELECT pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conname = ?',
            [self::CHECK],
        );

        $this->assertCount(1, $rows);
        $definition = (string) $rows[0]->definition;

        foreach (HeldOrderStatus::cases() as $case) {
            $this->assertStringContainsString(
                "'".$case->value."'",
                $definition,
                'CHECK does not cover HeldOrderStatus::'.$case->name,
            );
        }

        // Exactly the enum, nothing else: three quoted literals, no strays.
        $this->assertSame(
            count(HeldOrderStatus::cases()),
            substr_count($definition, "'::"),
            'CHECK covers a different number of values than the enum: '.$definition,
        );
    }

    /**
     * MIGRATION-BEARING — the pre-flight scan must abort the tenant's
     * `tenants:migrate` with the census result, not let PostgreSQL reject the
     * ALTER with an opaque "check constraint ... is violated by some row".
     *
     * Staged by dropping the CHECK, planting an out-of-enum row the way a
     * manual UPDATE on a live tenant would, and re-running the migration's
     * `up()` (which is re-entrant for exactly this operator loop).
     */
    public function test_pre_flight_scan_aborts_on_out_of_enum_rows(): void
    {
        $this->requirePostgres();
        $this->seedFixtures();

        DB::statement('ALTER TABLE pos_held_orders DROP CONSTRAINT '.self::CHECK);
        $this->insertHeldOrder((string) Str::uuid(), 'parked');
        $this->insertHeldOrder((string) Str::uuid(), 'parked');
        $this->insertHeldOrder((string) Str::uuid(), 'PENDING');

        $migration = require __DIR__.'/../../../database/migrations/tenant/2026_08_23_163000_harden_pos_held_orders_status_and_discard.php';

        try {
            $migration->up();
            $this->fail('Expected the pre-flight scan to abort.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('out-of-enum status values', $e->getMessage());
            $this->assertStringContainsString('parked=2', $e->getMessage());
            $this->assertStringContainsString('PENDING=1', $e->getMessage());
        }

        // Aborted BEFORE any DDL: the constraint is still absent, so the rest
        // of the fleet is unaffected and this tenant can be remediated and
        // re-run.
        $this->assertCount(0, DB::select('SELECT 1 FROM pg_constraint WHERE conname = ?', [self::CHECK]));

        // Documented remediation, then a clean re-run.
        DB::table('pos_held_orders')
            ->whereNotIn('status', array_column(HeldOrderStatus::cases(), 'value'))
            ->update(['status' => HeldOrderStatus::Expired->value]);

        $migration->up();

        $this->assertCount(1, DB::select('SELECT 1 FROM pg_constraint WHERE conname = ?', [self::CHECK]));
    }

    /**
     * Q-8 fix round — `down()` must be guarded symmetrically with `up()`.
     *
     * `up()` adds `discarded_by` / `deleted_at` only when they are absent, and
     * the docblock claims the migration is re-entrant. `down()` used to drop
     * the index and both columns unconditionally, so a rollback on a tenant
     * where the adds had been skipped (or a second rollback) threw. Rolling
     * back twice must be a no-op the second time.
     */
    public function test_down_is_symmetric_with_up_and_can_run_twice(): void
    {
        $migration = require __DIR__.'/../../../database/migrations/tenant/2026_08_23_163000_harden_pos_held_orders_status_and_discard.php';

        $migration->down();

        $this->assertFalse(Schema::hasColumn('pos_held_orders', 'deleted_at'));
        $this->assertFalse(Schema::hasColumn('pos_held_orders', 'discarded_by'));

        // Second rollback: the columns are already gone, and this must not throw.
        $migration->down();

        $this->assertFalse(Schema::hasColumn('pos_held_orders', 'deleted_at'));

        // Restore the schema so the rest of the run is unaffected.
        $migration->up();

        $this->assertTrue(Schema::hasColumn('pos_held_orders', 'deleted_at'));
        $this->assertTrue(Schema::hasColumn('pos_held_orders', 'discarded_by'));
    }

    private function insertHeldOrder(string $id, ?string $status): void
    {
        DB::table('pos_held_orders')->insert([
            'id' => $id,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'terminal_id' => $this->terminalId,
            'shift_id' => $this->shiftId,
            'cashier_id' => $this->cashierId,
            'label' => 'CHECK probe',
            'cart_snapshot' => json_encode(['lines' => []], JSON_THROW_ON_ERROR),
            'status' => $status,
            'held_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedFixtures(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $location = Location::factory()->create(['company_id' => $company->id]);
        $terminal = Terminal::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
        ]);
        $shift = Shift::create([
            'terminal_id' => $terminal->id,
            'cashier_id' => $user->id,
            'shift_number' => 1,
            'opening_cash' => '100.000',
            'status' => ShiftStatus::Open,
            'opened_at' => now(),
        ]);

        $this->tenantId = $tenant->id;
        $this->companyId = $company->id;
        $this->terminalId = $terminal->id;
        $this->shiftId = $shift->id;
        $this->cashierId = $user->id;
    }
}
