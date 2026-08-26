<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Compliance\Domain\AuditEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\TerminalType;
use App\Modules\POS\Domain\Events\TerminalClaimed;
use App\Modules\POS\Domain\Events\TerminalReleased;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentRepository;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\POS\Migrations\PosTerminalsIdentityLifecycleConstraintsTest;
use Tests\TestCase;

/**
 * Session B lane Q-7 — terminal-claim hardening.
 *
 * `pos_terminals` IS the NF525 fiscal chain identity (`genesis_seed`,
 * `current_sequence`, `last_hash`), and `hardware_identifier` is the only link
 * between that identity and a physical till. Before this lane the link was
 * established by an unlocked check-then-set with no DB backstop:
 *
 *  - `claim()` read the row with `firstOrFail()`, tested
 *    `hardware_identifier !== null` in memory, then wrote with a plain
 *    `WHERE id = ?` update. Two devices claiming the same terminal both passed
 *    the check and both believed they owned the chain.
 *  - `requestTerminal()` wrote `hardware_identifier` straight into
 *    `Terminal::create()` with no collision check at all, so one device could
 *    hold N terminals.
 *  - `findByDevice()` resolved with `->first()` and NO ordering, so which
 *    terminal a device got back was whatever the planner returned first.
 *  - Nothing ever cleared `hardware_identifier` — `deactivate()` does not —
 *    so a dead till could not be re-homed to replacement hardware.
 *  - `claim()` dispatched no audit event at all, unlike its
 *    `activate()`/`deactivate()` siblings.
 *
 * These cases pin the closed versions. The DB backstop itself (the partial
 * unique index) is created on BOTH pgsql and sqlite — partial unique indexes
 * are native to both — so the collision cases below are real on the default
 * harness. The two CHECK constraints are pgsql-only and are pinned separately
 * in {@see PosTerminalsIdentityLifecycleConstraintsTest}.
 *
 * B-3 (merged `457457911`) owns the `LOCATION_POS_DISABLED` refusals on the
 * same four acquisition paths and is NOT re-tested here — that is
 * {@see TerminalLocationPosEnabledTest}'s job. What IS pinned here is that the
 * refusal ORDER around it did not move.
 */
final class TerminalClaimHardeningTest extends TestCase
{
    use RefreshDatabase;

