<?php

declare(strict_types=1);

namespace Tests\Feature\POS\Migrations;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\POS\Application\Services\VirtualAdminTerminalResolver;
use App\Modules\POS\Domain\Enums\TerminalType;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Tests\Traits\ProvesTenantMigrationRoundTrip;

/**
 * Owner ruling B-3, 2026-08-23 —
 * `2026_08_23_120000_backfill_location_pos_enabled_b3`.
 *
 * The same deploy that starts ENFORCING `locations.pos_enabled` must first
 * repair the tenants whose rows still carry the born-false default, or every
 * existing till stops working the moment the code lands. `tenants:migrate`
 * runs unattended on push, so the migration's PREDICATE and its GUARD LADDER
 * are the contract, and both are pinned here:
 *
 *   (a) any location with a POS terminal in ANY state  -> enabled
 *   (b) a `type=shop` location that is the company's default, or its only
 *       location                                        -> enabled
 *   everything else (warehouse, office, mobile, secondary shop, all without
 *   terminals)                                          -> UNTOUCHED
 *
 * The migration is invoked directly rather than through `artisan migrate`
 * because `RefreshDatabase` has already run it against the empty schema; these
 * cases build the pre-migration data by hand and then apply it.
 */
final class BackfillLocationPosEnabledB3MigrationTest extends TestCase
{
    use ProvesTenantMigrationRoundTrip;
    use RefreshDatabase;

    private const MIGRATION = '2026_08_23_120000_backfill_location_pos_enabled_b3.php';

    private const GATE_TOKEN = 'LOCATION POS-ENABLED B3 BACKFILL MIGRATION:';

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    public function test_a_disabled_location_with_a_terminal_is_enabled(): void
    {
        $location = $this->location(['type' => 'warehouse', 'is_default' => false]);
        $this->terminalAt($location);

        $this->runMigration();

        $this->assertTrue(
            (bool) $this->fresh($location)->pos_enabled,
            'A location that already hosts a terminal must be enabled — it demonstrably sells.',
        );
    }

    public function test_a_terminal_in_any_state_counts_as_evidence(): void
    {
        // Branch (a) filters on NOTHING but `location_id`. An archived or
        // deactivated terminal is proof of the same past decision, and
        // re-activating it is precisely the flow the new refusal would block.
        $withArchived = $this->location(['type' => 'shop', 'is_default' => false, 'code' => 'ARCH']);
        $this->terminalAt($withArchived)->delete();

        $withInactive = $this->location(['type' => 'shop', 'is_default' => false, 'code' => 'INACT']);
        $this->terminalAt($withInactive, ['is_active' => false]);

        // Two locations exist besides these, so neither can be swept in by the
        // "company's only location" half of branch (b), and neither is default.
        $this->location(['type' => 'shop', 'is_default' => true, 'code' => 'HQ']);
        $this->location(['type' => 'office', 'is_default' => false, 'code' => 'OFF']);

        $this->runMigration();

        $this->assertTrue((bool) $this->fresh($withArchived)->pos_enabled, 'A soft-deleted terminal is still evidence.');
        $this->assertTrue((bool) $this->fresh($withInactive)->pos_enabled, 'A deactivated terminal is still evidence.');
    }

