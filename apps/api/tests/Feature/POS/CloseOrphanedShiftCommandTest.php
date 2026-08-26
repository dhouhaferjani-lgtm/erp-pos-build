<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Compliance\Domain\AuditEvent;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Projections\ZSessionLifecycleProjection;
use App\Modules\POS\Commands\CloseOrphanedShiftCommand;
use App\Modules\POS\Domain\CashDrawerOperation;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Enums\TerminalType;
use App\Modules\POS\Domain\Events\OrphanedShiftClosedByOperator;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * LEDGER O-30 / Q7-OWES-1 — the resolution surface for a `pos_shifts` row
 * orphaned by `POST /pos/terminals/{id}/release` with `force=true`.
 *
 * Before this command there was NO writer of `ShiftStatus::Closed` an operator
 * could reach: the only two are `ZSessionLifecycleProjection::projectPosShiftClose()`
 * (device-authored `SESSION_CLOSE` only) and `ShiftManagementService::closeShift()`,
 * whose HTTP callers hard-409 `SHIFT_DEVICE_AUTHORITY_REQUIRED` for every
 * `fiscal_schema_version >= 3` terminal — which is every terminal the POS
 * provisions. So the replacement device's `SESSION_OPEN` was silently dropped
 * (`ZSessionLifecycleProjection.php:147-153`), its `SESSION_CLOSE` retried to
 * exhaustion, and its Z report could never land (`pos_z_reports.shift_id` is
 * FK-RESTRICTed).
 *
 * The command is deliberately NARROW: it closes a shift ONLY when the audit
 * register itself proves the shift was orphaned by a forced release. It is not
 * a general "close any shift" surface, because that would be a device-less
 * shift close by the back door and would make the v3 device-authority refusal
 * a formality.
 */
final class CloseOrphanedShiftCommandTest extends TestCase
{
    use RefreshDatabase;

    private const COMMAND = 'pos:shift:close-orphaned';

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private User $user;

    private ?PaymentMethod $cashPaymentMethod = null;

