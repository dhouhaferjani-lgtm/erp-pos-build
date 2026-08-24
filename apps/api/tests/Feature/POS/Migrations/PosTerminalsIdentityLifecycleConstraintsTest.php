<?php

declare(strict_types=1);

namespace Tests\Feature\POS\Migrations;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\POS\Domain\Enums\TerminalType;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\ProvesTenantMigrationRoundTrip;

/**
 * Session B lane Q-7 — the DB backstops on `pos_terminals`
 * (`2026_08_23_140000_harden_pos_terminals_identity_and_lifecycle`).
 *
 * Sweep finding #25 observed that `pos_terminals` — which carries the NF525
 * hash-chain head — had none of the constraints its same-day sibling
 * `pos_shifts` was given (`pos_shifts_status`, `pos_shifts_closed_logic`,
 * `pos_shifts_one_open_per_terminal`). Three legs close that:
 *
 *   1. `pos_terminals_unique_hardware_identifier` — partial UNIQUE on
 *      (tenant_id, company_id, hardware_identifier) WHERE the identifier is
 *      set and the row is not soft-deleted. Native to pgsql AND sqlite, so it
 *      is created on both and tested on both.
 *   2. `pos_terminals_type` — CHECK whitelisting the `TerminalType` cases.
 *   3. `pos_terminals_active_logic` — the state invariant on
 *      `is_active`/`deactivated_at`/`deactivation_reason`.
 *
 * Legs 2 and 3 are `ALTER TABLE … ADD CONSTRAINT`, which sqlite cannot do, so
 * those cases skip on the default harness and run on PG (`DB_PORT=5433`).
 *
 * The MIGRATION'S OWN GUARD LADDER is pinned here too. `tenants:migrate` runs
 * unattended on push across every tenant database; all three legs CAN fail on
 * pre-existing rows, so each carries a pre-flight violation scan and, on a hit,
 * REPORTS and skips that leg rather than throwing. Throwing would abort the
 * tenant's entire migration run and block every later migration for that tenant
 * — a worse outcome than a missing backstop on a hole the application layer now
 * closes anyway.
 */
final class PosTerminalsIdentityLifecycleConstraintsTest extends TestCase
{
    use ProvesTenantMigrationRoundTrip;
    use RefreshDatabase;

    private const MIGRATION = '2026_08_23_140000_harden_pos_terminals_identity_and_lifecycle.php';

    private const GATE_TOKEN = 'POS TERMINAL IDENTITY/LIFECYCLE HARDENING:';

    private const UNIQUE_INDEX = 'pos_terminals_unique_hardware_identifier';

    private const TYPE_CHECK = 'pos_terminals_type';