    private const UNIQUE_INDEX = 'pos_terminals_unique_hardware_identifier';

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->location = Location::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Shop Floor',
            'type' => 'shop',
            // B-3: every acquisition path refuses a POS-disabled location, so
            // the fixture location must be enabled or every case below would
            // stop at LOCATION_POS_DISABLED and prove nothing about claiming.
            'pos_enabled' => true,
        ]);

        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('pos.manage_terminals', 'sanctum');
        Permission::findOrCreate('pos.operate_terminal', 'sanctum');
        $this->user->givePermissionTo('pos.manage_terminals');
        $this->user->givePermissionTo('pos.operate_terminal');

        // Campaign lane N-12 — a terminal may only be acquired at a location
        // whose cash has somewhere of its own to go. This fixture keeps the
        // company on the pre-N-12 shape (an unattributed, GL-linked till that
        // still serves every location, the resolver's tier 2), so the claim
        // paths under test here stay exactly as hardening left them.
        PaymentRepository::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => RepositoryType::CashRegister,
            'location_id' => null,
            'gl_account_id' => Account::factory()->create(['tenant_id' => $this->tenant->id, 'company_id' => $this->company->id])->id,
        ]);

        Sanctum::actingAs($this->user);
    }

    // ---------------------------------------------------------------- claim()

    /**
     * THE RACE, reproduced deterministically.
     *
     * `claim()` reads the terminal, then runs B-3's `pos_enabled` lookup, then
     * tests `hardware_identifier` against the IN-MEMORY copy it read before
     * that lookup. This test injects a competing device's write into exactly
     * that window by listening for the `locations` SELECT and claiming the row
     * from underneath the request.
     *
     * With the old plain `WHERE id = ?` update the request answers 200 and
     * silently overwrites the competitor — both devices then author against one
     * fiscal chain. With the conditional `WHERE hardware_identifier IS NULL`
     * update it answers 409 and the first writer keeps the terminal.
     */
    public function test_a_device_that_loses_the_claim_race_is_refused_and_does_not_overwrite_the_winner(): void
    {
        $terminal = $this->claimableTerminal();

        $raced = false;
        DB::listen(function (QueryExecuted $query) use ($terminal, &$raced): void {
            if ($raced) {
                return;
            }
            if (! str_contains($query->sql, '"locations"') && ! str_contains($query->sql, '`locations`')) {
                return;
            }
            $raced = true;

            // The competing device wins the race between this request's read
            // and its write.
            DB::table('pos_terminals')
                ->where('id', $terminal->id)
                ->update(['hardware_identifier' => 'HW-RACE-WINNER']);
        });

        $response = $this->postJson('/api/v1/pos/terminals/claim', [
            'terminal_id' => $terminal->id,
            'hardware_identifier' => 'HW-RACE-LOSER',
        ]);

        $this->assertTrue($raced, 'The race was never injected — the interposition point moved.');

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'TERMINAL_ALREADY_CLAIMED');

        $this->assertSame(
            'HW-RACE-WINNER',
            $terminal->fresh()?->hardware_identifier,
            'The loser of the race must never overwrite the winner on the fiscal chain identity.',
        );
    }

    public function test_a_device_cannot_claim_a_second_terminal_while_it_holds_one(): void
    {
        $first = $this->claimableTerminal(['code' => 'POS01']);
        $second = $this->claimableTerminal(['code' => 'POS02']);

        $this->postJson('/api/v1/pos/terminals/claim', [
            'terminal_id' => $first->id,
            'hardware_identifier' => 'HW-ONE-DEVICE',
        ])->assertStatus(200);

        $response = $this->postJson('/api/v1/pos/terminals/claim', [
            'terminal_id' => $second->id,
            'hardware_identifier' => 'HW-ONE-DEVICE',
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'DEVICE_ALREADY_BOUND');

        $this->assertNull(
            $second->fresh()?->hardware_identifier,
            'One device must never hold two fiscal chains.',
        );
        $this->assertSame('HW-ONE-DEVICE', $first->fresh()?->hardware_identifier);
    }

    /**
     * The application-layer pre-check is itself racy — it is a courtesy, not the
     * guarantee. This binds the hardware identifier AFTER that pre-check's own
     * query has run, so the collision can only be discovered by
     * `pos_terminals_unique_hardware_identifier` when the conditional UPDATE
     * fires. What is proven here is the CATCH: without it the request would
     * surface the raw `QueryException` as a 500 instead of a named 409.
     */
    public function test_a_unique_violation_raised_after_the_pre_check_is_answered_as_a_409_not_a_500(): void
    {
        $terminal = $this->claimableTerminal();
        $other = $this->claimableTerminal(['code' => 'POS09']);

        $raced = false;
        DB::listen(function (QueryExecuted $query) use ($other, &$raced): void {
            if ($raced) {
                return;
            }
            // The `hardwareBoundElsewhere()` pre-check's own SELECT — the last
            // query before the conditional UPDATE.
            if (! str_contains($query->sql, 'hardware_identifier')) {
                return;
            }
            if (! str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
                return;
            }
            $raced = true;

            DB::table('pos_terminals')
                ->where('id', $other->id)
                ->update(['hardware_identifier' => 'HW-BACKSTOP']);
        });

        $response = $this->postJson('/api/v1/pos/terminals/claim', [
            'terminal_id' => $terminal->id,
            'hardware_identifier' => 'HW-BACKSTOP',
        ]);

        $this->assertTrue($raced, 'The race was never injected — the interposition point moved.');

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'DEVICE_ALREADY_BOUND');
        $this->assertNull($terminal->fresh()?->hardware_identifier);
    }

    public function test_a_successful_claim_emits_the_claim_audit_event(): void
    {
        Event::fake([TerminalClaimed::class]);

        $terminal = $this->claimableTerminal();

        $this->postJson('/api/v1/pos/terminals/claim', [
            'terminal_id' => $terminal->id,
            'hardware_identifier' => 'HW-AUDIT-1',
        ])->assertStatus(200);

        Event::assertDispatched(
            TerminalClaimed::class,
            fn (TerminalClaimed $event): bool => $event->terminalId === $terminal->id
                && $event->terminalCode === $terminal->code
                && $event->companyId === $this->company->id
                && $event->hardwareIdentifier === 'HW-AUDIT-1'
                && $event->claimedBy === (string) $this->user->id,
        );
    }

    /**
     * B-3 regression by adjacency: the refusal ladder's ORDER is contractual
     * (an inactive terminal is the nearer, more actionable cause, so it must
     * still report before the claim state). The conditional update must not
     * have hoisted the claim check above its siblings.
     */
    public function test_the_refusal_order_is_unchanged_for_an_inactive_already_claimed_terminal(): void
    {
        $terminal = $this->claimableTerminal([
            'is_active' => false,
            'deactivated_at' => now(),
            'deactivation_reason' => 'RMA',
            'hardware_identifier' => 'HW-SOMEONE-ELSE',
        ]);

        $response = $this->postJson('/api/v1/pos/terminals/claim', [
            'terminal_id' => $terminal->id,
            'hardware_identifier' => 'HW-NEW',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'TERMINAL_INACTIVE');
    }

    // -------------------------------------------------------- requestTerminal()

    public function test_request_terminal_refuses_a_hardware_identifier_that_is_already_bound(): void
    {
        $existing = $this->claimableTerminal();

        $this->postJson('/api/v1/pos/terminals/claim', [
            'terminal_id' => $existing->id,
            'hardware_identifier' => 'HW-BOUND-ALREADY',
        ])->assertStatus(200);

        $before = Terminal::withTrashed()->count();

        $response = $this->postJson('/api/v1/pos/terminals/request', [
            'location_id' => $this->location->id,
            'hardware_identifier' => 'HW-BOUND-ALREADY',
            'suggested_name' => 'Second till on the same box',
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'DEVICE_ALREADY_BOUND');

        $this->assertSame(
            $before,
            Terminal::withTrashed()->count(),
            'A refused request must not leave a half-provisioned terminal behind.',
        );
    }

    /**
     * A unique violation on a DIFFERENT constraint must NOT be reported as
     * `DEVICE_ALREADY_BOUND`.
     *
     * HONEST PIN REWRITE — LEDGER C-17(vii). This case used to assert
     * `>= 500`, because `generateTerminalCode()` derived the next code from a
     * `count()` and therefore collided with this fixture's POS02 on
     * `pos_terminals_unique_code`; the point being made was only that the 500 is
     * not MIS-REPORTED as a 409 "release your other terminal". C-17(vii) makes
     * the allocator max+1, so that collision is no longer reachable from this
     * path at all and the old assertion pinned a defect. What survives is the
     * behaviour that mattered: an unbound device provisioning at a location
     * whose code sequence has a hole is not a device-binding conflict — it is a
     * successful provision on the next FREE code.
     *
     * `isUniqueViolation()`'s discrimination itself is still exercised by
     * {@see test_request_terminal_refuses_a_hardware_identifier_that_is_already_bound}
     * above, which is the arm that must answer 409.
     */
    public function test_a_code_collision_is_not_mis_reported_as_a_device_binding_collision(): void
    {
        $this->claimableTerminal(['code' => 'POS02']);

        $response = $this->postJson('/api/v1/pos/terminals/request', [
            'location_id' => $this->location->id,
            'hardware_identifier' => 'HW-UNBOUND-DEVICE',
            'suggested_name' => 'Collides on code, not on hardware',
        ]);

        $this->assertNotSame(409, $response->getStatusCode());
        $this->assertLessThan(
            500,
            $response->getStatusCode(),
            'A hole in the code sequence must no longer 500 the provisioning path. Body: '.$response->getContent(),
        );
        $response->assertJsonPath('data.code', 'POS03');
    }

    public function test_request_terminal_still_provisions_for_an_unbound_device(): void
    {
        $response = $this->postJson('/api/v1/pos/terminals/request', [
            'location_id' => $this->location->id,
            'hardware_identifier' => 'HW-FRESH-DEVICE',
            'suggested_name' => 'Counter till',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.hardware_identifier', 'HW-FRESH-DEVICE');
        $response->assertJsonPath('data.is_active', false);
    }

    /**
     * T-6 — `pos_terminals.hardware_identifier` is `varchar(100)`
     * (`2026_01_08_190429_create_pos_terminals_table.php:51`) while both
     * FormRequests validated `max:255`. An over-long identifier therefore
     * reached the INSERT and surfaced as an unhandled 22001 (PostgreSQL) or was
     * silently truncated/stored oversize (SQLite, which does not enforce
     * varchar length) — neither is an answer an operator can act on.
     */
    public function test_claim_refuses_a_hardware_identifier_longer_than_the_column(): void
    {
        $terminal = $this->claimableTerminal();

        $response = $this->postJson('/api/v1/pos/terminals/claim', [
            'terminal_id' => $terminal->id,
            'hardware_identifier' => str_repeat('H', 101),
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('hardware_identifier', $response->json('error.errors'));
        $this->assertNull($terminal->fresh()?->hardware_identifier);
    }

    public function test_request_terminal_refuses_a_hardware_identifier_longer_than_the_column(): void
    {
        $before = Terminal::withTrashed()->count();

        $response = $this->postJson('/api/v1/pos/terminals/request', [
            'location_id' => $this->location->id,
            'hardware_identifier' => str_repeat('H', 101),
            'suggested_name' => 'Device id longer than the column',
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('hardware_identifier', $response->json('error.errors'));
        $this->assertSame($before, Terminal::withTrashed()->count());
    }

    // ------------------------------------------------------------ findByDevice()

    /**
     * Determinism still matters for the population the index cannot repair: a
     * brownfield tenant whose pre-existing duplicates BLOCKED the index (the
     * migration reports and skips rather than aborting the tenant's run — see
     * the migration docblock). The index is dropped here to model exactly that
     * tenant.
     *
     * The OLDER row is inserted SECOND on purpose. An unordered `->first()`
     * returns rows in physical/insertion order on both sqlite and PostgreSQL
     * for a table this size, so without an explicit ORDER BY this case gets the
     * NEWER row back — which is the whole defect.
     */
    public function test_find_by_device_resolves_duplicates_deterministically_to_the_oldest_binding(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::UNIQUE_INDEX);

        $newer = $this->insertRawTerminal('POS20', 'HW-DUPLICATE', '2026-08-20 10:00:00');
        $older = $this->insertRawTerminal('POS21', 'HW-DUPLICATE', '2026-01-05 08:00:00');

        $firstCall = $this->getJson('/api/v1/pos/terminals/by-device/HW-DUPLICATE');
        $firstCall->assertStatus(200);
        $firstCall->assertJsonPath('data.id', $older);

        // Deterministic means the same answer every time, not merely a defined
        // answer once.
        $this->getJson('/api/v1/pos/terminals/by-device/HW-DUPLICATE')
            ->assertStatus(200)
            ->assertJsonPath('data.id', $older);

        $this->assertNotSame($older, $newer);
    }

    // ----------------------------------------------------------------- release()

    public function test_release_requires_manage_terminals_permission(): void
    {
        $terminal = $this->claimableTerminal(['hardware_identifier' => 'HW-TO-RELEASE']);

        $operator = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $operator->id,
            'company_id' => $this->company->id,
            'role' => 'cashier',
        ]);
        $operator->givePermissionTo('pos.operate_terminal');
        Sanctum::actingAs($operator);

        $this->postJson("/api/v1/pos/terminals/{$terminal->id}/release", [])
            ->assertStatus(403);

        $this->assertSame(
            'HW-TO-RELEASE',
            $terminal->fresh()?->hardware_identifier,
            'A refused release must not clear the binding.',
        );
    }

    /**
     * F-3 — the SINGLE fiscal contract of this endpoint: a re-homed terminal
     * CONTINUES its NF525 chain, it never restarts it. `release()` must write
     * `hardware_identifier` and NOTHING else. This is exactly what a
     * well-meaning future refactor ("release should reset the terminal for the
     * new device") would break — silently, and irreversibly, because every
     * receipt the replacement till then seals would chain off a fresh genesis.
     * Read raw through the query builder so no Eloquent cast can launder a
     * difference.
     */
    public function test_release_clears_the_binding_and_emits_the_audit_event(): void
    {
        Event::fake([TerminalReleased::class]);

        $terminal = $this->claimableTerminal([
            'hardware_identifier' => 'HW-DEAD-DEVICE',
            'genesis_seed' => str_repeat('b', 64),
            'current_sequence' => 4242,
            'current_year' => 2026,
            'last_hash' => str_repeat('c', 64),
        ]);

        $chainBefore = $this->rawChainState($terminal->id);
        $this->assertSame(str_repeat('b', 64), $chainBefore['genesis_seed'], 'Fixture drift: the chain head was never seeded.');

        $response = $this->postJson("/api/v1/pos/terminals/{$terminal->id}/release", [
            'reason' => 'Device destroyed in the back office flood',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.hardware_identifier', null);

        $this->assertNull($terminal->fresh()?->hardware_identifier);

        $chainAfter = $this->rawChainState($terminal->id);
        $this->assertSame($chainBefore['current_sequence'], $chainAfter['current_sequence'], 'release() must not move the chain sequence.');
        $this->assertSame($chainBefore['last_hash'], $chainAfter['last_hash'], 'release() must not clear the chain head hash.');
        $this->assertSame($chainBefore['genesis_seed'], $chainAfter['genesis_seed'], 'release() must never re-seed the chain — the replacement device CONTINUES it.');
        $this->assertSame($chainBefore['current_year'], $chainAfter['current_year'], 'release() must not move the sequence-reset year.');

        Event::assertDispatched(
            TerminalReleased::class,
            fn (TerminalReleased $event): bool => $event->terminalId === $terminal->id
                && $event->terminalCode === $terminal->code
                && $event->companyId === $this->company->id
                && $event->hardwareIdentifier === 'HW-DEAD-DEVICE'
                && $event->reason === 'Device destroyed in the back office flood'
                && $event->releasedBy === (string) $this->user->id
                && $event->forced === false
                && $event->openShiftId === null,
        );
    }

    /**
     * T-5 — the information-leak contract. A terminal belonging to ANOTHER
     * company of the same tenant must answer 404, NOT 403: a 403 would confirm
     * the id exists and turn the endpoint into an existence oracle across the
     * tenant's legal entities. Mirrors
     * {@see TerminalLocationPosEnabledTest::test_claim_fails_closed_when_the_terminal_points_at_another_companys_location}.
     */
    public function test_release_of_another_companys_terminal_is_a_404_and_leaves_the_binding_intact(): void
    {
        $otherCompany = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $foreignLocation = Location::factory()->create([
            'company_id' => $otherCompany->id,
            'type' => 'shop',
            'pos_enabled' => true,
        ]);

        $foreign = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
            'location_id' => $foreignLocation->id,
            'type' => TerminalType::Physical,
            'code' => 'POS77',
            'is_active' => true,
            'hardware_identifier' => 'HW-FOREIGN',
        ]);

        $response = $this->postJson("/api/v1/pos/terminals/{$foreign->id}/release", [
            'reason' => 'Must never take effect',
        ]);

        $this->assertNotSame(403, $response->getStatusCode(), 'A cross-company terminal must not be confirmed to exist by a 403.');
        $response->assertStatus(404);

        $this->assertSame(
            'HW-FOREIGN',
            $foreign->fresh()?->hardware_identifier,
            'A cross-company release must not clear another company\'s binding.',
        );
    }

    public function test_releasing_an_unclaimed_terminal_is_a_no_op_and_writes_no_audit_event(): void
    {
        Event::fake([TerminalReleased::class]);

        $terminal = $this->claimableTerminal();

        $this->postJson("/api/v1/pos/terminals/{$terminal->id}/release", [])
            ->assertStatus(200)
            ->assertJsonPath('data.hardware_identifier', null);

        Event::assertNotDispatched(TerminalReleased::class);
    }

    public function test_a_released_terminal_can_be_claimed_by_replacement_hardware(): void
    {
        $terminal = $this->claimableTerminal();

        $this->postJson('/api/v1/pos/terminals/claim', [
            'terminal_id' => $terminal->id,
            'hardware_identifier' => 'HW-OLD-BOX',
        ])->assertStatus(200);

        $this->postJson("/api/v1/pos/terminals/{$terminal->id}/release", [
            'reason' => 'Hardware swap',
        ])->assertStatus(200);

        $this->postJson('/api/v1/pos/terminals/claim', [
            'terminal_id' => $terminal->id,
            'hardware_identifier' => 'HW-NEW-BOX',
        ])->assertStatus(200);

        $this->assertSame('HW-NEW-BOX', $terminal->fresh()?->hardware_identifier);
    }

    /**
     * F-1 — releasing a terminal that still has an OPEN shift is refused BY
     * DEFAULT.
     *
     * The r1 fiscal gate proved the consequence of allowing it silently: the
     * replacement device's SESSION_OPEN is DROPPED by
     * `ZSessionLifecycleProjection.php:146-153` (a different OPEN shift already
     * sits on the terminal, so it silently `return`s), its SESSION_CLOSE then
     * retries to exhaustion at `:205-216`, and its Z_REPORT can never land
     * because `pos_z_reports.shift_id` is FK-RESTRICTed to a `pos_shifts` row
     * that does not exist. The till sells; the server projects nothing.
     */
    public function test_release_is_refused_while_a_shift_is_open(): void
    {
        Event::fake([TerminalReleased::class]);

        $terminal = $this->claimableTerminal(['hardware_identifier' => 'HW-LOST-BOX']);
        $shiftId = $this->openShiftOn($terminal);

        $response = $this->postJson("/api/v1/pos/terminals/{$terminal->id}/release", [
            'reason' => 'Till stolen mid-shift',
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'TERMINAL_HAS_OPEN_SHIFT');
        $response->assertJsonPath('error.open_shift_id', $shiftId);

        $this->assertSame(
            'HW-LOST-BOX',
            $terminal->fresh()?->hardware_identifier,
            'A refused release must leave the binding exactly as it was.',
        );

        Event::assertNotDispatched(TerminalReleased::class);
    }

    /**
     * The override is not a checkbox. A lost device's shift genuinely cannot be
     * closed from the device (`ShiftController.php:63,127`,
     * `SyncController.php:56` all hard-409 for `fiscal_schema_version >= 3`,
     * which is every terminal this controller provisions), so the remedy must
     * stay reachable — but the operator must say WHY, on the record, because
     * the act knowingly orphans a fiscal shift.
     */
    public function test_a_forced_release_without_a_reason_is_refused(): void
    {
        $terminal = $this->claimableTerminal(['hardware_identifier' => 'HW-LOST-BOX']);
        $this->openShiftOn($terminal);

        $response = $this->postJson("/api/v1/pos/terminals/{$terminal->id}/release", [
            'force' => true,
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('reason', $response->json('error.errors'));

        $this->assertSame('HW-LOST-BOX', $terminal->fresh()?->hardware_identifier);
    }

    /**
     * Forced release succeeds, and the audit register records WHICH shift it
     * orphaned — the only artefact an auditor (or the operator who has to
     * resolve the orphan) can work from once the binding is gone.
     */
    public function test_a_forced_release_proceeds_and_records_the_orphaned_shift_in_the_audit_row(): void
    {
        $terminal = $this->claimableTerminal(['hardware_identifier' => 'HW-LOST-BOX']);
        $shiftId = $this->openShiftOn($terminal);

        $this->postJson("/api/v1/pos/terminals/{$terminal->id}/release", [
            'force' => true,
            'reason' => 'Till stolen mid-shift; shift cannot be closed from the device',
        ])->assertStatus(200);

        $this->assertNull($terminal->fresh()?->hardware_identifier);

        $audit = AuditEvent::query()
            ->where('company_id', $this->company->id)
            ->where('aggregate_type', 'Terminal')
            ->where('aggregate_id', $terminal->id)
            ->where('event_type', 'terminal.released')
            ->first();

        $this->assertNotNull($audit, 'A forced release must leave an audit row.');

        $payload = $audit->payload;
        $this->assertSame($shiftId, $payload['open_shift_id']);
        $this->assertTrue($payload['forced']);
        $this->assertSame('Till stolen mid-shift; shift cannot be closed from the device', $payload['reason']);

        // The shift really IS orphaned — forcing does not close it, and no
        // server surface can (v3 terminals refuse device-less shift closure).
        $this->assertSame(
            'OPEN',
            DB::table('pos_shifts')->where('id', $shiftId)->value('status'),
            'A forced release orphans the shift; it must not pretend to have closed it.',
        );
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Projects the shape a device's SESSION_OPEN leaves behind: an OPEN
     * `pos_shifts` row on the terminal.
     */
    // ----------------------------------------- code allocation + release lock

    /**
     * LEDGER C-17(vii) — `generateTerminalCode()` derived the next code from
     * `Terminal::withTrashed()->forCompany($id)->count()`. A count is not a
     * maximum: as soon as the code sequence has a HOLE (an admin-supplied code,
     * a renamed till), `count + 1` lands on a code that already exists and the
     * INSERT dies on `pos_terminals_unique_code` — a 500 on a perfectly ordinary
     * "add a terminal" click, with no concurrency involved at all.
     *
     * Fixture: POS01 and POS03 exist at the fixture location. count() = 2 =>
     * "POS03" (collision). max + 1 => "POS04".
     */
    public function test_auto_generated_terminal_code_is_max_plus_one_not_count_plus_one(): void
    {
        $this->claimableTerminal(['code' => 'POS01']);
        $this->claimableTerminal(['code' => 'POS03']);

        $response = $this->postJson('/api/v1/pos/terminals', [
            'location_id' => $this->location->id,
            'name' => 'Fourth till',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.code', 'POS04');
    }

    /**
     * A non-numeric or foreign code must not derail the allocator — it is
     * skipped, not parsed as zero.
     */
    public function test_auto_generated_terminal_code_ignores_codes_outside_the_pos_sequence(): void
    {
        $this->claimableTerminal(['code' => 'POS07']);
        $this->claimableTerminal(['code' => 'CAISSE-A']);

        $this->postJson('/api/v1/pos/terminals', [
            'location_id' => $this->location->id,
            'name' => 'Next till',
        ])->assertStatus(201)->assertJsonPath('data.code', 'POS08');
    }

    /**
     * LEDGER C-17(vii), the TOCTOU half. max+1 alone is still read-then-write:
     * two concurrent creates read the same maximum and race to the same code.
     * The read is now serialised by a transaction-scoped advisory lock keyed on
     * the company — the shape `ExpenseService::generateExpenseNumber()` and
     * `GeneralLedgerService::takeTenantNumberingLock()` already use — and the
     * allocation now happens INSIDE the create transaction, so the lock is held
     * until the INSERT commits.
     *
     * PostgreSQL only: `pg_advisory_xact_lock` does not exist on SQLite (the
     * helper is a no-op there, like its two siblings).
     */
    public function test_terminal_code_allocation_takes_the_company_advisory_lock_before_the_insert(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('pg_advisory_xact_lock is PostgreSQL-only; the helper no-ops on SQLite.');
        }

        /** @var list<string> $statements */
        $statements = [];
        DB::listen(function (QueryExecuted $query) use (&$statements): void {
            $statements[] = strtolower($query->sql);
        });

        $this->postJson('/api/v1/pos/terminals', [
            'location_id' => $this->location->id,
            'name' => 'Locked till',
        ])->assertStatus(201);

        $lockAt = null;
        $insertAt = null;
        foreach ($statements as $i => $sql) {
            if ($lockAt === null && str_contains($sql, 'pg_advisory_xact_lock')) {
                $lockAt = $i;
            }
            if ($insertAt === null && str_contains($sql, 'insert into "pos_terminals"')) {
                $insertAt = $i;
            }
        }

        $this->assertNotNull($lockAt, 'Terminal-code allocation must take the per-company advisory lock.');
        $this->assertNotNull($insertAt, 'The create never inserted — the trace point moved.');
        $this->assertLessThan(
            $insertAt,
            $lockAt,
            'The advisory lock must be taken BEFORE the INSERT, inside the same transaction.',
        );
    }

    /**
     * LEDGER C-17(viii) — `release()`'s open-shift probe was check-then-act with
     * no lock at all: it read `pos_shifts` for an OPEN row, then wrote
     * `hardware_identifier = NULL` outside any transaction. The probe now runs
     * INSIDE a transaction, after `lockForUpdate()` on the terminal row, and the
     * clearing write happens on that same locked instance.
     *
     * PostgreSQL only for the SQL-text half: `SQLiteGrammar::compileLock()`
     * returns '', so `FOR UPDATE` is compiled away and the ordering could never
     * be observed on the SQLite leg.
     */
    public function test_release_probes_for_an_open_shift_under_the_terminal_row_lock(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('FOR UPDATE is compiled away by SQLiteGrammar::compileLock().');
        }

        $terminal = $this->claimableTerminal(['hardware_identifier' => 'HW-RELEASE-LOCK']);

        /** @var list<string> $statements */
        $statements = [];
        DB::listen(function (QueryExecuted $query) use (&$statements): void {
            $statements[] = strtolower($query->sql);
        });

        $this->postJson("/api/v1/pos/terminals/{$terminal->id}/release", [
            'reason' => 'device replaced',
        ])->assertStatus(200);

        $lockAt = null;
        $probeAt = null;
        $updateAt = null;
        foreach ($statements as $i => $sql) {
            if ($lockAt === null && str_contains($sql, 'from "pos_terminals"') && str_contains($sql, 'for update')) {
                $lockAt = $i;
            }
            if ($probeAt === null && str_contains($sql, 'from "pos_shifts"') && str_contains($sql, 'status')) {
                $probeAt = $i;
            }
            if ($updateAt === null && str_contains($sql, 'update "pos_terminals"')) {
                $updateAt = $i;
            }
        }

        $this->assertNotNull($lockAt, 'release() must re-read the terminal row FOR UPDATE.');
        $this->assertNotNull($probeAt, 'The open-shift probe never ran — the trace point moved.');
        $this->assertNotNull($updateAt, 'release() never cleared the binding.');
        $this->assertLessThan($probeAt, $lockAt, 'The open-shift probe must run AFTER the row lock.');
        $this->assertLessThan($updateAt, $lockAt, 'The clearing write must happen under the row lock.');

        $this->assertNull($terminal->fresh()?->hardware_identifier);
    }

    // ------------------------------------------------------ zChainState()

    /**
     * LEDGER C-17(ii) — `zChainState()` derived `z_hash_sequence` from
     * `ZReport::forTerminal(...)->count()` while `z_number` came from the LATEST
     * row. The device keeps the two counters in lockstep
     * (`zReportService.ts:440-442`: `newZNumber = z_number + 1`,
     * `newHashSequence = z_hash_sequence + 1`), so the recovery endpoint must
     * hand back a matching pair. `count()` under-reports the moment any Z row is
     * absent server-side — exactly the O-30 population — and the value is sealed
     * into the next Z payload as `legacyReportReference.hash_sequence`
     * (`zReportService.ts:722-724`), so a recovered device would author a Z whose
     * hash sequence silently rewinds.
     *
     * Fixture: Z 1 and Z 3 exist, Z 2 does not. `count()` = 2, latest z_number = 3.
     */
    public function test_z_chain_state_hash_sequence_tracks_the_latest_z_number_not_the_row_count(): void
    {
        $terminal = $this->claimableTerminal(['hardware_identifier' => 'HW-ZCHAIN']);

        $this->insertZReport($terminal, zNumber: 1, shiftNumber: 1, fiscalHash: str_repeat('1', 64));
        // Z 2 never reached the server (the O-30 shape).
        $this->insertZReport($terminal, zNumber: 3, shiftNumber: 3, fiscalHash: str_repeat('3', 64));

        $this->assertSame(
            2,
            DB::table('pos_z_reports')->where('terminal_id', $terminal->id)->count(),
            'The fixture must actually have a hole in it, or this proves nothing.',
        );

        $response = $this->getJson("/api/v1/pos/terminals/{$terminal->id}/z-chain-state");

        $response->assertStatus(200);
        $response->assertJsonPath('data.z_number', 3);
        $response->assertJsonPath('data.z_last_hash', str_repeat('3', 64));
        $response->assertJsonPath(
            'data.z_hash_sequence',
            3,
        );
    }

    /**
     * The no-Z case must still answer the genesis pair — the fix must not turn
     * an empty chain into a null or a 500.
     */
    public function test_z_chain_state_is_genesis_when_the_terminal_has_no_z_reports(): void
    {
        $terminal = $this->claimableTerminal(['hardware_identifier' => 'HW-ZCHAIN-EMPTY']);

        $this->getJson("/api/v1/pos/terminals/{$terminal->id}/z-chain-state")
            ->assertStatus(200)
            ->assertJsonPath('data.z_last_hash', 'GENESIS')
            ->assertJsonPath('data.z_hash_sequence', 0)
            ->assertJsonPath('data.z_number', 0);
    }

    // ------------------------------------------------- route id constraints

    /**
     * LEDGER C-17(iv) — a non-UUID `{id}` 500s across the whole terminal route
     * group. Every one of these handlers takes `string $id` and puts it straight
     * into a `where('id', …)` against a `uuid` column, so PostgreSQL answers
     * `SQLSTATE[22P02] invalid input syntax for type uuid` — a 500 with a SQL
     * fragment in the log for what is simply a bad URL. Fixed group-wide with
     * `whereUuid`, so an unparseable id is a 404 before the controller runs.
     *
     * PostgreSQL only, and not for convenience: SQLite is untyped, so
     * `where id = 'not-a-uuid'` there just matches no rows and every one of
     * these already answers 404. The defect is invisible on the SQLite leg.
     */
    public function test_a_non_uuid_terminal_id_is_a_404_not_a_500(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('SQLite is untyped: a non-UUID id matches no rows instead of raising 22P02.');
        }

        $calls = [
            ['get', '/api/v1/pos/terminals/not-a-uuid'],
            ['patch', '/api/v1/pos/terminals/not-a-uuid'],
            ['delete', '/api/v1/pos/terminals/not-a-uuid'],
            ['patch', '/api/v1/pos/terminals/not-a-uuid/activate'],
            ['patch', '/api/v1/pos/terminals/not-a-uuid/deactivate'],
            ['post', '/api/v1/pos/terminals/not-a-uuid/release'],
            ['patch', '/api/v1/pos/terminals/not-a-uuid/archive'],
            ['post', '/api/v1/pos/terminals/not-a-uuid/toggle-training'],
            ['get', '/api/v1/pos/terminals/not-a-uuid/z-chain-state'],
            ['post', '/api/v1/pos/terminals/not-a-uuid/fiscal-schema-cutover'],
            ['post', '/api/v1/pos/terminals/not-a-uuid/acknowledge-v4-refund-authoring'],
        ];

        foreach ($calls as [$verb, $url]) {
            $response = match ($verb) {
                'get' => $this->getJson($url),
                'patch' => $this->patchJson($url, []),
                'delete' => $this->deleteJson($url),
                default => $this->postJson($url, []),
            };

            $this->assertSame(
                404,
                $response->status(),
                strtoupper($verb)." {$url} must 404 on an unparseable id, not 500. Body: ".$response->getContent(),
            );
        }
    }

    /**
     * Insert a Z report straight through the query builder (with its own shift,
     * since `pos_z_reports.shift_id` is unique) so the fixture can contain a
     * HOLE — a z_number the server never received.
     */
    private function insertZReport(Terminal $terminal, int $zNumber, int $shiftNumber, string $fiscalHash): void
    {
        $shiftId = (string) Str::uuid();

        DB::table('pos_shifts')->insert([
            'id' => $shiftId,
            'terminal_id' => $terminal->id,
            'cashier_id' => $this->user->id,
            'shift_number' => $shiftNumber,
            // `pos_shifts_closed_logic` (PG CHECK): CLOSED requires both
            // closed_at and closed_by.
            'status' => 'CLOSED',
            'opening_cash' => '0.00',
            'opened_at' => now(),
            'closed_at' => now(),
            'closed_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('pos_z_reports')->insert([
            'id' => (string) Str::uuid(),
            'terminal_id' => $terminal->id,
            'shift_id' => $shiftId,
            'z_number' => $zNumber,
            'fiscal_hash' => $fiscalHash,
            'previous_z_hash' => null,
            'report_data' => json_encode(['z_number' => $zNumber]),
            'receipt_snapshots' => json_encode([]),
            'grand_totals' => json_encode([]),
            'generated_by' => $this->user->id,
            'generated_at' => now(),
        ]);
    }

    private function openShiftOn(Terminal $terminal): string
    {
        $shiftId = (string) Str::uuid();

        DB::table('pos_shifts')->insert([
            'id' => $shiftId,
            'terminal_id' => $terminal->id,
            'cashier_id' => $this->user->id,
            'shift_number' => 1,
            'status' => 'OPEN',
            'opening_cash' => '0.00',
            'opened_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $shiftId;
    }

    /**
     * The four chain-identity columns, read RAW so an Eloquent cast cannot
     * launder a difference between two reads.
     *
     * @return array<string, mixed>
     */
    private function rawChainState(string $terminalId): array
    {
        $row = DB::table('pos_terminals')
            ->where('id', $terminalId)
            ->first(['current_sequence', 'last_hash', 'genesis_seed', 'current_year']);

        $this->assertNotNull($row, 'The terminal row vanished.');

        return (array) $row;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function claimableTerminal(array $overrides = []): Terminal
    {
        return Terminal::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'type' => TerminalType::Physical,
            'is_active' => true,
            'hardware_identifier' => null,
        ], $overrides));
    }

    /**
     * Insert a row straight through the query builder so `created_at` is under
     * this test's control and the model's own ordering cannot interfere.
     */
    private function insertRawTerminal(string $code, string $hardwareIdentifier, string $createdAt): string
    {
        $id = (string) Str::uuid();

        DB::table('pos_terminals')->insert([
            'id' => $id,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'type' => TerminalType::Physical->value,
            'code' => $code,
            'name' => 'Duplicate '.$code,
            'genesis_seed' => str_repeat('a', 64),
            'current_sequence' => 1,
            'current_year' => 2026,
            'is_active' => true,
            'hardware_identifier' => $hardwareIdentifier,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        return $id;
    }
}
