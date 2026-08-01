<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\ProjectionStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * v3-refund-chain-integration spec §5.1/§5.2 —
 * `GET /api/v1/fiscal/dead-lettered-projections` (+ `/{fiscal_event_id}`).
 *
 * Both addressable classes (dead-lettered projection, ingress-quarantine),
 * the projector filter, the write_off_action_url surfaced on every row,
 * and the permission gate (403 deny path).
 */
final class DeadLetteredProjectionsControllerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->operator = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $this->operator->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);
        $this->app->make(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->operator->givePermissionTo('fiscal.refunds.manage_dead_letters');
    }

    public function test_index_lists_a_dead_lettered_projection_row_with_write_off_url(): void
    {
        $event = $this->storeFiscalEvent();
        $this->storeProjectionRow($event->id, 'pos_core_receipt', ProjectionStatus::DeadLettered);

        Sanctum::actingAs($this->operator);
        $response = $this->getJson('/api/v1/fiscal/dead-lettered-projections');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.source', 'dead_lettered_projection');
        $response->assertJsonPath('data.0.fiscal_event_id', $event->id);
        $response->assertJsonPath('data.0.projector_name', 'pos_core_receipt');
        $response->assertJsonPath('data.0.write_off_action_url', route('fiscal.refund-compensations.store'));
    }

    public function test_index_lists_an_ingress_quarantined_event_with_no_projection_row(): void
    {
        $event = $this->storeFiscalEvent(canonicalParseFailure: true);
        // deliberately NO fiscal_event_projections row for this event.

        Sanctum::actingAs($this->operator);
        $response = $this->getJson('/api/v1/fiscal/dead-lettered-projections');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.source', 'ingress_quarantine');
        $response->assertJsonPath('data.0.fiscal_event_id', $event->id);
        $response->assertJsonPath('data.0.write_off_action_url', route('fiscal.refund-compensations.store'));
    }

    public function test_index_projector_filter_excludes_ingress_quarantine_and_other_projectors(): void
    {
        $posEvent = $this->storeFiscalEvent();
        $this->storeProjectionRow($posEvent->id, 'pos_core_receipt', ProjectionStatus::DeadLettered);

        $treasuryEvent = $this->storeFiscalEvent();
        $this->storeProjectionRow($treasuryEvent->id, 'treasury_receipt_bridge', ProjectionStatus::DeadLettered);

        $this->storeFiscalEvent(canonicalParseFailure: true); // ingress-quarantine, excluded by the filter

        Sanctum::actingAs($this->operator);
        $response = $this->getJson('/api/v1/fiscal/dead-lettered-projections?projector=pos_core_receipt');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.fiscal_event_id', $posEvent->id);
    }

    public function test_index_excludes_pending_and_applied_projection_rows(): void
    {
        $pendingEvent = $this->storeFiscalEvent();
        $this->storeProjectionRow($pendingEvent->id, 'pos_core_receipt', ProjectionStatus::Pending);

        $appliedEvent = $this->storeFiscalEvent();
        $this->storeProjectionRow($appliedEvent->id, 'pos_core_receipt', ProjectionStatus::Applied);

        Sanctum::actingAs($this->operator);
        $response = $this->getJson('/api/v1/fiscal/dead-lettered-projections');

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }

    public function test_show_resolves_a_dead_lettered_projection_by_fiscal_event_id(): void
    {
        $event = $this->storeFiscalEvent();
        $this->storeProjectionRow($event->id, 'pos_core_receipt', ProjectionStatus::DeadLettered);

        Sanctum::actingAs($this->operator);
        $response = $this->getJson("/api/v1/fiscal/dead-lettered-projections/{$event->id}");

        $response->assertOk();
        $response->assertJsonPath('data.source', 'dead_lettered_projection');
        $response->assertJsonPath('data.fiscal_event_id', $event->id);
    }

    public function test_show_resolves_an_ingress_quarantined_event_by_fiscal_event_id(): void
    {
        $event = $this->storeFiscalEvent(canonicalParseFailure: true);

        Sanctum::actingAs($this->operator);
        $response = $this->getJson("/api/v1/fiscal/dead-lettered-projections/{$event->id}");

        $response->assertOk();
        $response->assertJsonPath('data.source', 'ingress_quarantine');
        $response->assertJsonPath('data.fiscal_event_id', $event->id);
    }

    public function test_show_returns_404_for_an_unknown_fiscal_event_id(): void
    {
        Sanctum::actingAs($this->operator);
        $response = $this->getJson('/api/v1/fiscal/dead-lettered-projections/'.Str::uuid()->toString());

        $response->assertStatus(404);
    }

    public function test_show_returns_409_for_an_event_that_is_neither_dead_lettered_nor_quarantined(): void
    {
        $event = $this->storeFiscalEvent();
        $this->storeProjectionRow($event->id, 'pos_core_receipt', ProjectionStatus::Applied);

        Sanctum::actingAs($this->operator);
        $response = $this->getJson("/api/v1/fiscal/dead-lettered-projections/{$event->id}");

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'NOT_DEAD_LETTERED');
    }

    // =================================================================
    // review round-2 IMPORTANT 14 (§5.2 evidence (a)) — shift_id +
    // refund_amount surfaced on BOTH row formats.
    // =================================================================

    public function test_index_surfaces_shift_id_and_refund_amount_on_a_dead_lettered_row(): void
    {
        $event = $this->storeRefundFiscalEvent();
        $this->storeProjectionRow($event->id, 'pos_core_receipt', ProjectionStatus::DeadLettered);

        Sanctum::actingAs($this->operator);
        $response = $this->getJson('/api/v1/fiscal/dead-lettered-projections');

        $response->assertOk();
        $response->assertJsonPath('data.0.shift_id', '22222222-2222-4222-8222-222222222222');
        $response->assertJsonPath('data.0.refund_amount', '20.00');
    }

    public function test_show_surfaces_shift_id_and_refund_amount_on_an_ingress_quarantine_row(): void
    {
        // A quarantined row's canonical_bytes never parsed, but its
        // structured `payload` column may still be readable when the
        // best-effort parse partially succeeded -- storeRefundFiscalEvent()
        // deliberately sets BOTH so this path is exercised even though the
        // canonical_parse_failure flag is set independently.
        $event = $this->storeRefundFiscalEvent(canonicalParseFailure: true);

        Sanctum::actingAs($this->operator);
        $response = $this->getJson("/api/v1/fiscal/dead-lettered-projections/{$event->id}");

        $response->assertOk();
        $response->assertJsonPath('data.shift_id', '22222222-2222-4222-8222-222222222222');
        $response->assertJsonPath('data.refund_amount', '20.00');
    }

    // =================================================================
    // Permission gate — 403 deny path.
    // =================================================================

    public function test_index_denies_a_user_without_the_permission(): void
    {
        $unauthorized = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $unauthorized->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);
        Sanctum::actingAs($unauthorized);

        $response = $this->getJson('/api/v1/fiscal/dead-lettered-projections');

        $response->assertStatus(403);
    }

    public function test_show_denies_a_user_without_the_permission(): void
    {
        $event = $this->storeFiscalEvent();
        $this->storeProjectionRow($event->id, 'pos_core_receipt', ProjectionStatus::DeadLettered);

        $unauthorized = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $unauthorized->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);
        Sanctum::actingAs($unauthorized);

        $response = $this->getJson("/api/v1/fiscal/dead-lettered-projections/{$event->id}");

        $response->assertStatus(403);
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function storeFiscalEvent(bool $canonicalParseFailure = false): FiscalEvent
    {
        $payload = $canonicalParseFailure ? null : ['test' => 'dead-lettered-projections'];

        return FiscalEvent::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'terminal_id' => '33333333-3333-4333-8333-333333333333',
            'operator_id' => $this->operator->id,
            'event_type' => FiscalEventType::SALE_RECEIPT,
            'event_version' => 4,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => random_int(1, 999999),
            'event_time_device' => now(),
            'business_date' => now()->startOfDay(),
            'last_server_time_seen' => null,
            'server_received_at' => now(),
            'reference_event_id' => null,
            'reference_document_id' => null,
            // fiscal re-verification (CI PG-filter expansion) —
            // `fiscal_events_source_event_paired_null` CHECK requires
            // BOTH null or BOTH non-null (PG-only; SQLite never enforced
            // it). No assertion in this file reads source_event_id's
            // value.
            'source_event_class' => 'refund_intents',
            'source_event_id' => Str::uuid()->toString(),
            'partner_id' => null,
            'partner_identity_snapshot' => null,
            'canonical_bytes' => $canonicalParseFailure ? 'not valid json {' : json_encode($payload, JSON_THROW_ON_ERROR),
            'previous_hash' => str_repeat('a', 64),
            'current_hash' => str_repeat('b', 64),
            'signature_status' => SignatureStatus::NotRequired,
            'integrity_status' => $canonicalParseFailure ? IntegrityStatus::Quarantined : IntegrityStatus::Verified,
            'integrity_exception_class' => $canonicalParseFailure ? 'canonical_parse_failure' : null,
            'integrity_exception_reason' => $canonicalParseFailure ? 'payload did not parse as JSON' : null,
            'payload' => $payload,
            'payload_parse_status' => $canonicalParseFailure ? PayloadParseStatus::Failed : PayloadParseStatus::Parsed,
        ])->refresh();
    }

    /**
     * A richer fixture carrying `shift_id` + `payments[0].amount` (review
     * round-2 IMPORTANT 14), unlike `storeFiscalEvent()`'s bare
     * `{"test": "..."}` payload.
     */
    private function storeRefundFiscalEvent(bool $canonicalParseFailure = false): FiscalEvent
    {
        $payload = [
            'invoice_type_code' => 'REFUND',
            'shift_id' => '22222222-2222-4222-8222-222222222222',
            'payments' => [[
                'amount' => '20.00',
                'method_code' => 'CASH',
            ]],
        ];

        return FiscalEvent::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'terminal_id' => '33333333-3333-4333-8333-333333333333',
            'operator_id' => $this->operator->id,
            'event_type' => FiscalEventType::SALE_RECEIPT,
            'event_version' => 4,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => random_int(1, 999999),
            'event_time_device' => now(),
            'business_date' => now()->startOfDay(),
            'last_server_time_seen' => null,
            'server_received_at' => now(),
            'reference_event_id' => null,
            'reference_document_id' => null,
            // fiscal re-verification (CI PG-filter expansion) —
            // `fiscal_events_source_event_paired_null` CHECK requires
            // BOTH null or BOTH non-null (PG-only; SQLite never enforced
            // it). No assertion in this file reads source_event_id's
            // value.
            'source_event_class' => 'refund_intents',
            'source_event_id' => Str::uuid()->toString(),
            'partner_id' => null,
            'partner_identity_snapshot' => null,
            'canonical_bytes' => json_encode($payload, JSON_THROW_ON_ERROR),
            'previous_hash' => str_repeat('a', 64),
            'current_hash' => str_repeat('b', 64),
            'signature_status' => SignatureStatus::NotRequired,
            'integrity_status' => $canonicalParseFailure ? IntegrityStatus::Quarantined : IntegrityStatus::Verified,
            'integrity_exception_class' => $canonicalParseFailure ? 'canonical_parse_failure' : null,
            'integrity_exception_reason' => $canonicalParseFailure ? 'payload did not parse as JSON' : null,
            'payload' => $payload,
            'payload_parse_status' => $canonicalParseFailure ? PayloadParseStatus::Failed : PayloadParseStatus::Parsed,
        ])->refresh();
    }

    private function storeProjectionRow(string $fiscalEventId, string $projectorName, ProjectionStatus $status): void
    {
        $now = now();
        DB::table('fiscal_event_projections')->insert([
            'id' => Str::uuid()->toString(),
            'fiscal_event_id' => $fiscalEventId,
            'projector_name' => $projectorName,
            'projection_status' => $status->value,
            'attempts' => $status === ProjectionStatus::DeadLettered ? 5 : 0,
            'last_error' => $status === ProjectionStatus::DeadLettered ? 'RefundQuantityExceededException: over cap' : null,
            'last_attempted_at' => $now,
            'dead_lettered_at' => $status === ProjectionStatus::DeadLettered ? $now : null,
            'applied_at' => $status === ProjectionStatus::Applied ? $now : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
