<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentRepository;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * v3-refund-chain-integration spec §5.2/§17 —
 * `POST /api/v1/fiscal/refund-compensations`.
 *
 * Covers both classes, idempotency-replay, posting/atomicity, and the
 * permission gate.
 */
final class RefundCompensationControllerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $operator;

    private FiscalEvent $rejectedEvent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'US', // no dedicated seeder -> Generic chart
        ]);
        $location = Location::factory()->create(['company_id' => $this->company->id]);
        Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $location->id,
            'genesis_seed' => str_repeat('0', 64),
        ]);

        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        PaymentRepository::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Cash Register',
            'type' => RepositoryType::CashRegister,
            'currency' => 'EUR',
        ]);

        $this->operator = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $this->operator->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);
        $this->app->make(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->operator->givePermissionTo('fiscal.refunds.manage_dead_letters');

        $this->rejectedEvent = $this->storeRejectedRefundEvent();
    }

    public function test_invalid_refund_class_posts_write_off_entry_and_persists_compensation(): void
    {
        Sanctum::actingAs($this->operator);

        $response = $this->postJson('/api/v1/fiscal/refund-compensations', [
            'fiscal_event_id' => $this->rejectedEvent->id,
            'compensation_class' => 'invalid_refund',
            'operator_attestation' => 'I reconciled the drawer for this shift and confirm cash left it.',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.compensation_class', 'invalid_refund');
        $response->assertJsonPath('data.fiscal_event_id', $this->rejectedEvent->id);

        $this->assertDatabaseHas('fiscal_refund_compensations', [
            'fiscal_event_id' => $this->rejectedEvent->id,
            'compensation_class' => 'invalid_refund',
        ]);

        $journalEntryId = $response->json('data.journal_entry_id');
        $this->assertDatabaseHas('journal_entries', [
            'id' => $journalEntryId,
            'status' => 'posted',
            'source_type' => 'fiscal_refund_compensation',
        ]);
        $this->assertDatabaseCount('journal_lines', 2);
    }

    public function test_valid_unbooked_class_posts_sales_return_entry(): void
    {
        Sanctum::actingAs($this->operator);

        $response = $this->postJson('/api/v1/fiscal/refund-compensations', [
            'fiscal_event_id' => $this->rejectedEvent->id,
            'compensation_class' => 'valid_unbooked',
            'operator_attestation' => 'Genuine refund; purpose-account was missing at booking time.',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.compensation_class', 'valid_unbooked');
    }

    // =================================================================
    // Idempotency — the mandatory RED/GREEN contract for this scope item.
    // =================================================================

    public function test_repeated_post_for_the_same_fiscal_event_id_returns_the_existing_record_not_a_duplicate(): void
    {
        Sanctum::actingAs($this->operator);

        $first = $this->postJson('/api/v1/fiscal/refund-compensations', [
            'fiscal_event_id' => $this->rejectedEvent->id,
            'compensation_class' => 'invalid_refund',
            'operator_attestation' => 'I reconciled the drawer for this shift and confirm cash left it.',
        ]);
        $first->assertStatus(201);
        $firstCompensationId = $first->json('data.id');

        $second = $this->postJson('/api/v1/fiscal/refund-compensations', [
            'fiscal_event_id' => $this->rejectedEvent->id,
            // Deliberately a DIFFERENT class/attestation on replay — the
            // idempotent-hit short-circuit must return the ORIGINAL record
            // unmodified, never re-evaluate against the new input.
            'compensation_class' => 'valid_unbooked',
            'operator_attestation' => 'A different attestation text on replay.',
        ]);

        $second->assertStatus(200);
        $second->assertJsonPath('data.id', $firstCompensationId);
        $second->assertJsonPath('data.compensation_class', 'invalid_refund');

        $this->assertDatabaseCount('fiscal_refund_compensations', 1);
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertDatabaseCount('journal_lines', 2);
    }

    public function test_permission_gate_rejects_a_user_without_the_permission(): void
    {
        $unauthorized = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $unauthorized->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);
        Sanctum::actingAs($unauthorized);

        $response = $this->postJson('/api/v1/fiscal/refund-compensations', [
            'fiscal_event_id' => $this->rejectedEvent->id,
            'compensation_class' => 'invalid_refund',
            'operator_attestation' => 'I reconciled the drawer for this shift and confirm cash left it.',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseCount('fiscal_refund_compensations', 0);
    }

    public function test_unknown_compensation_class_is_rejected(): void
    {
        Sanctum::actingAs($this->operator);

        $response = $this->postJson('/api/v1/fiscal/refund-compensations', [
            'fiscal_event_id' => $this->rejectedEvent->id,
            'compensation_class' => 'bogus_class',
            'operator_attestation' => 'I reconciled the drawer for this shift and confirm cash left it.',
        ]);

        $response->assertStatus(422);
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function storeRejectedRefundEvent(): FiscalEvent
    {
        $payload = [
            'currency_code' => 'EUR',
            'currency_scale' => 2,
            'total' => '20.00',
            'shift_id' => '22222222-2222-4222-8222-222222222222',
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
            'sequence_number' => 1,
            'event_time_device' => now(),
            'business_date' => now()->startOfDay(),
            'last_server_time_seen' => null,
            'server_received_at' => now(),
            'reference_event_id' => null,
            'reference_document_id' => null,
            'source_event_class' => 'refund_intents',
            'source_event_id' => null,
            'partner_id' => null,
            'partner_identity_snapshot' => null,
            'canonical_bytes' => json_encode($payload, JSON_THROW_ON_ERROR),
            'previous_hash' => str_repeat('a', 64),
            'current_hash' => str_repeat('b', 64),
            'signature_status' => SignatureStatus::NotRequired,
            'integrity_status' => IntegrityStatus::Verified,
            'integrity_exception_class' => null,
            'integrity_exception_reason' => null,
            'payload' => $payload,
            'payload_parse_status' => PayloadParseStatus::Parsed,
        ])->refresh();
    }
}