    /**
     * `fiscal_events` is uniquely indexed on
     * (tenant, company, terminal, chain_context, sequence_number), so a clock- or
     * random-derived number collides as soon as two fixtures land in the same
     * millisecond. A plain counter is the only reliable source here.
     */
    private int $accountCollectionSequence = 900;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'currency' => 'TND',
        ]);
        $this->location = Location::factory()->create([
            'company_id' => $this->company->id,
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
        $this->user->givePermissionTo('pos.manage_terminals');

        Sanctum::actingAs($this->user);
    }

    // ------------------------------------------------------------- refusals

    public function test_an_unknown_shift_is_refused_with_the_not_found_exit_code(): void
    {
        $this->artisan(self::COMMAND, [
            'shift' => Str::uuid()->toString(),
            '--reason' => 'device stolen',
            '--closed-by' => $this->user->id,
            '--apply' => true,
        ])->assertExitCode(CloseOrphanedShiftCommand::EXIT_SHIFT_NOT_FOUND);
    }

    public function test_a_malformed_shift_id_is_a_usage_error_and_never_reaches_the_database(): void
    {
        $this->artisan(self::COMMAND, [
            'shift' => 'not-a-uuid',
            '--reason' => 'device stolen',
            '--closed-by' => $this->user->id,
        ])->assertExitCode(CloseOrphanedShiftCommand::INVALID);
    }

    public function test_a_missing_reason_is_a_usage_error(): void
    {
        [$terminal, $shiftId] = $this->orphanedShift();

        $this->artisan(self::COMMAND, [
            'shift' => $shiftId,
            '--closed-by' => $this->user->id,
            '--apply' => true,
        ])->assertExitCode(CloseOrphanedShiftCommand::INVALID);

        $this->assertSame('OPEN', $this->shiftStatus($shiftId));
        $this->assertNotNull($terminal->fresh());
    }

    public function test_a_missing_closed_by_is_a_usage_error(): void
    {
        [, $shiftId] = $this->orphanedShift();

        $this->artisan(self::COMMAND, [
            'shift' => $shiftId,
            '--reason' => 'device stolen',
            '--apply' => true,
        ])->assertExitCode(CloseOrphanedShiftCommand::INVALID);

        $this->assertSame('OPEN', $this->shiftStatus($shiftId));
    }

    /**
     * `pos_shifts.closed_by` is FK-RESTRICTed to `users`. A typo'd operator id
     * must be refused with its own code rather than exploding as a 23503 the
     * runbook reader has to decode.
     */
    public function test_a_closed_by_that_is_not_a_real_user_is_refused(): void
    {
        [, $shiftId] = $this->orphanedShift();

        $this->artisan(self::COMMAND, [
            'shift' => $shiftId,
            '--reason' => 'device stolen',
            '--closed-by' => Str::uuid()->toString(),
            '--apply' => true,
        ])->assertExitCode(CloseOrphanedShiftCommand::EXIT_CLOSED_BY_UNKNOWN);

        $this->assertSame('OPEN', $this->shiftStatus($shiftId));
    }

    /**
     * THE POINT OF THE COMMAND. An ordinary OPEN shift on a live terminal is
     * NOT closable here — only the audit register's own record of a forced
     * release authorises the close.
     */
    public function test_a_shift_no_forced_release_orphaned_is_refused(): void
    {
        $terminal = $this->terminal(['hardware_identifier' => 'HW-LIVE']);
        $shiftId = $this->openShiftOn($terminal);

        $this->artisan(self::COMMAND, [
            'shift' => $shiftId,
            '--reason' => 'operator forgot to close',
            '--closed-by' => $this->user->id,
            '--apply' => true,
        ])->assertExitCode(CloseOrphanedShiftCommand::EXIT_NOT_ORPHANED);

        $this->assertSame('OPEN', $this->shiftStatus($shiftId));
    }

    /**
     * A forced release on the SAME terminal that named a DIFFERENT shift does
     * not authorise this one — the match is on `payload.open_shift_id`, not on
     * "this terminal has had a forced release at some point".
     */
    public function test_a_forced_release_naming_another_shift_does_not_authorise_this_one(): void
    {
        $terminal = $this->terminal(['hardware_identifier' => 'HW-ONE']);
        $firstShiftId = $this->openShiftOn($terminal, shiftNumber: 1);
        $this->forceRelease($terminal, $firstShiftId);

        // The replacement device re-claims and opens its own shift, which is
        // then closed properly; a later shift is open and un-orphaned.
        DB::table('pos_shifts')->where('id', $firstShiftId)->update([
            'status' => 'CLOSED',
            'closed_at' => now(),
            'closed_by' => $this->user->id,
        ]);
        $secondShiftId = $this->openShiftOn($terminal, shiftNumber: 2);

        $this->artisan(self::COMMAND, [
            'shift' => $secondShiftId,
            '--reason' => 'trying to reuse the old release',
            '--closed-by' => $this->user->id,
            '--apply' => true,
        ])->assertExitCode(CloseOrphanedShiftCommand::EXIT_NOT_ORPHANED);

        $this->assertSame('OPEN', $this->shiftStatus($secondShiftId));
    }

    // ------------------------------------------------------------- dry run

    public function test_the_command_is_dry_run_by_default_and_writes_nothing(): void
    {
        [, $shiftId] = $this->orphanedShift();

        $this->artisan(self::COMMAND, [
            'shift' => $shiftId,
            '--reason' => 'device stolen mid-shift',
            '--closed-by' => $this->user->id,
        ])->assertExitCode(CloseOrphanedShiftCommand::SUCCESS);

        $this->assertSame('OPEN', $this->shiftStatus($shiftId));
        $this->assertSame(0, $this->orphanCloseAuditCount($shiftId));
    }

    // --------------------------------------------------------------- apply

    public function test_apply_closes_the_orphan_with_a_zero_variance_pair(): void
    {
        [$terminal, $shiftId] = $this->orphanedShift(openingCash: '100.000');

        $this->artisan(self::COMMAND, [
            'shift' => $shiftId,
            '--reason' => 'device stolen mid-shift; cash never recovered',
            '--closed-by' => $this->user->id,
            '--apply' => true,
        ])->assertExitCode(CloseOrphanedShiftCommand::SUCCESS);

        $row = DB::table('pos_shifts')->where('id', $shiftId)->first();
        $this->assertNotNull($row);
        $this->assertSame('CLOSED', $row->status);
        $this->assertNotNull($row->closed_at);
        $this->assertSame($this->user->id, $row->closed_by);

        // The ruling: expected = opening float + cash movements booked to the
        // shift; counted = the same; variance = 0. No cash is invented and no
        // shortage is asserted against a cashier who was never asked to count.
        $this->assertSame(0, bccomp((string) $row->expected_cash, '100', 4));
        $this->assertSame(0, bccomp((string) $row->actual_cash, '100', 4));
        $this->assertSame(0, bccomp((string) $row->variance, '0', 4));

        // `pos_shifts_variance_calc` is a PG CHECK: variance = actual − expected.
        $this->assertSame(
            0,
            bccomp(
                (string) $row->variance,
                bcsub((string) $row->actual_cash, (string) $row->expected_cash, 4),
                4,
            ),
        );

        $this->assertStringContainsString('device stolen mid-shift', (string) $row->notes);
        $this->assertNotNull($terminal->fresh());
    }

    /**
     * THE GATE-r1 CRITICAL, pinned.
     *
     * A v3 device shift books its cash movements as FISCAL EVENTS into
     * `pos_z_session_events` and writes NO `pos_cash_drawer_operations` row at
     * all (LEDGER ES-05); its cash takings live on `pos_receipts`. The first
     * version of this command summed the drawer table, so for the only
     * population it can act on it produced `opening_cash` and nothing else — a
     * figure that could never include the day's takings or the device's own
     * drops, and that `Nf525DataProvider::mapShift()` exports to the NF525 JET
     * as `EspecesAttendues` with `Ecart = 0` against the ORIGINAL cashier.
     *
     * Fixture is the device's own Z arithmetic
     * (`apps/pos/src/lib/offline/zReportService.ts:277-290`):
     * opening 100 + cash sales (60 tendered − 10 change) − payout 30 = 120.
     * The old derivation would have said 100 — asserted below so this test
     * fails if the movement source ever regresses.
     */
    public function test_expected_cash_for_a_v3_shift_is_the_float_plus_cash_sales_plus_z_session_movements(): void
    {
        [$terminal, $shiftId] = $this->orphanedShift(openingCash: '100.000');

        $this->cashSaleOn($terminal, sequence: 1, tendered: '60.000', changeDue: '10.000');
        $this->zSessionMovement($terminal, $shiftId, 'CASH_OUT', '30.000', sequenceNumber: 10);

        $this->artisan(self::COMMAND, [
            'shift' => $shiftId,
            '--reason' => 'device stolen mid-shift',
            '--closed-by' => $this->user->id,
            '--apply' => true,
        ])->assertExitCode(CloseOrphanedShiftCommand::SUCCESS);

        $row = DB::table('pos_shifts')->where('id', $shiftId)->first();
        $this->assertNotNull($row);
        $this->assertSame(0, bccomp((string) $row->expected_cash, '120', 4), 'expected = 100 + (60 − 10) − 30');
        $this->assertSame(0, bccomp((string) $row->actual_cash, '120', 4));
        $this->assertSame(0, bccomp((string) $row->variance, '0', 4));
        $this->assertSame(
            1,
            bccomp((string) $row->expected_cash, '100', 4),
            'The drawer-operations derivation would have written the bare float — that is the defect.',
        );
    }

    /**
     * THE GATE-r2 CRITICAL, pinned.
     *
     * The receipt window used to run to `now()`. Nothing stops a REPLACEMENT
     * device claiming the terminal and selling for weeks while the orphan sits
     * OPEN — `pos_receipts` carries no shift id and `PosCoreReceiptProjection`
     * never consults `pos_shifts`, so the replacement's receipts project
     * normally even while its `SESSION_OPEN` is being dropped. That is this
     * lane's own incident narrative. With an unbounded window the operator
     * running the command at month-end (the cadence the runbook prescribes)
     * would sweep another till's whole week of takings into the orphan's
     * `expected_cash`, which the JET exports as `EspecesAttendues` with
     * `Ecart = 0` against the ORIGINAL cashier — r1 understated the figure,
     * this overstated it.
     *
     * The window now ends at the authorising release's `occurred_at`.
     */
    public function test_a_receipt_posted_after_the_release_is_not_swept_into_the_orphans_expected_cash(): void
    {
        [$terminal, $shiftId] = $this->orphanedShift(openingCash: '100.000');

        // The orphan's own till, before the release.
        $this->cashSaleOn($terminal, sequence: 1, tendered: '50.000');
        // The REPLACEMENT till, after it. Same terminal — that is the point.
        $this->cashSaleOn($terminal, sequence: 2, tendered: '400.000', postedAt: now()->addHours(6));

        $this->artisan(self::COMMAND, [
            'shift' => $shiftId,
            '--reason' => 'device stolen mid-shift',
            '--closed-by' => $this->user->id,
            '--apply' => true,
        ])->assertExitCode(CloseOrphanedShiftCommand::SUCCESS);

        $row = DB::table('pos_shifts')->where('id', $shiftId)->first();
        $this->assertNotNull($row);
        $this->assertSame(0, bccomp((string) $row->expected_cash, '150', 4), 'expected = 100 + 50; the 400 belongs to the replacement till.');
        $this->assertSame(
            -1,
            bccomp((string) $row->expected_cash, '550', 4),
            'An unbounded window would have written 550 — another till\'s takings.',
        );
    }

    /**
     * GATE r2 IMPORTANT — cash collected against a customer credit account
     * physically enters this drawer, and the device folds it into its own
     * expected cash (`zReportService.ts:272-275`). Omitting it makes the server
     * figure short by exactly that amount and exports the shortfall as a
     * balanced count.
     *
     * The term is derivable: `AccountPaymentReceiptProjection` stores the whole
     * payload, and `AccountPaymentPayload::toArray()` emits `shift_id` and
     * `payment.method_code` / `payment.amount`.
     */
    public function test_a_cash_account_collection_raises_expected_cash(): void
    {
        [$terminal, $shiftId] = $this->orphanedShift(openingCash: '100.000');

        $this->accountCollection($terminal, $shiftId, methodCode: 'CASH', amount: '75.000');

        $this->artisan(self::COMMAND, [
            'shift' => $shiftId,
            '--reason' => 'device stolen mid-shift',
            '--closed-by' => $this->user->id,
            '--apply' => true,
        ])->assertExitCode(CloseOrphanedShiftCommand::SUCCESS);

        $this->assertSame(
            0,
            bccomp((string) DB::table('pos_shifts')->where('id', $shiftId)->value('expected_cash'), '175', 4),
        );
    }

    /**
     * A collection settled any other way never entered the drawer, and a
     * collection belonging to ANOTHER shift on the same terminal is not this
     * shift's money. Both must leave the figure alone.
     */
    public function test_a_non_cash_or_foreign_shift_account_collection_does_not_move_expected_cash(): void
    {
        [$terminal, $shiftId] = $this->orphanedShift(openingCash: '100.000');

        $this->accountCollection($terminal, $shiftId, methodCode: 'CARD', amount: '75.000');
        $this->accountCollection($terminal, Str::uuid()->toString(), methodCode: 'CASH', amount: '60.000');

        $this->artisan(self::COMMAND, [
            'shift' => $shiftId,
            '--reason' => 'device stolen mid-shift',
            '--closed-by' => $this->user->id,
            '--apply' => true,
        ])->assertExitCode(CloseOrphanedShiftCommand::SUCCESS);

        $this->assertSame(
            0,
            bccomp((string) DB::table('pos_shifts')->where('id', $shiftId)->value('expected_cash'), '100', 4),
        );
    }

    /**
     * A grandfathered collection with no `shift_id` cannot be attributed, and
     * the two alternatives — guessing it belongs to this shift, or dropping cash
     * that is sitting in the drawer — both write a wrong number into a certified
     * export. Same fail-closed standard `CASH_CORRECTION` gets.
     */
    public function test_an_account_collection_with_no_shift_id_refuses_the_close(): void
    {
        [$terminal, $shiftId] = $this->orphanedShift(openingCash: '100.000');

        $this->accountCollection($terminal, shiftId: null, methodCode: 'CASH', amount: '75.000');

        $this->artisan(self::COMMAND, [
            'shift' => $shiftId,
            '--reason' => 'device stolen mid-shift',
            '--closed-by' => $this->user->id,
            '--apply' => true,
        ])->assertExitCode(CloseOrphanedShiftCommand::EXIT_COLLECTION_UNATTRIBUTABLE);

        $this->assertSame('OPEN', $this->shiftStatus($shiftId));
        $this->assertSame(0, $this->orphanCloseAuditCount($shiftId));
    }

    /**
     * CASH_IN raises the drawer, mirroring the device's `deposit = +`
     * (`apps/pos/src/api/cashDrawerApi.ts:177`,
     * `zReportService.ts:264-276`).
     */
    public function test_a_cash_in_movement_raises_expected_cash(): void
    {
        [$terminal, $shiftId] = $this->orphanedShift(openingCash: '100.000');

        $this->zSessionMovement($terminal, $shiftId, 'CASH_IN', '45.000', sequenceNumber: 10);

        $this->artisan(self::COMMAND, [
            'shift' => $shiftId,
            '--reason' => 'device bricked',
            '--closed-by' => $this->user->id,
            '--apply' => true,
        ])->assertExitCode(CloseOrphanedShiftCommand::SUCCESS);

        $this->assertSame(
            0,
            bccomp((string) DB::table('pos_shifts')->where('id', $shiftId)->value('expected_cash'), '145', 4),
        );
    }

    /**
     * A v2/legacy shift has no `pos_z_session_events` at all — its movements are
     * server-side `pos_cash_drawer_operations`, which is what
     * `CashDrawerService::calculateExpectedCash()` (and therefore the v2 Z
     * report) sums. The orphan close must agree with the v2 Z, not with the v3
     * formula.
     *
     * Fixture: opening 100 + SALE 40 − REFUND 5 − PAYOUT 10 − DEPOSIT 25 = 100.
     */
    public function test_expected_cash_for_a_legacy_v2_shift_comes_from_the_drawer_operations(): void
    {
        [$terminal, $shiftId] = $this->orphanedShift(openingCash: '100.000', fiscalSchemaVersion: 2);

        $this->cashOperation($shiftId, 'SALE', '40.000', $this->receiptOn($terminal, 1)->id);
        $this->cashOperation($shiftId, 'REFUND', '5.000', $this->receiptOn($terminal, 2)->id);
        $this->cashOperation($shiftId, 'PAYOUT', '10.000');
        $this->cashOperation($shiftId, 'DEPOSIT', '25.000');

        $this->artisan(self::COMMAND, [
            'shift' => $shiftId,
            '--reason' => 'device bricked',
            '--closed-by' => $this->user->id,
            '--apply' => true,
        ])->assertExitCode(CloseOrphanedShiftCommand::SUCCESS);

        $row = DB::table('pos_shifts')->where('id', $shiftId)->first();
        $this->assertNotNull($row);
        $this->assertSame(0, bccomp((string) $row->expected_cash, '100', 4));
        $this->assertSame(0, bccomp((string) $row->variance, '0', 4));
    }

    /**
     * `opening_cash` is the float, and an `OPENING` drawer operation must NOT be
     * added on top of it — a legacy shift carries both and would otherwise have
     * its float counted twice.
     */
    public function test_an_opening_cash_drawer_operation_is_not_double_counted(): void
    {
        [, $shiftId] = $this->orphanedShift(openingCash: '100.000', fiscalSchemaVersion: 2);

        $this->cashOperation($shiftId, 'OPENING', '100.000');

        $this->artisan(self::COMMAND, [
            'shift' => $shiftId,
            '--reason' => 'device bricked',
            '--closed-by' => $this->user->id,
            '--apply' => true,
        ])->assertExitCode(CloseOrphanedShiftCommand::SUCCESS);

        $this->assertSame(
            0,
            bccomp((string) DB::table('pos_shifts')->where('id', $shiftId)->value('expected_cash'), '100', 4),
        );
    }

    /**
     * A v3 shift's drawer operations are NOT its movements — reading them would
     * be the old defect in reverse. A stray legacy row on a v3 shift must not
     * move the figure.
     */
    public function test_a_v3_shift_ignores_drawer_operations(): void
    {
        [, $shiftId] = $this->orphanedShift(openingCash: '100.000');

        $this->cashOperation($shiftId, 'PAYOUT', '70.000');

        $this->artisan(self::COMMAND, [
            'shift' => $shiftId,
            '--reason' => 'device bricked',
            '--closed-by' => $this->user->id,
            '--apply' => true,
        ])->assertExitCode(CloseOrphanedShiftCommand::SUCCESS);

        $this->assertSame(
            0,
            bccomp((string) DB::table('pos_shifts')->where('id', $shiftId)->value('expected_cash'), '100', 4),
        );
    }

    /**
     * GATE r1 CRITICAL 2 — a negative derived position must be a typed refusal,
     * not an uncaught `pos_shifts_positive_amounts` 23514 that leaves the orphan
     * OPEN and the terminal unusable, which is the one outcome this command
     * exists to prevent.
     *
     * Reached with real data, not a stub: float 100, a 300 payout out of takings
     * the server never saw.
     */
    public function test_a_negative_derived_expected_cash_is_refused_with_a_typed_exit_and_writes_nothing(): void
    {
        [$terminal, $shiftId] = $this->orphanedShift(openingCash: '100.000');

        $this->zSessionMovement($terminal, $shiftId, 'CASH_OUT', '300.000', sequenceNumber: 10);

        $this->artisan(self::COMMAND, [
            'shift' => $shiftId,
            '--reason' => 'device stolen mid-shift',
            '--closed-by' => $this->user->id,
            '--apply' => true,
        ])->assertExitCode(CloseOrphanedShiftCommand::EXIT_EXPECTED_CASH_NEGATIVE);

        $this->assertSame('OPEN', $this->shiftStatus($shiftId));
        $this->assertSame(0, $this->orphanCloseAuditCount($shiftId));
    }

    /**
     * FAIL CLOSED on a movement the server cannot sign.
     *
     * `CASH_CORRECTION` is the live case: it is the only movement whose
     * direction lives in the amount's SIGN rather than in its type, and it has
     * no device authoring path today, so there is no observed convention to
     * encode. Dropping it would return a figure that looks reasonable, is short
     * by whatever the correction was worth, and lands in the JET as a balanced
     * count — the exact failure the derivation was rewritten to end.
     */
    public function test_a_movement_the_server_cannot_sign_refuses_the_close_rather_than_dropping_it(): void
    {
        [$terminal, $shiftId] = $this->orphanedShift(openingCash: '100.000');

        $this->zSessionMovement($terminal, $shiftId, 'CASH_CORRECTION', '25.000', sequenceNumber: 10);

        $this->artisan(self::COMMAND, [
            'shift' => $shiftId,
            '--reason' => 'device stolen mid-shift',
            '--closed-by' => $this->user->id,
            '--apply' => true,
        ])->assertExitCode(CloseOrphanedShiftCommand::EXIT_MOVEMENTS_UNUSABLE);

        $this->assertSame('OPEN', $this->shiftStatus($shiftId));
        $this->assertSame(0, $this->orphanCloseAuditCount($shiftId));
    }

    /**
     * GATE r1 IMPORTANT — a soft-deleted (archived) terminal used to reach
     * `authorisingRelease(Shift, Terminal)` as NULL and die as a TypeError with
     * a stack trace. The eligibility read now uses `withTrashed()`, so an
     * archived terminal is still resolvable and its orphan still closable.
     */
    public function test_an_archived_terminal_still_resolves_and_its_orphan_is_closable(): void
    {
        [$terminal, $shiftId] = $this->orphanedShift();

        $terminal->delete();
        $this->assertSoftDeleted('pos_terminals', ['id' => $terminal->id]);

        $this->artisan(self::COMMAND, [
            'shift' => $shiftId,
            '--reason' => 'terminal archived after the device was written off',
            '--closed-by' => $this->user->id,
            '--apply' => true,
        ])->assertExitCode(CloseOrphanedShiftCommand::SUCCESS);

        $this->assertSame('CLOSED', $this->shiftStatus($shiftId));
    }

    /**
     * GATE r1 IMPORTANT — the audit row is the provenance, and
     * `DomainEventSubscriber::persistEvent()` swallows every `Throwable`, so the
     * dispatch cannot report its own failure. The command reads the row back
     * INSIDE its transaction and rolls the close back when it is absent: an
     * orphan still OPEN is recoverable, a closed fiscal shift with no record of
     * who closed it or why is not.
     *
     * Faked here by silencing the event, which is exactly the observable shape
     * of a subscriber that failed.
     */
    public function test_a_close_whose_audit_row_cannot_be_confirmed_is_rolled_back(): void
    {
        [, $shiftId] = $this->orphanedShift();

        Event::fake([OrphanedShiftClosedByOperator::class]);

        $this->artisan(self::COMMAND, [
            'shift' => $shiftId,
            '--reason' => 'device stolen mid-shift',
            '--closed-by' => $this->user->id,
            '--apply' => true,
        ])->assertExitCode(CloseOrphanedShiftCommand::EXIT_AUDIT_WRITE_FAILED);

        $this->assertSame('OPEN', $this->shiftStatus($shiftId), 'The close must have rolled back.');
        $this->assertNull(DB::table('pos_shifts')->where('id', $shiftId)->value('closed_at'));
        $this->assertSame(0, $this->orphanCloseAuditCount($shiftId));
    }

    /**
     * The audit event is the durable record that this shift was closed by an
     * OPERATOR and not by its device — the `pos_shifts` row itself cannot say
     * so, and the NF525 JET derives its `FERMETURE_CAISSE` from `closed_at`
     * with no field for provenance.
     */
    public function test_apply_writes_an_audit_event_naming_the_source_release(): void
    {
        [$terminal, $shiftId, $releaseAuditId] = $this->orphanedShiftWithReleaseId();

        $this->artisan(self::COMMAND, [
            'shift' => $shiftId,
            '--reason' => 'device stolen mid-shift',
            '--closed-by' => $this->user->id,
            '--apply' => true,
        ])->assertExitCode(CloseOrphanedShiftCommand::SUCCESS);

        $audit = AuditEvent::query()
            ->where('company_id', $this->company->id)
            ->where('event_type', 'shift.orphan_closed')
            ->where('aggregate_type', 'Shift')
            ->where('aggregate_id', $shiftId)
            ->first();

        $this->assertNotNull($audit, 'The orphan close must leave an audit row.');

        $payload = $audit->payload;
        $this->assertSame($terminal->id, $payload['terminal_id']);
        $this->assertSame('device stolen mid-shift', $payload['reason']);
        $this->assertSame($this->user->id, $payload['closed_by']);
        $this->assertSame($releaseAuditId, $payload['release_audit_event_id']);
    }

    /**
     * `shift.closed` is the DEVICE's closure event and it is what the NF525
     * data provider treats as a real cash-drawer closure. An administrative
     * orphan close must not forge one.
     */
    public function test_apply_does_not_forge_a_device_shift_closed_event(): void
    {
        [, $shiftId] = $this->orphanedShift();

        $this->artisan(self::COMMAND, [
            'shift' => $shiftId,
            '--reason' => 'device stolen mid-shift',
            '--closed-by' => $this->user->id,
            '--apply' => true,
        ])->assertExitCode(CloseOrphanedShiftCommand::SUCCESS);

        $this->assertSame(
            0,
            AuditEvent::query()
                ->where('company_id', $this->company->id)
                ->where('event_type', 'shift.closed')
                ->count(),
            'The orphan close must not masquerade as a device-authored shift close.',
        );
    }

    /**
     * `--apply` is a VALUE_NONE flag, but `tenants:run` (the documented
     * invocation) turns `--option=apply=1` into the STRING `'1'`. A bare
     * `(bool)` cast would also read `apply=false` as TRUE and write the close.
     * Both directions are pinned.
     */
    public function test_apply_is_honoured_in_the_string_form_tenants_run_passes(): void
    {
        [, $shiftId] = $this->orphanedShift();

        $this->artisan(self::COMMAND, [
            'shift' => $shiftId,
            '--reason' => 'device stolen mid-shift',
            '--closed-by' => $this->user->id,
            '--apply' => '1',
        ])->assertExitCode(CloseOrphanedShiftCommand::SUCCESS);

        $this->assertSame('CLOSED', $this->shiftStatus($shiftId));
    }

    public function test_a_falsey_apply_string_stays_a_dry_run(): void
    {
        [, $shiftId] = $this->orphanedShift();

        foreach (['0', 'false', 'no'] as $falsey) {
            $this->artisan(self::COMMAND, [
                'shift' => $shiftId,
                '--reason' => 'device stolen mid-shift',
                '--closed-by' => $this->user->id,
                '--apply' => $falsey,
            ])->assertExitCode(CloseOrphanedShiftCommand::SUCCESS);

            $this->assertSame('OPEN', $this->shiftStatus($shiftId), "--apply={$falsey} must not write.");
        }
    }

    public function test_re_running_after_a_successful_close_is_an_idempotent_no_op(): void
    {
        [, $shiftId] = $this->orphanedShift();

        $args = [
            'shift' => $shiftId,
            '--reason' => 'device stolen mid-shift',
            '--closed-by' => $this->user->id,
            '--apply' => true,
        ];

        $this->artisan(self::COMMAND, $args)->assertExitCode(CloseOrphanedShiftCommand::SUCCESS);
        $closedAt = DB::table('pos_shifts')->where('id', $shiftId)->value('closed_at');

        $this->artisan(self::COMMAND, $args)->assertExitCode(CloseOrphanedShiftCommand::SUCCESS);

        $this->assertSame(
            $closedAt,
            DB::table('pos_shifts')->where('id', $shiftId)->value('closed_at'),
            'A re-run must not move closed_at.',
        );
        $this->assertSame(1, $this->orphanCloseAuditCount($shiftId), 'A re-run must not write a second audit row.');
    }

    // ------------------------------------------------------------- helpers

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function terminal(array $overrides = []): Terminal
    {
        return Terminal::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'type' => TerminalType::Physical,
            'is_active' => true,
            'fiscal_schema_version' => 3,
            'hardware_identifier' => null,
        ], $overrides));
    }

    /**
     * The shape a device's SESSION_OPEN leaves behind: an OPEN `pos_shifts` row.
     */
    private function openShiftOn(Terminal $terminal, int $shiftNumber = 1, string $openingCash = '100.000'): string
    {
        $shiftId = Str::uuid()->toString();

        // Opened four hours ago so the fixtures have room either side of the
        // forced release, which happens at `now()`. The window this command
        // derives over is [opened_at, release occurred_at], and several cases
        // below turn on which side of that bound a row falls.
        DB::table('pos_shifts')->insert([
            'id' => $shiftId,
            'terminal_id' => $terminal->id,
            'cashier_id' => $this->user->id,
            'shift_number' => $shiftNumber,
            'status' => 'OPEN',
            'opening_cash' => $openingCash,
            'opened_at' => now()->subHours(4),
            'created_at' => now()->subHours(4),
            'updated_at' => now()->subHours(4),
        ]);

        return $shiftId;
    }

    /**
     * Force-release the terminal through the REAL endpoint so the audit row the
     * command matches on is the one production actually writes.
     */
    private function forceRelease(Terminal $terminal, string $shiftId): string
    {
        $this->postJson("/api/v1/pos/terminals/{$terminal->id}/release", [
            'force' => true,
            'reason' => 'Till stolen mid-shift; shift cannot be closed from the device',
        ])->assertStatus(200);

        $audit = AuditEvent::query()
            ->where('company_id', $this->company->id)
            ->where('event_type', 'terminal.released')
            ->where('aggregate_id', $terminal->id)
            ->orderByDesc('occurred_at')
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame($shiftId, $audit->payload['open_shift_id']);

        return $audit->id;
    }

    /**
     * @return array{0: Terminal, 1: string}
     */
    private function orphanedShift(string $openingCash = '100.000', int $fiscalSchemaVersion = 3): array
    {
        $terminal = $this->terminal([
            'hardware_identifier' => 'HW-LOST-BOX',
            'fiscal_schema_version' => $fiscalSchemaVersion,
        ]);
        $shiftId = $this->openShiftOn($terminal, openingCash: $openingCash);
        $this->forceRelease($terminal, $shiftId);

        return [$terminal, $shiftId];
    }

    /**
     * A fiscalized CASH sale inside the shift's window, with change handed back.
     *
     * Inserted the way the v3 projection leaves it — `pos_receipts` +
     * `pos_receipt_payments` — because that is where a device shift's cash
     * takings actually live; there is no `SALE` cash-drawer operation in
     * production at all (`CashDrawerService::recordSale()` has no callers).
     */
    private function cashSaleOn(
        Terminal $terminal,
        int $sequence,
        string $tendered,
        string $changeDue = '0.000',
        ?CarbonInterface $postedAt = null,
    ): void {
        $receipt = $this->receiptOn($terminal, $sequence, $changeDue, $postedAt);

        DB::table('pos_receipt_payments')->insert([
            'id' => (string) Str::uuid(),
            'receipt_id' => $receipt->id,
            'payment_method_id' => $this->cashPaymentMethod()->id,
            'payment_type' => 'CASH',
            'payment_method_code' => 'CASH',
            'amount' => $tendered,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function cashPaymentMethod(): PaymentMethod
    {
        return $this->cashPaymentMethod ??= PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH',
            'is_physical' => true,
        ]);
    }

    /**
     * A device-authored cash movement, projected the REAL way: a verified
     * `z_session` fiscal event run through `ZSessionLifecycleProjection`, which
     * writes the `pos_z_session_events` row. Hand-writing that row would prove
     * nothing about the shape production actually produces.
     */
    private function zSessionMovement(
        Terminal $terminal,
        string $shiftId,
        string $movementType,
        string $amount,
        int $sequenceNumber,
    ): void {
        $payload = [
            'amount' => $amount,
            'approval' => null,
            'business_date' => now()->toDateString(),
            'cash_drawer_operation_id' => null,
            'currency_code' => 'TND',
            'currency_scale' => 3,
            'event_time_device' => now()->subHours(2)->toIso8601String(),
            'movement_id' => (string) Str::uuid(),
            'movement_type' => $movementType,
            'operator_id' => $this->user->id,
            'operator_name' => 'Fixture Operator',
            'reason_code' => 'fixture',
            'reason_text' => null,
            'session_id' => $shiftId,
            'shift_id' => $shiftId,
            'training_flag' => false,
        ];
        $canonicalBytes = json_encode(['payload' => $payload], JSON_THROW_ON_ERROR);

        $event = FiscalEvent::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'terminal_id' => $terminal->id,
            'operator_id' => $this->user->id,
            'event_type' => FiscalEventType::from($movementType),
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => $sequenceNumber,
            'event_time_device' => now()->subHours(2),
            'business_date' => now()->toDateString(),
            'chain_context' => 'z_session',
            'server_received_at' => now()->subHours(2),
            'source_event_class' => 'pos_cash_movement',
            'source_event_id' => $payload['movement_id'],
            'canonical_bytes' => $canonicalBytes,
            'previous_hash' => str_repeat('a', 64),
            'current_hash' => hash('sha256', $canonicalBytes),
            'signature_status' => SignatureStatus::NotRequired,
            'integrity_status' => IntegrityStatus::Verified,
            'payload' => $payload,
            'payload_parse_status' => PayloadParseStatus::Parsed,
        ])->refresh();

        $this->app->make(CompanyContext::class)->clear();
        $this->app->make(ZSessionLifecycleProjection::class)->apply($event);
    }

    /**
     * @return array{0: Terminal, 1: string, 2: string}
     */
    private function orphanedShiftWithReleaseId(): array
    {
        $terminal = $this->terminal(['hardware_identifier' => 'HW-LOST-BOX']);
        $shiftId = $this->openShiftOn($terminal);
        $releaseAuditId = $this->forceRelease($terminal, $shiftId);

        return [$terminal, $shiftId, $releaseAuditId];
    }

    /**
     * A device-authored ACCOUNT_PAYMENT, in the shape
     * `AccountPaymentReceiptProjection` leaves behind: the `payload_snapshot`
     * IS `AccountPaymentPayload::toArray()`, carrying `shift_id` and the
     * `payment` leg.
     *
     * `$shiftId = null` models a grandfathered row from before the device
     * required the field.
     */
    private function accountCollection(Terminal $terminal, ?string $shiftId, string $methodCode, string $amount): void
    {
        $paymentUuid = (string) Str::uuid();

        $snapshot = [
            'account_payment_uuid' => $paymentUuid,
            'business_date' => now()->toDateString(),
            'currency_code' => 'TND',
            'currency_scale' => 3,
            'payment' => [
                'amount' => $amount,
                'foreign_currency_amount' => null,
                'foreign_currency_code' => null,
                'instrument_serial' => null,
                'instrument_type' => null,
                'method_code' => $methodCode,
                'repository_id' => null,
            ],
            'receipt_type_code' => 'ACCOUNT_PAYMENT',
            'terminal_id' => $terminal->id,
            'training_flag' => false,
        ];
        if ($shiftId !== null) {
            $snapshot['shift_id'] = $shiftId;
        }

        $canonicalBytes = json_encode(['payload' => $snapshot], JSON_THROW_ON_ERROR);

        $event = FiscalEvent::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'terminal_id' => $terminal->id,
            'operator_id' => $this->user->id,
            'event_type' => FiscalEventType::ACCOUNT_PAYMENT,
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => ++$this->accountCollectionSequence,
            'event_time_device' => now()->subHours(2),
            'business_date' => now()->toDateString(),
            'chain_context' => 'operational',
            'server_received_at' => now()->subHours(2),
            'source_event_class' => 'pos_account_payment',
            'source_event_id' => $paymentUuid,
            'canonical_bytes' => $canonicalBytes,
            'previous_hash' => str_repeat('b', 64),
            'current_hash' => hash('sha256', $canonicalBytes),
            'signature_status' => SignatureStatus::NotRequired,
            'integrity_status' => IntegrityStatus::Verified,
            'payload' => $snapshot,
            'payload_parse_status' => PayloadParseStatus::Parsed,
        ]);

        DB::table('pos_account_payment_receipts')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'fiscal_event_id' => $event->id,
            'account_payment_uuid' => $paymentUuid,
            'customer_id' => (string) Str::uuid(),
            'customer_name' => 'Fixture Customer',
            'amount' => $amount,
            'currency_code' => 'TND',
            'payload_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subHours(2),
        ]);
    }

    private function cashOperation(string $shiftId, string $type, string $amount, ?string $receiptId = null): void
    {
        CashDrawerOperation::create([
            'shift_id' => $shiftId,
            'operation_type' => $type,
            'amount' => $amount,
            'user_id' => $this->user->id,
            'reason' => 'fixture',
            // `pos_cash_drawer_operations_receipt_logic` (PG CHECK): SALE and
            // REFUND require a receipt; every other type forbids one.
            'receipt_id' => $receiptId,
        ]);
    }

    /**
     * Minimal posted receipt — the CHECK above only needs the FK to resolve.
     */
    private function receiptOn(
        Terminal $terminal,
        int $sequence,
        string $changeDue = '0.000',
        ?CarbonInterface $postedAt = null,
    ): Receipt {
        return Receipt::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'terminal_id' => $terminal->id,
            'receipt_number' => sprintf('%s-2026-%08d', $terminal->code, $sequence),
            'chain_sequence' => $sequence,
            'receipt_year' => 2026,
            'fiscal_hash' => hash('sha256', "o30-orphan-{$sequence}"),
            'vat_breakdown_hash' => hash('sha256', 'vat'),
            'payment_methods_hash' => hash('sha256', 'payment'),
            'posted_at' => $postedAt ?? now()->subHours(2),
            'cashier_id' => $this->user->id,
            'cashier_name' => 'Fixture Cashier',
            'subtotal' => '100.000',
            'tax_amount' => '19.000',
            'discount_amount' => '0.000',
            'total' => '119.000',
            'currency' => 'TND',
            'change_due' => $changeDue,
            'fiscal_status' => FiscalStatus::Fiscalized,
            'is_training' => false,
            'is_voided' => false,
        ]);
    }

    private function shiftStatus(string $shiftId): ?string
    {
        $status = DB::table('pos_shifts')->where('id', $shiftId)->value('status');

        return is_string($status) ? $status : null;
    }

    private function orphanCloseAuditCount(string $shiftId): int
    {
        return AuditEvent::query()
            ->where('company_id', $this->company->id)
            ->where('event_type', 'shift.orphan_closed')
            ->where('aggregate_id', $shiftId)
            ->count();
    }
}