    /**
     * Gate r1 / P1-1. `pos_terminals` also holds SERVER-authored rows:
     * {@see VirtualAdminTerminalResolver::resolve()}
     * mints a `virtual_admin` terminal at whatever location is OLDEST for the
     * company — no `type` filter, no `pos_enabled` filter — on ordinary
     * back-office actions (RecordCustomerDepositService, CustomerAccountStatusService).
     * In the canonical warehouse-first seeder shape (DemoPharmacySeeder creates
     * WH-01 before its shops) that row sits on a WAREHOUSE, so an unfiltered
     * evidence branch would flip the warehouse on and contradict this
     * migration's own contract. It is also the one part of the lane that is not
     * re-runnable: the update is scoped `where pos_enabled = false` and never
     * writes false, so an over-enable can only be undone by hand.
     */
    public function test_a_virtual_admin_terminal_is_not_evidence_that_a_location_sells(): void
    {
        $warehouse = $this->location(['type' => 'warehouse', 'is_default' => false, 'code' => 'WH']);
        $this->terminalAt($warehouse, ['type' => TerminalType::VirtualAdmin, 'code' => 'VADMIN']);

        // A second location, so the warehouse cannot qualify via branch (b).
        $this->location(['type' => 'shop', 'is_default' => true, 'code' => 'HQ']);

        $this->runMigration();

        $this->assertFalse(
            (bool) $this->fresh($warehouse)->pos_enabled,
            'A server-minted virtual_admin terminal is a fiscal-event carrier, not a till — it is no evidence that anybody sells here.',
        );
    }

    public function test_a_web_terminal_is_still_evidence(): void
    {
        // The contrast case: narrowing branch (a) to real tills must keep BOTH
        // till types counting, not just `physical`.
        $withWeb = $this->location(['type' => 'warehouse', 'is_default' => false, 'code' => 'WH']);
        $this->terminalAt($withWeb, ['type' => TerminalType::Web]);

        $this->location(['type' => 'shop', 'is_default' => true, 'code' => 'HQ']);

        $this->runMigration();

        $this->assertTrue((bool) $this->fresh($withWeb)->pos_enabled);
    }

    public function test_a_warehouse_without_terminals_is_left_alone(): void
    {
        $warehouse = $this->location(['type' => 'warehouse', 'is_default' => false, 'code' => 'WH']);
        // A second location, so the warehouse cannot qualify as "the only one".
        $this->location(['type' => 'shop', 'is_default' => true, 'code' => 'HQ']);

        $this->runMigration();

        $this->assertFalse(
            (bool) $this->fresh($warehouse)->pos_enabled,
            'The ruling is explicit: do not enable warehouses.',
        );
    }

    public function test_an_office_and_a_mobile_location_without_terminals_are_left_alone(): void
    {
        $office = $this->location(['type' => 'office', 'is_default' => false, 'code' => 'OFF']);
        $mobile = $this->location(['type' => 'mobile', 'is_default' => false, 'code' => 'VAN']);
        $this->location(['type' => 'shop', 'is_default' => true, 'code' => 'HQ']);

        $this->runMigration();

        $this->assertFalse((bool) $this->fresh($office)->pos_enabled);
        $this->assertFalse(
            (bool) $this->fresh($mobile)->pos_enabled,
            'A delivery van may or may not take payment — without a terminal there is no evidence, so a human decides.',
        );
    }

    public function test_the_companys_default_shop_is_enabled(): void
    {
        // This is the auto-provisioned "Main Location" shape. Matched on
        // type + is_default, NOT on code — a renamed head location is the same
        // case, so the code here is deliberately not 'MAIN'.
        $main = $this->location(['type' => 'shop', 'is_default' => true, 'code' => 'SIEGE']);
        $this->location(['type' => 'warehouse', 'is_default' => false, 'code' => 'WH']);

        $this->runMigration();

        $this->assertTrue((bool) $this->fresh($main)->pos_enabled);
    }

    public function test_a_companys_only_location_is_enabled_even_when_not_flagged_default(): void
    {
        $sole = $this->location(['type' => 'shop', 'is_default' => false, 'code' => 'ONLY']);

        $this->runMigration();

        $this->assertTrue((bool) $this->fresh($sole)->pos_enabled);
    }

    public function test_a_secondary_shop_without_terminals_is_left_alone(): void
    {
        $this->location(['type' => 'shop', 'is_default' => true, 'code' => 'HQ']);
        $branch = $this->location(['type' => 'shop', 'is_default' => false, 'code' => 'BR2']);

        $this->runMigration();

        $this->assertFalse(
            (bool) $this->fresh($branch)->pos_enabled,
            'No terminal and not the primary location: there is no evidence it sells, and inventing one is a business decision.',
        );
    }

