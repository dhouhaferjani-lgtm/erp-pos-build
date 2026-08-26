<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityExceptionClass;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Projections\ZSessionLifecycleProjection;
use App\Modules\POS\Commands\CloseOrphanedShiftCommand;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Events\TerminalReleased;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\POS\Presentation\Resources\ShiftResource;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 2 — `pos_shifts` is a projection of the device-authored SESSION_OPEN
 * fiscal event (the device is authoritative; pos_shifts is derived).
 */
final class PosShiftProjectionTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $companyId;

    private User $cashier;

    private Terminal $terminal;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        $this->tenantId = $tenant->id;
        $company = Company::factory()->create(['tenant_id' => $this->tenantId]);
        $this->companyId = $company->id;
        $this->cashier = User::factory()->create([
            'tenant_id' => $this->tenantId,
            'name' => 'Default Cashier',
        ]);
        $location = Location::factory()->create(['company_id' => $this->companyId]);
        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'location_id' => $location->id,
            'code' => 'T001',
        ]);
    }

    public function test_session_open_projects_an_open_pos_shift_keyed_by_the_device_uuid(): void
    {
        $shiftId = Str::uuid()->toString();
        $event = $this->makeSessionOpenEvent($shiftId, shiftNumber: 7);

        $this->app->make(ZSessionLifecycleProjection::class)->apply($event);

        $shift = Shift::query()->find($shiftId);
        $this->assertNotNull($shift);
        $this->assertSame($shiftId, $shift->id);
        $this->assertSame($this->terminal->id, $shift->terminal_id);
        $this->assertSame($this->cashier->id, $shift->cashier_id);
        $this->assertSame(7, $shift->shift_number);
        $this->assertSame(ShiftStatus::Open, $shift->status);
        $this->assertSame('100.0000', $shift->opening_cash);
        $this->assertNull($shift->closed_at);
    }

    public function test_session_open_projection_is_idempotent_on_redelivery(): void
    {
        $shiftId = Str::uuid()->toString();
        $projector = $this->app->make(ZSessionLifecycleProjection::class);

        // The outbox can re-deliver the SAME fiscal event; re-applying must not
        // create a second shift.
        $event = $this->makeSessionOpenEvent($shiftId, shiftNumber: 1);
        $projector->apply($event);
        $projector->apply($event);

        $this->assertSame(1, Shift::query()->where('id', $shiftId)->count());
    }

    public function test_replayed_session_open_for_an_already_open_terminal_is_an_idempotent_noop(): void
    {
        $projector = $this->app->make(ZSessionLifecycleProjection::class);

        $firstShiftId = Str::uuid()->toString();
        $projector->apply($this->makeSessionOpenEvent($firstShiftId, shiftNumber: 1));

        // A stale/replayed SESSION_OPEN with a DIFFERENT shift id while the
        // terminal already has an open shift must NOT crash the projector
        // (would dead-letter) and must NOT open a second shift (Codex F-15).
        $staleShiftId = Str::uuid()->toString();
        $projector->apply($this->makeSessionOpenEvent($staleShiftId, shiftNumber: 2, sequenceNumber: 2));

        $this->assertSame(1, Shift::query()->where('terminal_id', $this->terminal->id)->count());
        $this->assertNull(Shift::query()->find($staleShiftId));
        $this->assertSame(ShiftStatus::Open, Shift::query()->find($firstShiftId)->status);
    }

    public function test_session_open_projection_skips_quarantined_events(): void
    {
        $shiftId = Str::uuid()->toString();
        $event = $this->makeSessionOpenEvent($shiftId, shiftNumber: 1, overrides: [
            'integrity_status' => IntegrityStatus::Quarantined,
            'integrity_exception_class' => IntegrityExceptionClass::CanonicalParseFailure,
            'integrity_exception_reason' => 'payload_extra_field:shift_number',
        ]);

        $this->app->make(ZSessionLifecycleProjection::class)->apply($event);

        $this->assertNull(Shift::query()->find($shiftId));
    }

    public function test_pos_shifts_terminal_shift_number_is_unique(): void
    {
        Shift::create([
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->cashier->id,
            'shift_number' => 5,
            'opening_cash' => '0.0000',
            'status' => ShiftStatus::Closed,
            'opened_at' => '2026-06-14 08:00:00',
            'closed_at' => '2026-06-14 12:00:00',
            'closed_by' => $this->cashier->id,
        ]);

        $this->expectException(QueryException::class);
        Shift::create([
            'terminal_id' => $this->terminal->id,
            'cashier_id' => $this->cashier->id,
            'shift_number' => 5,
            'opening_cash' => '0.0000',
            'status' => ShiftStatus::Closed,
            'opened_at' => '2026-06-14 13:00:00',
            'closed_at' => '2026-06-14 18:00:00',
            'closed_by' => $this->cashier->id,
        ]);
    }

    public function test_session_close_closes_the_projected_pos_shift(): void
    {
        $shiftId = Str::uuid()->toString();
        $projector = $this->app->make(ZSessionLifecycleProjection::class);
        $projector->apply($this->makeSessionOpenEvent($shiftId, shiftNumber: 1));

        $projector->apply($this->makeSessionCloseEvent($shiftId, sequenceNumber: 2));

        $shift = Shift::query()->findOrFail($shiftId);
        $this->assertSame(ShiftStatus::Closed, $shift->status);
        // pos_shifts_closed_logic: CLOSED requires closed_at + closed_by set.
        $this->assertNotNull($shift->closed_at);
        $this->assertSame($this->cashier->id, $shift->closed_by);
        $this->assertSame('150.0000', $shift->expected_cash);
        $this->assertSame('150.0000', $shift->actual_cash);
        $this->assertSame('0.0000', $shift->variance);
    }

    public function test_session_close_projection_is_idempotent(): void
    {
        $shiftId = Str::uuid()->toString();
        $projector = $this->app->make(ZSessionLifecycleProjection::class);
        $projector->apply($this->makeSessionOpenEvent($shiftId, shiftNumber: 1));
        $closeEvent = $this->makeSessionCloseEvent($shiftId, sequenceNumber: 2);

        $projector->apply($closeEvent);
        $projector->apply($closeEvent);

        $shift = Shift::query()->findOrFail($shiftId);
        $this->assertSame(ShiftStatus::Closed, $shift->status);
        $this->assertSame(1, Shift::query()->where('id', $shiftId)->count());
    }

    public function test_session_close_before_its_open_throws_for_retry(): void
    {
        // Per-event projection jobs carry no cross-row ordering guarantee, so a
        // SESSION_CLOSE can be picked up before its SESSION_OPEN. The close must
        // throw (retryable) — never silently no-op and lose the close — so it
        // re-applies once the open's pos_shift row exists.
        $shiftId = Str::uuid()->toString();

        $this->expectException(\RuntimeException::class);
        $this->app->make(ZSessionLifecycleProjection::class)
            ->apply($this->makeSessionCloseEvent($shiftId, sequenceNumber: 1));
    }

    public function test_session_close_maps_balanced_severity_to_null(): void
    {
        // The device emits variance_severity='balanced' for a zero-variance
        // close, but pos_shifts.variance_severity only allows
        // info|warning|critical|NULL (PG CHECK). 'balanced' must map to NULL.
        $shiftId = Str::uuid()->toString();
        $projector = $this->app->make(ZSessionLifecycleProjection::class);
        $projector->apply($this->makeSessionOpenEvent($shiftId, shiftNumber: 1));
        $projector->apply($this->makeSessionCloseEvent($shiftId, sequenceNumber: 2));

        $this->assertNull(Shift::query()->findOrFail($shiftId)->variance_severity);
    }

    public function test_session_close_preserves_a_valid_severity(): void
    {
        $shiftId = Str::uuid()->toString();
        $projector = $this->app->make(ZSessionLifecycleProjection::class);
        $projector->apply($this->makeSessionOpenEvent($shiftId, shiftNumber: 1));
        $projector->apply($this->makeSessionCloseEvent($shiftId, sequenceNumber: 2, payloadOverrides: ['variance_severity' => 'warning']));

        $this->assertSame('warning', Shift::query()->findOrFail($shiftId)->variance_severity);
    }

    public function test_shift_resource_exposes_device_reconcile_fields(): void
    {
        $shiftId = Str::uuid()->toString();
        $this->app->make(ZSessionLifecycleProjection::class)
            ->apply($this->makeSessionOpenEvent($shiftId, shiftNumber: 4));
        $shift = Shift::query()->findOrFail($shiftId);

        $array = ShiftResource::make($shift)->toArray(request());

        // The device reconcile read round-trips shift_number + the one-id
        // session_id (== shift id) + opened_at_device (Codex F-6/F-16).
        $this->assertSame(4, $array['shift_number']);
        $this->assertSame($shiftId, $array['session_id']);
        $this->assertSame($shift->opened_at->toIso8601String(), $array['opened_at_device']);
    }

    // ------------------------------------------------- O-30 orphaned shift

    /**
     * LEDGER O-30, HALF ONE — the defect, pinned as it behaves TODAY.
     *
     * A forced terminal release leaves the OPEN `pos_shifts` row behind. The
     * replacement device then binds to the same terminal and opens its own
     * shift — and `projectPosShiftOpen()` sees a different shift already OPEN
     * on that terminal and silently returns. Nothing dead-letters, nothing
     * warns: the new till's shift simply does not exist server-side, which is
     * why its SESSION_CLOSE retries to exhaustion and its Z report can never
     * land (`pos_z_reports.shift_id` is FK-RESTRICTed).
     *
     * This is NOT a bug in the projection — the drop is what keeps
     * `pos_shifts_one_open_per_terminal` from dead-lettering the projector on a
     * replayed open. The bug was that nothing could clear the orphan.
     */
    public function test_a_replacement_devices_session_open_is_dropped_while_the_orphan_is_open(): void
    {
        $orphanId = $this->orphanShiftLeftByForcedRelease();

        $replacementShiftId = Str::uuid()->toString();
        $this->app->make(CompanyContext::class)->clear();
        $this->app->make(ZSessionLifecycleProjection::class)
            ->apply($this->makeSessionOpenEvent($replacementShiftId, shiftNumber: 2, sequenceNumber: 2));

        $this->assertNull(
            Shift::query()->find($replacementShiftId),
            'While the orphan is OPEN the replacement device is invisible server-side.',
        );
        $this->assertSame(
            ShiftStatus::Open,
            Shift::query()->findOrFail($orphanId)->status,
            'And nothing has closed the orphan.',
        );
    }

    /**
     * LEDGER O-30, HALF TWO — the remedy, end to end.
     *
     * `pos:shift:close-orphaned` closes the orphan, and the SAME SESSION_OPEN
     * that was dropped a moment ago now projects. This is the assertion that
     * makes the command worth having: closing the row is not the goal, getting
     * the replacement till back onto the server's projections is.
     */
    public function test_a_replacement_devices_session_open_projects_once_the_orphan_is_closed(): void
    {
        $orphanId = $this->orphanShiftLeftByForcedRelease();

        $this->artisan('pos:shift:close-orphaned', [
            'shift' => $orphanId,
            '--reason' => 'Till stolen 2026-06-14; device never recovered',
            '--closed-by' => $this->cashier->id,
            '--apply' => true,
        ])->assertExitCode(CloseOrphanedShiftCommand::SUCCESS);

        $this->assertSame(ShiftStatus::Closed, Shift::query()->findOrFail($orphanId)->status);

        $replacementShiftId = Str::uuid()->toString();
        // Rule 20: the projector runs on a queue worker with NO CompanyContext.
        // Binding one here (the HTTP shape) would mask that reality.
        $this->app->make(CompanyContext::class)->clear();
        $this->app->make(ZSessionLifecycleProjection::class)
            ->apply($this->makeSessionOpenEvent($replacementShiftId, shiftNumber: 2, sequenceNumber: 2));

        $replacement = Shift::query()->find($replacementShiftId);
        $this->assertNotNull($replacement, 'Once the orphan is closed the replacement device projects again.');
        $this->assertSame(ShiftStatus::Open, $replacement->status);
        $this->assertSame($this->terminal->id, $replacement->terminal_id);
        $this->assertSame(2, $replacement->shift_number);
    }

    /**
     * LEDGER C-17(viii), the half the row lock in `release()` could NOT buy on
     * its own.
     *
     * `TerminalController::release()` probes `pos_shifts` for an OPEN row under
     * `lockForUpdate()` on the terminal — but a lock only serialises writers who
     * take THE SAME LOCK, and this projection (the v3 shift-open path) took
     * none. So a `SESSION_OPEN` landing between the probe and the release's
     * commit opened a shift the release had just decided did not exist: on the
     * non-forced arm the release succeeded against an open shift it should have
     * refused, and on the FORCED arm the shift was orphaned WITHOUT being named
     * in the `terminal.released` audit row — which is precisely the evidence
     * `pos:shift:close-orphaned` demands before it will close anything. The
     * orphan would have been unresolvable by the very command that exists to
     * resolve it.
     *
     * The projection now takes the same terminal row lock before it decides
     * whether the terminal already has an OPEN shift, so the two paths
     * serialise and `release()`'s probe is still true when it commits.
     *
     * PostgreSQL only: `SQLiteGrammar::compileLock()` returns '', so `FOR UPDATE`
     * is compiled away and the ordering could never be observed on the SQLite leg.
     */
    public function test_session_open_takes_the_terminal_row_lock_before_inserting_the_shift(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('FOR UPDATE is compiled away by SQLiteGrammar::compileLock().');
        }

        $shiftId = Str::uuid()->toString();
        $event = $this->makeSessionOpenEvent($shiftId, shiftNumber: 1);

        /** @var list<string> $statements */
        $statements = [];
        DB::listen(function (QueryExecuted $query) use (&$statements): void {
            $statements[] = strtolower($query->sql);
        });

        $this->app->make(CompanyContext::class)->clear();
        $this->app->make(ZSessionLifecycleProjection::class)->apply($event);

        $lockAt = null;
        $insertAt = null;
        foreach ($statements as $i => $sql) {
            if ($lockAt === null && str_contains($sql, 'from "pos_terminals"') && str_contains($sql, 'for update')) {
                $lockAt = $i;
            }
            if ($insertAt === null && str_contains($sql, 'insert into "pos_shifts"')) {
                $insertAt = $i;
            }
        }

        $this->assertNotNull($lockAt, 'projectPosShiftOpen() must take the terminal row FOR UPDATE.');
        $this->assertNotNull($insertAt, 'The shift was never inserted — the trace point moved.');
        $this->assertLessThan($insertAt, $lockAt, 'The terminal row lock must be taken BEFORE the shift insert.');

        $this->assertNotNull(Shift::query()->find($shiftId));
    }

    /**
     * The shape a forced release leaves behind: an OPEN shift on a terminal
     * whose binding has been cleared, plus the `terminal.released` audit row
     * (forced, naming the shift) that the command demands as its authorisation.
     */
    private function orphanShiftLeftByForcedRelease(): string
    {
        $orphanId = Str::uuid()->toString();
        $this->app->make(CompanyContext::class)->clear();
        $this->app->make(ZSessionLifecycleProjection::class)
            ->apply($this->makeSessionOpenEvent($orphanId, shiftNumber: 1));

        $this->terminal->update(['hardware_identifier' => null]);

        event(new TerminalReleased(
            terminalId: $this->terminal->id,
            terminalCode: $this->terminal->code,
            companyId: $this->companyId,
            hardwareIdentifier: 'HW-LOST-BOX',
            reason: 'Till stolen mid-shift; shift cannot be closed from the device',
            releasedBy: $this->cashier->id,
            forced: true,
            openShiftId: $orphanId,
        ));

        return $orphanId;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeSessionOpenEvent(string $shiftId, int $shiftNumber, int $sequenceNumber = 1, array $overrides = []): FiscalEvent
    {
        $payload = [
            'business_date' => '2026-06-14',
            'currency_code' => 'TND',
            'currency_scale' => 3,
            'opened_at_device' => '2026-06-14T08:00:00.000Z',
            'opening_float_amount' => '100.000',
            'operator_id' => $this->cashier->id,
            'operator_name' => 'Default Cashier',
            'session_id' => $shiftId,
            'shift_id' => $shiftId,
            'shift_number' => $shiftNumber,
            'terminal_id' => $this->terminal->id,
            'terminal_label' => 'T001',
            'training_flag' => false,
        ];
        $canonicalBytes = json_encode(['payload' => $payload], JSON_THROW_ON_ERROR);

        return FiscalEvent::query()->create(array_merge([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'terminal_id' => $this->terminal->id,
            'operator_id' => $this->cashier->id,
            'event_type' => FiscalEventType::SESSION_OPEN,
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => $sequenceNumber,
            'event_time_device' => '2026-06-14 08:00:00',
            'business_date' => '2026-06-14',
            'chain_context' => 'z_session',
            'last_server_time_seen' => null,
            'server_received_at' => '2026-06-14 08:00:01',
            'reference_event_id' => null,
            'reference_document_id' => null,
            'source_event_class' => 'pos_session',
            'source_event_id' => $shiftId,
            'partner_id' => null,
            'partner_identity_snapshot' => null,
            'canonical_bytes' => $canonicalBytes,
            'previous_hash' => str_repeat('a', 64),
            'current_hash' => hash('sha256', $canonicalBytes),
            'signature_status' => SignatureStatus::NotRequired,
            'integrity_status' => IntegrityStatus::Verified,
            'integrity_exception_class' => null,
            'integrity_exception_reason' => null,
            'payload' => $payload,
            'payload_parse_status' => PayloadParseStatus::Parsed,
        ], $overrides))->refresh();
    }

    /**
     * @param  array<string, mixed>  $payloadOverrides
     * @param  array<string, mixed>  $overrides
     */
    private function makeSessionCloseEvent(string $shiftId, int $sequenceNumber, array $payloadOverrides = [], array $overrides = []): FiscalEvent
    {
        $closeUuid = Str::uuid()->toString();
        $payload = array_merge([
            'business_date' => '2026-06-14',
            'closure_status' => 'closed',
            'counted_cash' => '150.000',
            'expected_cash' => '150.000',
            'generated_at_device' => '2026-06-14T18:00:00.000Z',
            'manager_approval' => null,
            'operator_id' => $this->cashier->id,
            'operator_name' => 'Default Cashier',
            'session_close_uuid' => $closeUuid,
            'session_id' => $shiftId,
            'shift_id' => $shiftId,
            'terminal_id' => $this->terminal->id,
            'training_flag' => false,
            'variance_amount' => '0.000',
            'variance_direction' => 'balanced',
            'variance_reason' => null,
            'variance_severity' => 'balanced',
        ], $payloadOverrides);
        $canonicalBytes = json_encode(['payload' => $payload], JSON_THROW_ON_ERROR);

        return FiscalEvent::query()->create(array_merge([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'terminal_id' => $this->terminal->id,
            'operator_id' => $this->cashier->id,
            'event_type' => FiscalEventType::SESSION_CLOSE,
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => $sequenceNumber,
            'event_time_device' => '2026-06-14 18:00:00',
            'business_date' => '2026-06-14',
            'chain_context' => 'z_session',
            'last_server_time_seen' => null,
            'server_received_at' => '2026-06-14 18:00:01',
            'reference_event_id' => null,
            'reference_document_id' => null,
            'source_event_class' => 'pos_session_close',
            'source_event_id' => $closeUuid,
            'partner_id' => null,
            'partner_identity_snapshot' => null,
            'canonical_bytes' => $canonicalBytes,
            'previous_hash' => str_repeat('a', 64),
            'current_hash' => hash('sha256', $canonicalBytes),
            'signature_status' => SignatureStatus::NotRequired,
            'integrity_status' => IntegrityStatus::Verified,
            'integrity_exception_class' => null,
            'integrity_exception_reason' => null,
            'payload' => $payload,
            'payload_parse_status' => PayloadParseStatus::Parsed,
        ], $overrides))->refresh();
    }
}
