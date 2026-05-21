<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Enums\Vertical;
use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Document\Domain\Document;
use App\Modules\Fiscal\Application\Services\CanonicalPayloadReader;
use App\Modules\Fiscal\Application\Services\FiscalEventProjectionRegistry;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\ProjectionStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\POS\Domain\AccountPaymentReceipt;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\PaymentOrigin;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentAllocation;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Shared\Contracts\Fiscal\ModuleActivationResolver;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 2 closure verification.
 *
 * Device authors + seals an ACCOUNT_PAYMENT event, syncs through the
 * fiscal-event endpoint, the server stores the exact canonical bytes, POS-core
 * projects the printable receipt, and the Treasury bridge allocates FIFO only
 * when the Treasury module is active.
 */
final class TaskPhase2AccountPaymentFullFlowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private Terminal $terminal;

    private User $cashier;

    private Partner $customer;

    private PaymentMethod $paymentMethod;

    private PaymentRepository $repository;

    private string $genesisSeed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->genesisSeed = str_repeat('0', 64);

        $this->tenant = Tenant::factory()->create(['vertical' => Vertical::Parapharmacy]);
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'country_code' => 'TN',
            'currency' => 'TND',
        ]);
        $this->location = Location::factory()->create(['company_id' => $this->company->id]);
        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->location->id,
            'genesis_seed' => $this->genesisSeed,
        ]);

        $this->cashier = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Phase 2 Cashier',
        ]);
        UserCompanyMembership::query()->create([
            'user_id' => $this->cashier->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Cashier,
            'is_primary' => true,
            'status' => MembershipStatus::Active,
        ]);

        $this->customer = Partner::factory()->customer()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Mariam Ben Ali',
        ]);

        $this->paymentMethod = PaymentMethod::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'CASH',
            'name' => 'Cash',
        ]);

        $this->app->make(ChartOfAccountsService::class)->seedForCompany($this->company);
        $cashAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::Cash);
        $this->repository = PaymentRepository::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => 'DRAWER-1',
            'name' => 'Drawer 1',
            'account_id' => $cashAccount->id,
            'gl_account_id' => $cashAccount->id,
            'is_active' => true,
        ]);
    }

    public function test_device_account_payment_syncs_projects_and_allocates_fifo_when_treasury_is_active(): void
    {
        Sanctum::actingAs($this->cashier);

        $invoice = Document::factory()
            ->posted()
            ->withTotal('100.000', '100.000')
            ->create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'partner_id' => $this->customer->id,
                'document_number' => 'INV-PHASE2-001',
                'document_date' => '2026-05-20',
                'due_date' => '2026-06-20',
                'currency' => 'TND',
            ]);

        $eventId = Str::uuid()->toString();
        $accountPaymentUuid = Str::uuid()->toString();
        $envelope = $this->sealedEnvelope(
            eventId: $eventId,
            sequence: 1,
            previousHash: $this->genesisSeed,
            accountPaymentUuid: $accountPaymentUuid,
        );
        $canonicalBytes = $envelope['payload']['canonical_bytes'];
        $currentHash = $envelope['payload']['current_hash'];

        $response = $this->postJson('/api/v1/pos/sync/fiscal-events', [
            'envelopes' => [$envelope],
        ]);

        $response->assertOk();
        $response->assertJsonPath('results.0.stored', true);
        $response->assertJsonPath('results.0.fiscal_event_id', $eventId);
        $response->assertJsonPath('results.0.sequence_conflict', false);
        $response->assertJsonPath('results.0.exception_class', null);

        $event = FiscalEvent::query()->findOrFail($eventId);
        $this->assertSame($this->tenant->id, $event->tenant_id);
        $this->assertSame($this->company->id, $event->company_id);
        $this->assertSame(FiscalEventType::ACCOUNT_PAYMENT, $event->event_type);
        $this->assertSame(1, $event->sequence_number);
        $this->assertSame($this->genesisSeed, $event->previous_hash);
        $this->assertSame($currentHash, $event->current_hash);
        $this->assertSame($currentHash, hash('sha256', $canonicalBytes));
        $this->assertSame($canonicalBytes, $event->canonical_bytes);
        $this->assertSame(IntegrityStatus::Verified, $event->integrity_status);
        $this->assertSame(PayloadParseStatus::Parsed, $event->payload_parse_status);

        $receipt = AccountPaymentReceipt::query()->where('fiscal_event_id', $eventId)->firstOrFail();
        $this->assertSame($this->tenant->id, $receipt->tenant_id);
        $this->assertSame($this->company->id, $receipt->company_id);
        $this->assertSame($accountPaymentUuid, $receipt->account_payment_uuid);
        $this->assertSame($this->customer->id, $receipt->customer_id);
        $this->assertSame('Mariam Ben Ali', $receipt->customer_name);
        $this->assertSame('100.000', $receipt->amount);
        $this->assertSame('TND', $receipt->currency_code);
        $this->assertSame($event->payload, $receipt->payload_snapshot);

        $readerView = $this->app->make(CanonicalPayloadReader::class)->forAccountPayment($event);
        $this->assertSame($receipt->payload_snapshot, $readerView->payload->toArray());

        $payment = Payment::query()->where('fiscal_event_id', $eventId)->firstOrFail();
        $this->assertSame($this->tenant->id, $payment->tenant_id);
        $this->assertSame($this->company->id, $payment->company_id);
        $this->assertSame($this->customer->id, $payment->partner_id);
        $this->assertSame($this->paymentMethod->id, $payment->payment_method_id);
        $this->assertSame($this->repository->id, $payment->repository_id);
        $this->assertSame(PaymentOrigin::Pos, $payment->origin);
        $this->assertSame(PaymentStatus::Completed, $payment->status);
        $this->assertSame('100.000', $payment->amount);
        $this->assertSame('TND', $payment->currency);
        $this->assertSame($this->cashier->id, $payment->created_by);

        $allocation = PaymentAllocation::query()->where('payment_id', $payment->id)->firstOrFail();
        $this->assertSame($invoice->id, $allocation->document_id);
        $this->assertSame('100.0000', $allocation->amount);

        $this->assertDatabaseHas('fiscal_event_projections', [
            'fiscal_event_id' => $eventId,
            'projector_name' => 'pos_core_account_payment_receipt',
            'projection_status' => ProjectionStatus::Applied->value,
        ]);
        $this->assertDatabaseHas('fiscal_event_projections', [
            'fiscal_event_id' => $eventId,
            'projector_name' => 'treasury_account_payment_bridge',
            'projection_status' => ProjectionStatus::Applied->value,
        ]);
    }

    public function test_pos_only_account_payment_sync_projects_printable_receipt_and_skips_treasury_bridge(): void
    {
        $this->app->bind(
            ModuleActivationResolver::class,
            fn (): ModuleActivationResolver => new class implements ModuleActivationResolver
            {
                public function isActive(string $module, string $tenantId, string $companyId): bool
                {
                    return false;
                }
            },
        );
        $this->app->forgetInstance(FiscalEventProjectionRegistry::class);
        Sanctum::actingAs($this->cashier);

        $eventId = Str::uuid()->toString();
        $accountPaymentUuid = Str::uuid()->toString();
        $envelope = $this->sealedEnvelope(
            eventId: $eventId,
            sequence: 1,
            previousHash: $this->genesisSeed,
            accountPaymentUuid: $accountPaymentUuid,
        );
        $canonicalBytes = $envelope['payload']['canonical_bytes'];

        $response = $this->postJson('/api/v1/pos/sync/fiscal-events', [
            'envelopes' => [$envelope],
        ]);

        $response->assertOk();
        $response->assertJsonPath('results.0.stored', true);

        $event = FiscalEvent::query()->findOrFail($eventId);
        $this->assertSame($canonicalBytes, $event->canonical_bytes);
        $this->assertSame(PayloadParseStatus::Parsed, $event->payload_parse_status);

        $receipt = AccountPaymentReceipt::query()->where('fiscal_event_id', $eventId)->firstOrFail();
        $this->assertSame($accountPaymentUuid, $receipt->account_payment_uuid);
        $this->assertSame($event->payload, $receipt->payload_snapshot);

        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, PaymentAllocation::query()->count());
        $this->assertDatabaseHas('fiscal_event_projections', [
            'fiscal_event_id' => $eventId,
            'projector_name' => 'pos_core_account_payment_receipt',
            'projection_status' => ProjectionStatus::Applied->value,
        ]);
        $this->assertDatabaseMissing('fiscal_event_projections', [
            'fiscal_event_id' => $eventId,
            'projector_name' => 'treasury_account_payment_bridge',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function sealedEnvelope(
        string $eventId,
        int $sequence,
        string $previousHash,
        string $accountPaymentUuid,
    ): array {
        $eventTime = now('UTC')->subSeconds(30);
        $payloadEventTime = $eventTime->format('Y-m-d\TH:i:s.000\Z');
        $businessDate = $eventTime->toDateString();
        $payload = $this->accountPaymentPayload($accountPaymentUuid, $payloadEventTime, $businessDate);
        $base = [
            'id' => $eventId,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'terminal_id' => $this->terminal->id,
            'operator_id' => $this->cashier->id,
            'event_type' => FiscalEventType::ACCOUNT_PAYMENT->value,
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => $sequence,
            'event_time_device' => $eventTime->format('Y-m-d\TH:i:s\Z'),
            'business_date' => $businessDate,
            'last_server_time_seen' => null,
            'reference_event_id' => null,
            'reference_document_id' => null,
            'source_event_class' => 'account_payments',
            'source_event_id' => $accountPaymentUuid,
            'previous_hash' => $previousHash,
        ];

        $canonicalArray = [
            'business_date' => $base['business_date'],
            'company_id' => $base['company_id'],
            'event_time_device' => $base['event_time_device'],
            'event_type' => $base['event_type'],
            'event_version' => $base['event_version'],
            'operator_id' => $base['operator_id'],
            'payload' => $payload,
            'previous_hash' => $base['previous_hash'],
            'reference_document_id' => $base['reference_document_id'],
            'reference_event_id' => $base['reference_event_id'],
            'sequence_number' => $base['sequence_number'],
            'signature_version' => $base['signature_version'],
            'tenant_id' => $base['tenant_id'],
            'terminal_id' => $base['terminal_id'],
        ];

        $canonicalBytes = $this->canonicalEncode($canonicalArray);
        $base['canonical_bytes'] = $canonicalBytes;
        $base['current_hash'] = hash('sha256', $canonicalBytes);

        return [
            'envelope_id' => Str::uuid()->toString(),
            'type' => 'FISCAL_EVENT',
            'payload_version' => 1,
            'idempotency_key' => $this->terminal->id.':'.$sequence,
            'payload' => $base,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function accountPaymentPayload(string $accountPaymentUuid, string $eventTimeDevice, string $businessDate): array
    {
        return [
            'account_payment_uuid' => $accountPaymentUuid,
            'business_date' => $businessDate,
            'cashier_id' => $this->cashier->id,
            'cashier_name' => $this->cashier->name,
            'currency_code' => 'TND',
            'currency_scale' => 3,
            'customer' => [
                'address' => null,
                'customer_category' => 'retail',
                'customer_id' => $this->customer->id,
                'customer_sync_status' => 'synced',
                'email' => null,
                'name' => $this->customer->name,
                'phone' => '+21611111111',
                'tax_number' => null,
            ],
            'event_time_device' => $eventTimeDevice,
            'local_balance_snapshot' => [
                'balance_updated_at' => '2026-05-21T10:10:00.000Z',
                'credit_balance_before' => '0.000',
                'net_balance_before' => '300.000',
                'payment_amount' => '100.000',
                'projected_credit_balance_after' => '0.000',
                'projected_net_balance_after' => '200.000',
                'projected_receivable_balance_after' => '200.000',
                'receivable_balance_before' => '300.000',
            ],
            'notes' => null,
            'payment' => [
                'amount' => '100.000',
                'foreign_currency_amount' => null,
                'foreign_currency_code' => null,
                'instrument_serial' => null,
                'instrument_type' => null,
                'method_code' => 'CASH',
                'repository_id' => $this->repository->id,
            ],
            'receipt_type_code' => 'ACCOUNT_PAYMENT',
            'references' => null,
            'regime_extensions' => null,
            'seller' => [
                'address' => ['city' => 'Tunis', 'country_code' => 'TN', 'postal_code' => '1000', 'street' => '1 rue Test'],
                'name' => 'Phase 2 Seller',
                'tax_jurisdiction_country_code' => 'TN',
                'tax_number' => '1234567AM000',
            ],
            'shift_id' => '22222222-2222-4222-8222-222222222222',
            'staleness' => [
                'balance_snapshot_stale' => false,
                'customer_snapshot_stale' => false,
                'mirror_last_synced_at' => '2026-05-21T10:10:00.000Z',
                'staleness_reason' => null,
            ],
            'terminal_id' => $this->terminal->id,
            'training_flag' => false,
            'treasury_allocation_policy' => 'FIFO',
        ];
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private function canonicalEncode(array $value): string
    {
        $json = json_encode($this->sortRecursive($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('canonical encode failed in Phase 2 closure fixture');
        }

        return $json;
    }

    private function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->sortRecursive($item), $value);
        }

        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->sortRecursive($item), $value);
    }
}