    public function test_an_already_enabled_location_is_not_rewritten(): void
    {
        $enabled = $this->location(['type' => 'shop', 'is_default' => true, 'code' => 'HQ', 'pos_enabled' => true]);
        DB::table('locations')->where('id', $enabled->id)->update(['updated_at' => now()->subYear()]);
        $before = (string) DB::table('locations')->where('id', $enabled->id)->value('updated_at');

        $this->runMigration();

        $this->assertSame(
            $before,
            (string) DB::table('locations')->where('id', $enabled->id)->value('updated_at'),
            'The update is scoped `where pos_enabled = false`, so an already-enabled row is not touched at all.',
        );
    }

    public function test_a_second_run_is_a_no_op(): void
    {
        $main = $this->location(['type' => 'shop', 'is_default' => true, 'code' => 'HQ']);
        $withTerminal = $this->location(['type' => 'warehouse', 'is_default' => false, 'code' => 'WH']);
        $this->terminalAt($withTerminal);

        $this->runMigration();

        DB::table('locations')->update(['updated_at' => now()->subYear()]);
        $before = DB::table('locations')->orderBy('id')->pluck('updated_at', 'id')->all();

        Log::spy();
        $this->runMigration();

        $this->assertSame(
            $before,
            DB::table('locations')->orderBy('id')->pluck('updated_at', 'id')->all(),
            'A re-run must not write, not even to updated_at.',
        );
        $this->assertTrue((bool) $this->fresh($main)->pos_enabled);
        $this->assertTrue((bool) $this->fresh($withTerminal)->pos_enabled);

        Log::shouldHaveReceived('warning')
            ->withArgs(static fn (string $message): bool => str_contains($message, self::GATE_TOKEN)
                && str_contains($message, 'status=ok')
                && str_contains($message, 'enabled=0'))
            ->once();
    }

    public function test_a_location_switched_off_by_hand_is_not_re_enabled_when_it_has_no_evidence(): void
    {
        // Non-destructive in the other direction: an operator who disabled a
        // secondary shop between deploys must not have it flipped back on.
        $this->location(['type' => 'shop', 'is_default' => true, 'code' => 'HQ']);
        $switchedOff = $this->location(['type' => 'shop', 'is_default' => false, 'code' => 'BR2']);

        $this->runMigration();
        $this->runMigration();

        $this->assertFalse((bool) $this->fresh($switchedOff)->pos_enabled);
    }

    public function test_the_deploy_gate_line_reports_the_repaired_count(): void
    {
        $this->location(['type' => 'shop', 'is_default' => true, 'code' => 'HQ']);
        $withTerminal = $this->location(['type' => 'warehouse', 'is_default' => false, 'code' => 'WH']);
        $this->terminalAt($withTerminal);
        $this->location(['type' => 'office', 'is_default' => false, 'code' => 'OFF']);

        Log::spy();

        $this->runMigration();

        Log::shouldHaveReceived('warning')
            ->withArgs(static fn (string $message): bool => str_contains($message, self::GATE_TOKEN)
                && str_contains($message, 'status=ok')
                && str_contains($message, 'enabled=2'))
            ->once();
    }

    /**
     * The deploy gate asserts ONE token line per tenant, because absence of the
     * token is how a tenant that died mid-run is detected. A silent early
     * return would be indistinguishable from that at the log.
     */
    public function test_the_table_guard_emits_a_skipped_gate_line_instead_of_returning_silently(): void
    {
        // RENAME, not drop: `locations` carries inbound foreign keys, so a
        // plain DROP raises 2BP01 on PostgreSQL and the case would fail for a
        // reason unrelated to the guard. A rename makes
        // `Schema::hasTable('locations')` false — exactly the tested condition.
        Schema::rename('locations', 'locations_guard_probe');

        Log::spy();

        try {
            $this->runMigration();
        } finally {
            Schema::rename('locations_guard_probe', 'locations');
        }

        Log::shouldHaveReceived('warning')
            ->withArgs(static fn (string $message): bool => str_contains($message, self::GATE_TOKEN)
                && str_contains($message, 'status=skipped')
                && ! str_contains($message, 'status=FAILED'))
            ->once();
    }