    private const LIFECYCLE_CHECK = 'pos_terminals_active_logic';

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->location = Location::factory()->create([
            'company_id' => $this->company->id,
            'type' => 'shop',
            'pos_enabled' => true,
        ]);
    }

    // ------------------------------------------- leg 1: partial unique index

    public function test_two_live_terminals_in_one_company_cannot_share_a_hardware_identifier(): void
    {
        $this->insertTerminal(['code' => 'POS01', 'hardware_identifier' => 'HW-SHARED']);

        $this->expectException(QueryException::class);

        $this->insertTerminal(['code' => 'POS02', 'hardware_identifier' => 'HW-SHARED']);
    }

    public function test_unclaimed_terminals_are_exempt_from_the_unique_index(): void
    {
        $this->insertTerminal(['code' => 'POS01', 'hardware_identifier' => null]);
        $this->insertTerminal(['code' => 'POS02', 'hardware_identifier' => null]);
        $this->insertTerminal(['code' => 'POS03', 'hardware_identifier' => null]);

        $this->assertSame(3, DB::table('pos_terminals')->whereNull('hardware_identifier')->count());
    }

    public function test_an_archived_terminal_does_not_block_re_registering_its_hardware(): void
    {
        // Archiving is a soft delete (`TerminalController::archive`). The
        // predicate excludes soft-deleted rows precisely so a replaced till can
        // be re-registered without first purging its history.
        $archived = $this->insertTerminal(['code' => 'POS01', 'hardware_identifier' => 'HW-REUSED']);
        DB::table('pos_terminals')->where('id', $archived)->update(['deleted_at' => now()]);

        $this->insertTerminal(['code' => 'POS02', 'hardware_identifier' => 'HW-REUSED']);

        $this->assertSame(
            1,
            DB::table('pos_terminals')
                ->where('hardware_identifier', 'HW-REUSED')
                ->whereNull('deleted_at')
                ->count(),
        );
    }

    public function test_two_companies_may_register_the_same_hardware_identifier(): void
    {
        // Pinned behaviour: TerminalDeviceLookupTest already asserts that a
        // same-hardware row in another company is invisible to this company's
        // lookup, so the index must be company-scoped and not tenant-wide.
        $other = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $otherLocation = Location::factory()->create(['company_id' => $other->id, 'type' => 'shop']);

        $this->insertTerminal(['code' => 'POS01', 'hardware_identifier' => 'HW-TWO-CO']);
        $this->insertTerminal([
            'code' => 'POS01',
            'hardware_identifier' => 'HW-TWO-CO',
            'company_id' => $other->id,
            'location_id' => $otherLocation->id,
        ]);

        $this->assertSame(2, DB::table('pos_terminals')->where('hardware_identifier', 'HW-TWO-CO')->count());
    }

    // ----------------------------------------------------- leg 2: type CHECK

    public function test_the_type_check_rejects_a_value_outside_the_enum(): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        $this->insertTerminal(['code' => 'POS01', 'type' => 'kiosk']);
    }

    public function test_the_type_check_admits_every_declared_terminal_type(): void
    {
        $this->requirePostgres();

        $code = 0;
        foreach (TerminalType::cases() as $case) {
            $this->insertTerminal([
                'code' => 'POS1'.$code++,
                'type' => $case->value,
            ]);
        }

        $this->assertSame(count(TerminalType::cases()), DB::table('pos_terminals')->count());
    }

    // ------------------------------------------------ leg 3: lifecycle CHECK

    public function test_an_active_terminal_cannot_carry_a_deactivation_record(): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        $this->insertTerminal([
            'code' => 'POS01',
            'is_active' => true,
            'deactivated_at' => now(),
            'deactivation_reason' => 'impossible',
        ]);
    }

    public function test_a_deactivation_reason_without_a_deactivation_timestamp_is_rejected(): void
    {
        $this->requirePostgres();

        $this->expectException(QueryException::class);

        $this->insertTerminal([
            'code' => 'POS01',
            'is_active' => false,
            'deactivated_at' => null,
            'deactivation_reason' => 'reason with no moment',
        ]);
    }

    /**
     * `requestTerminal()` creates a terminal with `is_active = false` and NO
     * deactivation record — it has never been activated. That state is legal
     * and the invariant must not outlaw it (which is why the CHECK is not the
     * naive `is_active = false ⇒ deactivated_at IS NOT NULL`).
     */
    public function test_a_requested_but_never_activated_terminal_is_legal(): void
    {
        $this->requirePostgres();

        $this->insertTerminal([
            'code' => 'POS01',
            'is_active' => false,
            'deactivated_at' => null,
            'deactivation_reason' => null,
        ]);

        $this->assertSame(1, DB::table('pos_terminals')->count());
    }

    public function test_a_deactivated_terminal_is_legal_including_the_empty_reason_the_controller_writes(): void
    {
        $this->requirePostgres();

        // `TerminalController::deactivate` defaults the reason to '' when the
        // caller omits it, so the empty string must remain acceptable.
        $this->insertTerminal([
            'code' => 'POS01',
            'is_active' => false,
            'deactivated_at' => now(),
            'deactivation_reason' => '',
        ]);

        $this->insertTerminal([
            'code' => 'POS02',
            'is_active' => false,
            'deactivated_at' => now(),
            'deactivation_reason' => 'RMA 4471',
        ]);

        $this->assertSame(2, DB::table('pos_terminals')->count());
    }

    public function test_the_activate_deactivate_round_trip_satisfies_the_invariant(): void
    {
        $this->requirePostgres();

        $id = $this->insertTerminal(['code' => 'POS01', 'is_active' => true]);

        DB::table('pos_terminals')->where('id', $id)->update([
            'is_active' => false,
            'deactivated_at' => now(),
            'deactivation_reason' => 'seasonal close',
        ]);

        // `activate()` clears BOTH deactivation columns — if it ever stopped
        // doing so, this UPDATE would start failing, which is the point.
        DB::table('pos_terminals')->where('id', $id)->update([
            'is_active' => true,
            'activated_at' => now(),
            'deactivated_at' => null,
            'deactivation_reason' => null,
        ]);

        $this->assertTrue((bool) DB::table('pos_terminals')->where('id', $id)->value('is_active'));
    }

    // -------------------------------------------------- the migration itself

    public function test_the_migration_round_trips(): void
    {
        $this->assertTenantMigrationRoundTrips(
            self::MIGRATION,
            function (string $context): void {
                $this->assertTrue($this->indexExists(self::UNIQUE_INDEX), "unique index missing {$context}");
                if ($this->onPostgres()) {
                    $this->assertTrue($this->constraintExists(self::TYPE_CHECK), "type CHECK missing {$context}");
                    $this->assertTrue($this->constraintExists(self::LIFECYCLE_CHECK), "lifecycle CHECK missing {$context}");
                }
            },
            function (string $context): void {
                $this->assertFalse($this->indexExists(self::UNIQUE_INDEX), "unique index still present {$context}");
                if ($this->onPostgres()) {
                    $this->assertFalse($this->constraintExists(self::TYPE_CHECK), "type CHECK still present {$context}");
                    $this->assertFalse($this->constraintExists(self::LIFECYCLE_CHECK), "lifecycle CHECK still present {$context}");
                }
            },
        );
    }

    /**
     * THE FLEET-ABORT GUARANTEE. A tenant that already holds two live terminals
     * on one hardware identifier must not have its migration run killed: the
     * leg reports `status=BLOCKED` and is skipped, the other legs still apply,
     * and `up()` returns normally.
     */
    public function test_pre_existing_duplicate_hardware_blocks_only_its_own_leg_and_never_throws(): void
    {
        [$migration] = $this->requireTenantMigrations(self::MIGRATION);
        $migration->down();

        $this->insertTerminal(['code' => 'POS01', 'hardware_identifier' => 'HW-LEGACY-DUP']);
        $this->insertTerminal(['code' => 'POS02', 'hardware_identifier' => 'HW-LEGACY-DUP']);

        Log::spy();

        $migration->up();

        $this->assertFalse(
            $this->indexExists(self::UNIQUE_INDEX),
            'The unique index must be skipped, not forced, when live duplicates exist.',
        );

        if ($this->onPostgres()) {
            $this->assertTrue(
                $this->constraintExists(self::TYPE_CHECK),
                'One blocked leg must not prevent the other legs from applying.',
            );
            $this->assertTrue($this->constraintExists(self::LIFECYCLE_CHECK));
        }

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message): bool => str_contains($message, self::GATE_TOKEN)
                && str_contains($message, 'status=BLOCKED')
                && str_contains($message, 'leg=unique-hardware-identifier'))
            ->atLeast()->once();
    }

    public function test_a_pre_existing_out_of_enum_type_blocks_only_the_type_leg(): void
    {
        $this->requirePostgres();

        [$migration] = $this->requireTenantMigrations(self::MIGRATION);
        $migration->down();

        $this->insertTerminal(['code' => 'POS01', 'type' => 'kiosk']);

        Log::spy();

        $migration->up();

        $this->assertFalse($this->constraintExists(self::TYPE_CHECK));
        $this->assertTrue($this->indexExists(self::UNIQUE_INDEX));
        $this->assertTrue($this->constraintExists(self::LIFECYCLE_CHECK));

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message): bool => str_contains($message, self::GATE_TOKEN)
                && str_contains($message, 'status=BLOCKED')
                && str_contains($message, 'leg=type-whitelist'))
            ->atLeast()->once();
    }

    public function test_a_pre_existing_incoherent_lifecycle_row_blocks_only_the_lifecycle_leg(): void
    {
        $this->requirePostgres();

        [$migration] = $this->requireTenantMigrations(self::MIGRATION);
        $migration->down();

        $this->insertTerminal([
            'code' => 'POS01',
            'is_active' => true,
            'deactivated_at' => now(),
            'deactivation_reason' => 'legacy incoherence',
        ]);

        Log::spy();

        $migration->up();

        $this->assertFalse($this->constraintExists(self::LIFECYCLE_CHECK));
        $this->assertTrue($this->indexExists(self::UNIQUE_INDEX));
        $this->assertTrue($this->constraintExists(self::TYPE_CHECK));

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message): bool => str_contains($message, self::GATE_TOKEN)
                && str_contains($message, 'status=BLOCKED')
                && str_contains($message, 'leg=active-lifecycle'))
            ->atLeast()->once();
    }

    // ------------------------------------------------------------------ helpers

    private function onPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    private function requirePostgres(): void
    {
        if (! $this->onPostgres()) {
            $this->markTestSkipped(
                'ALTER TABLE … ADD CONSTRAINT is PostgreSQL-only here; run this case with DB_CONNECTION=pgsql DB_PORT=5433.'
            );
        }
    }

    private function indexExists(string $name): bool
    {
        if ($this->onPostgres()) {
            return DB::select('SELECT 1 FROM pg_indexes WHERE indexname = ?', [$name]) !== [];
        }

        return DB::select("SELECT 1 FROM sqlite_master WHERE type = 'index' AND name = ?", [$name]) !== [];
    }

    private function constraintExists(string $name): bool
    {
        return DB::select(
            "SELECT 1 FROM pg_constraint WHERE conname = ? AND conrelid = 'pos_terminals'::regclass",
            [$name],
        ) !== [];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insertTerminal(array $overrides = []): string
    {
        $id = (string) Str::uuid();

        DB::table('pos_terminals')->insert(array_merge([
            'id' => $id,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'type' => TerminalType::Physical->value,
            'code' => 'POS01',
            'name' => 'Constraint fixture',
            'genesis_seed' => str_repeat('b', 64),
            'current_sequence' => 1,
            'current_year' => 2026,
            'is_active' => true,
            'hardware_identifier' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return $id;
    }
}
