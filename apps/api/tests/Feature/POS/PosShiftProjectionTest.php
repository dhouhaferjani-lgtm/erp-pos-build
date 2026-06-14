<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityExceptionClass;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Projections\ZSessionLifecycleProjection;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\POS\Presentation\Resources\ShiftResource;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