    public function test_branch_b_still_runs_when_the_pos_terminals_table_is_absent(): void
    {
        $main = $this->location(['type' => 'shop', 'is_default' => true, 'code' => 'HQ']);

        Schema::rename('pos_terminals', 'pos_terminals_guard_probe');

        Log::spy();

        try {
            $this->runMigration();
        } finally {
            Schema::rename('pos_terminals_guard_probe', 'pos_terminals');
        }

        $this->assertTrue(
            (bool) $this->fresh($main)->pos_enabled,
            'A tenant database without the POS tables has no terminals to protect, but its Main Location must still match a tenant registered today.',
        );

        Log::shouldHaveReceived('warning')
            ->withArgs(static fn (string $message): bool => str_contains($message, self::GATE_TOKEN)
                && str_contains($message, 'status=ok')
                && str_contains($message, 'terminals-table-absent'))
            ->once();
    }

    /**
     * On PostgreSQL, catching the exception is not enough to keep the "one
     * tenant must not brick the whole run" promise: the failed statement aborts
     * the ENCLOSING transaction `migrate` wraps every migration in, so every
     * later statement — including the migration repository's bookkeeping INSERT
     * — fails with "current transaction is aborted". The repair must run inside
     * a SAVEPOINT that can be rolled back on its own.
     */
    public function test_a_failing_backfill_does_not_poison_the_enclosing_migration_transaction(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped(
                'Only PostgreSQL aborts the enclosing transaction after a failed statement; '
                .'this is the production driver and the hazard is PG-specific.',
            );
        }

        $this->location(['type' => 'shop', 'is_default' => true, 'code' => 'HQ']);

        // The table guard passes (`locations` still exists) but the update's
        // own predicate cannot be compiled — the failure lands INSIDE the
        // savepoint, which is the point of the case.
        DB::statement('ALTER TABLE locations DROP COLUMN is_default');

        Log::spy();

        $this->runMigration();

        $this->assertSame(
            1,
            DB::table('companies')->where('id', $this->company->id)->count(),
            'The enclosing transaction must survive a failed backfill.',
        );

        Log::shouldHaveReceived('error')
            ->withArgs(static fn (string $message): bool => str_contains($message, self::GATE_TOKEN)
                && str_contains($message, 'status=FAILED'))
            ->once();
    }

    /**
     * `down()` is a DECLARED no-op: the rows this migration touched are
     * afterwards indistinguishable from rows an operator enabled by hand, and a
     * destructive `down()` would take those tills offline too.
     */
    public function test_the_migration_is_an_idempotent_irreversible_no_op(): void
    {
        $main = $this->location(['type' => 'shop', 'is_default' => true, 'code' => 'HQ']);
        $warehouse = $this->location(['type' => 'warehouse', 'is_default' => false, 'code' => 'WH']);

        $this->assertTenantMigrationIsIrreversibleNoOp(
            self::MIGRATION,
            function (string $context) use ($main, $warehouse): void {
                $this->assertTrue((bool) $this->fresh($main)->pos_enabled, "default shop enabled {$context}");
                $this->assertFalse((bool) $this->fresh($warehouse)->pos_enabled, "warehouse untouched {$context}");
            },
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function location(array $attributes): Location
    {
        return Location::factory()->create(array_merge([
            'company_id' => $this->company->id,
            'pos_enabled' => false,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function terminalAt(Location $location, array $attributes = []): Terminal
    {
        return Terminal::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $location->id,
        ], $attributes));
    }

    private function fresh(Location $location): Location
    {
        return Location::query()->findOrFail($location->id);
    }

    private function runMigration(): void
    {
        [$migration] = $this->requireTenantMigrations(self::MIGRATION);

        $migration->up();
    }
}
