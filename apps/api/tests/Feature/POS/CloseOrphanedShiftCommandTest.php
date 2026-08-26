<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Compliance\Domain\AuditEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Commands\CloseOrphanedShiftCommand;
use App\Modules\POS\Domain\CashDrawerOperation;
use App\Modules\POS\Domain\Enums\TerminalType;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_expected_cash_is_the_opening_float_plus_the_shifts_cash_movements(): void
    {
        [$terminal, $shiftId] = $this->orphanedShift(openingCash: '100.000');

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
        // 100 + 40 − 5 − 10 − 25 = 100
        $this->assertSame(0, bccomp((string) $row->expected_cash, '100', 4));
        $this->assertSame(0, bccomp((string) $row->actual_cash, '100', 4));
        $this->assertSame(0, bccomp((string) $row->variance, '0', 4));
    }

    /**
     * `opening_cash` is the float, and an `OPENING` cash-drawer OPERATION must
     * NOT be added on top of it — a legacy shift carries both and would
     * otherwise have its float counted twice.
     */
    public function test_an_opening_cash_drawer_operation_is_not_double_counted(): void
    {
        [, $shiftId] = $this->orphanedShift(openingCash: '100.000');

        $this->cashOperation($shiftId, 'OPENING', '100.000');

        $this->artisan(self::COMMAND, [
            'shift' => $shiftId,
            '--reason' => 'device bricked',
            '--closed-by' => $this->user->id,
            '--apply' => true,
        ])->assertExitCode(CloseOrphanedShiftCommand::SUCCESS);

        $row = DB::table('pos_shifts')->where('id', $shiftId)->first();
        $this->assertNotNull($row);
        $this->assertSame(0, bccomp((string) $row->expected_cash, '100', 4));
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

        DB::table('pos_shifts')->insert([
            'id' => $shiftId,
            'terminal_id' => $terminal->id,
            'cashier_id' => $this->user->id,
            'shift_number' => $shiftNumber,
            'status' => 'OPEN',
            'opening_cash' => $openingCash,
            'opened_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
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
    private function orphanedShift(string $openingCash = '100.000'): array
    {
        $terminal = $this->terminal(['hardware_identifier' => 'HW-LOST-BOX']);
        $shiftId = $this->openShiftOn($terminal, openingCash: $openingCash);
        $this->forceRelease($terminal, $shiftId);

        return [$terminal, $shiftId];
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
    private function receiptOn(Terminal $terminal, int $sequence): Receipt
    {
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
            'posted_at' => now(),
            'cashier_id' => $this->user->id,
            'cashier_name' => 'Fixture Cashier',
            'subtotal' => '100.000',
            'tax_amount' => '19.000',
            'discount_amount' => '0.000',
            'total' => '119.000',
            'currency' => 'TND',
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
