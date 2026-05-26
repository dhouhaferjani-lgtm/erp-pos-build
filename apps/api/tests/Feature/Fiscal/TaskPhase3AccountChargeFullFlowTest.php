<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Enums\Vertical;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Fiscal\Application\Services\CanonicalPayloadReader;
use App\Modules\Fiscal\Application\Services\FiscalEventProjectionRegistry;
use App\Modules\Fiscal\Application\Services\OutboxIngestor;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityExceptionClass;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\ProjectionStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\CustomerCategory;
use App\Modules\Partner\Domain\Partner;
use App\Modules\POS\Domain\AccountChargeReceipt;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Contracts\Fiscal\ModuleActivationResolver;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * Phase 3 Task 10 closure matrix.
 *
 * Device-authored ACCOUNT_CHARGE events must enter the system only through
 * `/pos/sync/fiscal-events`, preserve exact canonical bytes, project the
 * POS-core printable receipt in every deployment, and fan out only to active
 * bounded-module bridges.
 */
final class TaskPhase3AccountChargeFullFlowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private Terminal $terminal;

    private User $cashier;

    private Partner $customer;

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
            'name' => 'Phase 3 Cashier',
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
            'customer_category' => CustomerCategory::Individual,
            'name' => 'Mariam Ben Ali',
        ]);

        $this->createSystemAccount(SystemAccountPurpose::CustomerReceivable, AccountType::Asset, '411000');
        $this->createSystemAccount(SystemAccountPurpose::ProductRevenue, AccountType::Revenue, '707000');
        $this->createSystemAccount(SystemAccountPurpose::VatCollected, AccountType::Liability, '436700');
        $this->createSystemAccount(SystemAccountPurpose::SalesDiscount, AccountType::Expense, '709000');
    }

    public function test_account_charge_full_flow_projects_printable_and_ar(): void
    {
        $this->bindModuleActivation(true);
        Sanctum::actingAs($this->cashier);

        $eventId = Str::uuid()->toString();
        $accountChargeUuid = Str::uuid()->toString();
        $envelope = $this->sealedEnvelope(
            eventId: $eventId,
            sequence: 1,
            previousHash: $this->genesisSeed,
            accountChargeUuid: $accountChargeUuid,
        );
        $canonicalBytes = $envelope['payload']['canonical_bytes'];

        $response = $this->postJson('/api/v1/pos/sync/fiscal-events', ['envelopes' => [$envelope]]);

        $response->assertOk();
        $response->assertJsonPath('results.0.stored', true);
        $response->assertJsonPath('results.0.fiscal_event_id', $eventId);
        $response->assertJsonPath('results.0.sequence_conflict', false);
        $response->assertJsonPath('results.0.exception_class', null);

        $event = FiscalEvent::query()->findOrFail($eventId);
        $this->assertSame(FiscalEventType::ACCOUNT_CHARGE, $event->event_type);
        $this->assertSame($canonicalBytes, $event->canonical_bytes);
        $this->assertSame(hash('sha256', $canonicalBytes), $event->current_hash);
        $this->assertSame(IntegrityStatus::Verified, $event->integrity_status);
        $this->assertSame(PayloadParseStatus::Parsed, $event->payload_parse_status);

        $receipt = AccountChargeReceipt::query()->where('fiscal_event_id', $eventId)->firstOrFail();
        $this->assertSame($this->tenant->id, $receipt->tenant_id);
        $this->assertSame($this->company->id, $receipt->company_id);
        $this->assertSame($accountChargeUuid, $receipt->account_charge_uuid);
        $this->assertSame($this->customer->id, $receipt->customer_id);
        $this->assertSame('119.0000', $receipt->amount_charged);
        $this->assertSame($event->payload, $receipt->payload_snapshot);

        $readerView = $this->app->make(CanonicalPayloadReader::class)->forAccountCharge($event);
        $this->assertSame($receipt->payload_snapshot, $readerView->payload->toArray());

        $entry = JournalEntry::query()
            ->with('lines.account')
            ->where('source_type', 'pos_account_charge')
            ->where('source_id', $eventId)
            ->firstOrFail();
        $this->assertSame($this->tenant->id, $entry->tenant_id);
        $this->assertSame($this->company->id, $entry->company_id);

        $receivable = $this->lineForPurpose($entry, SystemAccountPurpose::CustomerReceivable);
        $this->assertSame($this->customer->id, $receivable->partner_id);
        $this->assertSame('119.000', $receivable->debit);
        $this->assertSame('100.000', $this->lineForPurpose($entry, SystemAccountPurpose::ProductRevenue)->credit);
        $this->assertSame('19.000', $this->lineForPurpose($entry, SystemAccountPurpose::VatCollected)->credit);

        $this->assertDatabaseHas('fiscal_event_projections', [
            'fiscal_event_id' => $eventId,
            'projector_name' => 'pos_core_account_charge_receipt',
            'projection_status' => ProjectionStatus::Applied->value,
        ]);
        $this->assertDatabaseHas('fiscal_event_projections', [
            'fiscal_event_id' => $eventId,
            'projector_name' => 'treasury_account_charge_bridge',
            'projection_status' => ProjectionStatus::Applied->value,
        ]);
    }

    public function test_account_charge_pos_only_projects_printable_and_skips_bridges(): void
    {
        $this->bindModuleActivation(false);
        Sanctum::actingAs($this->cashier);

        $eventId = Str::uuid()->toString();
        $accountChargeUuid = Str::uuid()->toString();
        $envelope = $this->sealedEnvelope(
            eventId: $eventId,
            sequence: 1,
            previousHash: $this->genesisSeed,
            accountChargeUuid: $accountChargeUuid,
        );

        $response = $this->postJson('/api/v1/pos/sync/fiscal-events', ['envelopes' => [$envelope]]);

        $response->assertOk();
        $response->assertJsonPath('results.0.stored', true);

        $receipt = AccountChargeReceipt::query()->where('fiscal_event_id', $eventId)->firstOrFail();
        $this->assertSame($accountChargeUuid, $receipt->account_charge_uuid);
        $this->assertSame(0, JournalEntry::query()->count());
        $this->assertSame(0, Document::query()->count());

        $this->assertDatabaseHas('fiscal_event_projections', [
            'fiscal_event_id' => $eventId,
            'projector_name' => 'pos_core_account_charge_receipt',
            'projection_status' => ProjectionStatus::Applied->value,
        ]);
        $this->assertDatabaseMissing('fiscal_event_projections', [
            'fiscal_event_id' => $eventId,
            'projector_name' => 'treasury_account_charge_bridge',
        ]);
        $this->assertDatabaseMissing('fiscal_event_projections', [
            'fiscal_event_id' => $eventId,
            'projector_name' => 'document_account_charge_facture_bridge',
        ]);
    }

    public function test_account_charge_business_customer_creates_facture_draft_when_document_active(): void
    {
        $this->bindModuleActivation(true);
        Sanctum::actingAs($this->cashier);

        $this->customer->update([
            'customer_category' => CustomerCategory::Business,
            'name' => 'Mariam Pharmacie SARL',
        ]);

        $eventId = Str::uuid()->toString();
        $accountChargeUuid = Str::uuid()->toString();
        $envelope = $this->sealedEnvelope(
            eventId: $eventId,
            sequence: 1,
            previousHash: $this->genesisSeed,
            accountChargeUuid: $accountChargeUuid,
            payloadOverrides: $this->businessPayloadOverrides(),
        );

        $response = $this->postJson('/api/v1/pos/sync/fiscal-events', ['envelopes' => [$envelope]]);

        $response->assertOk();
        $response->assertJsonPath('results.0.stored', true);

        $document = Document::query()->with('lines')->firstOrFail();
        $this->assertSame($this->tenant->id, $document->tenant_id);
        $this->assertSame($this->company->id, $document->company_id);
        $this->assertSame($this->customer->id, $document->partner_id);
        $this->assertSame(DocumentType::Invoice, $document->type);
        $this->assertSame(DocumentStatus::Draft, $document->status);
        $this->assertSame(FiscalStatus::Draft, $document->fiscal_status);
        $this->assertSame('POS-ACCOUNT-CHARGE:'.$eventId, $document->reference);
        $this->assertSame($eventId, $document->payload['fiscal_event_id'] ?? null);
        $this->assertSame($accountChargeUuid, $document->payload['account_charge_uuid'] ?? null);
        $this->assertCount(1, $document->lines);
        $this->assertSame(1, FiscalEvent::query()->where('event_type', FiscalEventType::ACCOUNT_CHARGE)->count());
        $this->assertSame(1, FiscalEvent::query()->count());

        $this->assertDatabaseHas('fiscal_event_projections', [
            'fiscal_event_id' => $eventId,
            'projector_name' => 'document_account_charge_facture_bridge',
            'projection_status' => ProjectionStatus::Applied->value,
        ]);
    }

    public function test_account_charge_rejects_server_authored_legacy_route_attempts(): void
    {
        Sanctum::actingAs($this->cashier);

        $this->postJson('/api/v1/pos/receipts', [
            'event_type' => FiscalEventType::ACCOUNT_CHARGE->value,
            'total' => '119.000',
        ])->assertStatus(410)
            ->assertJsonPath('error.code', 'NEW_SALE_AUTHORING_RETIRED');

        $this->postJson('/api/v1/pos/receipts/sync', [
            'event_type' => FiscalEventType::ACCOUNT_CHARGE->value,
        ])->assertStatus(405);

        $this->assertSame(0, FiscalEvent::query()->where('event_type', FiscalEventType::ACCOUNT_CHARGE)->count());
    }

    public function test_insufficient_credit_envelope_is_quarantined_and_does_not_project_if_device_bug_syncs_it(): void
    {
        $this->bindModuleActivation(true);
        Sanctum::actingAs($this->cashier);

        $eventId = Str::uuid()->toString();
        $envelope = $this->sealedEnvelope(
            eventId: $eventId,
            sequence: 1,
            previousHash: $this->genesisSeed,
            accountChargeUuid: Str::uuid()->toString(),
            payloadOverrides: [
                'credit_decision' => [
                    'credit_available_after' => '-19.000',
                    'credit_limit' => '100.000',
                    'limit_exceeded' => true,
                    'warnings' => ['credit_limit_exceeded'],
                ],
            ],
        );

        $response = $this->postJson('/api/v1/pos/sync/fiscal-events', ['envelopes' => [$envelope]]);

        $response->assertOk();
        $response->assertJsonPath('results.0.stored', true);
        $response->assertJsonPath('results.0.exception_class', IntegrityExceptionClass::CanonicalParseFailure->value);

        $event = FiscalEvent::query()->findOrFail($eventId);
        $this->assertSame(IntegrityStatus::Quarantined, $event->integrity_status);
        $this->assertSame(PayloadParseStatus::Failed, $event->payload_parse_status);
        $this->assertSame(0, AccountChargeReceipt::query()->count());
        $this->assertSame(0, JournalEntry::query()->count());
        $this->assertSame(0, Document::query()->count());
        $this->assertSame(0, DB::table('fiscal_event_projections')->where('fiscal_event_id', $eventId)->count());
    }

    public function test_hard_stale_block_envelope_is_quarantined_and_does_not_project_if_device_bug_syncs_it(): void
    {
        $this->bindModuleActivation(true);
        Sanctum::actingAs($this->cashier);

        $eventId = Str::uuid()->toString();
        $envelope = $this->sealedEnvelope(
            eventId: $eventId,
            sequence: 1,
            previousHash: $this->genesisSeed,
            accountChargeUuid: Str::uuid()->toString(),
            payloadOverrides: [
                'credit_decision' => [
                    'mirror_stale_at_authoring' => true,
                    'stale_policy_action' => 'block',
                    'warnings' => ['balance_snapshot_hard_stale'],
                ],
                'staleness' => [
                    'balance_snapshot_stale' => true,
                    'customer_snapshot_stale' => true,
                    'mirror_last_synced_at' => '2026-05-20T08:00:00.000Z',
                    'staleness_reason' => 'older_than_hard_threshold',
                ],
            ],
        );

        $response = $this->postJson('/api/v1/pos/sync/fiscal-events', ['envelopes' => [$envelope]]);

        $response->assertOk();
        $response->assertJsonPath('results.0.stored', true);
        $response->assertJsonPath('results.0.exception_class', IntegrityExceptionClass::CanonicalParseFailure->value);

        $event = FiscalEvent::query()->findOrFail($eventId);
        $this->assertSame(IntegrityStatus::Quarantined, $event->integrity_status);
        $this->assertSame(PayloadParseStatus::Failed, $event->payload_parse_status);
        $this->assertSame(0, AccountChargeReceipt::query()->count());
        $this->assertSame(0, JournalEntry::query()->count());
        $this->assertSame(0, Document::query()->count());
        $this->assertSame(0, DB::table('fiscal_event_projections')->where('fiscal_event_id', $eventId)->count());
    }

    private function bindModuleActivation(bool $active): void
    {
        $this->app->bind(
            ModuleActivationResolver::class,
            static fn (): ModuleActivationResolver => new class($active) implements ModuleActivationResolver
            {
                public function __construct(private readonly bool $active) {}

                public function isActive(string $module, string $tenantId, string $companyId): bool
                {
                    unset($module, $tenantId, $companyId);

                    return $this->active;
                }
            },
        );
        $this->app->forgetInstance(FiscalEventProjectionRegistry::class);
        $this->app->forgetInstance(OutboxIngestor::class);
    }

    /**
     * @param  array<string, mixed>  $payloadOverrides
     * @return array<string, mixed>
     */
    private function sealedEnvelope(
        string $eventId,
        int $sequence,
        string $previousHash,
        string $accountChargeUuid,
        array $payloadOverrides = [],
    ): array {
        $eventTime = now('UTC')->subSeconds(30);
        $payloadEventTime = $eventTime->format('Y-m-d\TH:i:s.000\Z');
        $businessDate = $eventTime->toDateString();
        $payload = $this->accountChargePayload($accountChargeUuid, $payloadEventTime, $businessDate, $payloadOverrides);
        $base = [
            'id' => $eventId,
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'terminal_id' => $this->terminal->id,
            'operator_id' => $this->cashier->id,
            'event_type' => FiscalEventType::ACCOUNT_CHARGE->value,
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => $sequence,
            'event_time_device' => $eventTime->format('Y-m-d\TH:i:s\Z'),
            'business_date' => $businessDate,
            'chain_context' => 'operational',
            'last_server_time_seen' => null,
            'reference_event_id' => null,
            'reference_document_id' => null,
            'source_event_class' => 'account_charge',
            'source_event_id' => $accountChargeUuid,
            'previous_hash' => $previousHash,
        ];

        $canonicalArray = [
            'business_date' => $base['business_date'],
            'chain_context' => $base['chain_context'],
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
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function accountChargePayload(
        string $accountChargeUuid,
        string $eventTimeDevice,
        string $businessDate,
        array $overrides = [],
    ): array {
        $payload = [
            'account_charge_uuid' => $accountChargeUuid,
            'business_date' => $businessDate,
            'buyer' => null,
            'cashier_id' => $this->cashier->id,
            'cashier_name' => $this->cashier->name,
            'charge_terms' => [
                'due_date' => '2026-06-20',
                'payment_terms_days' => 30,
                'terms_label' => 'Net 30',
            ],
            'credit_decision' => [
                'credit_available_after' => '81.000',
                'credit_available_before' => '200.000',
                'credit_limit' => '500.000',
                'decision' => 'approved',
                'limit_exceeded' => false,
                'mirror_stale_at_authoring' => false,
                'override_evidence' => null,
                'policy_version' => 'phase3-default-v1',
                'stale_policy_action' => 'allow',
                'warnings' => [],
            ],
            'currency_code' => 'TND',
            'currency_scale' => 3,
            'customer' => [
                'account_identifier' => 'CUST-0001',
                'address' => null,
                'customer_category' => 'individual',
                'customer_id' => $this->customer->id,
                'customer_sync_status' => 'synced',
                'email' => null,
                'name' => $this->customer->name,
                'phone' => '+21611111111',
                'tax_number' => null,
            ],
            'event_time_device' => $eventTimeDevice,
            'invoice_classification' => 'b2c_charge_receipt',
            'line_items' => [[
                'gtin' => null,
                'line_discount_amount' => '0.000',
                'line_discount_reason' => null,
                'line_subtotal' => '100.000',
                'line_uuid' => '77777777-7777-4777-8777-777777777777',
                'line_vat' => '19.000',
                'name' => 'Default item',
                'non_collected_subtype' => null,
                'product_id' => 'prod-default',
                'quantity' => '1.000',
                'sku' => 'SKU-DEFAULT',
                'tax_category_code' => '',
                'unit_price' => '100.000',
                'vat_rate' => '19.00',
            ]],
            'local_balance_snapshot' => [
                'balance_updated_at' => '2026-05-21T10:10:00.000Z',
                'charge_amount' => '119.000',
                'credit_balance_before' => '0.000',
                'net_balance_before' => '300.000',
                'projected_credit_balance_after' => '0.000',
                'projected_net_balance_after' => '419.000',
                'projected_receivable_balance_after' => '419.000',
                'receivable_balance_before' => '300.000',
            ],
            'notes' => null,
            'print_profile' => 'ACCOUNT_CHARGE_RECEIPT',
            'receipt_type_code' => 'ACCOUNT_CHARGE',
            'references' => null,
            'regime_extensions' => null,
            'seller' => [
                'address' => ['city' => 'Tunis', 'country_code' => 'TN', 'postal_code' => '1000', 'street' => '1 rue Test'],
                'name' => 'Phase 3 Seller',
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
            'totals' => [
                'amount_charged_to_account' => '119.000',
                'grand_total_before_charge' => '119.000',
                'subtotal' => '100.000',
                'total' => '119.000',
                'vat_total' => '19.000',
            ],
            'training_flag' => false,
            'transaction_discount_amount' => '0.000',
            'transaction_discount_reason' => null,
            'vat_breakdown' => [[
                'gross_amount' => '119.000',
                'net_amount' => '100.000',
                'rate' => '19.00',
                'tax_category_code' => '',
                'vat_amount' => '19.000',
            ]],
        ];

        return $this->mergeRecursiveDistinct($payload, $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function businessPayloadOverrides(): array
    {
        return [
            'buyer' => [
                'address' => ['city' => 'Tunis', 'country_code' => 'TN', 'postal_code' => '1000', 'street' => '2 rue Buyer'],
                'codice_fiscale' => null,
                'contact_id' => null,
                'customer_id' => $this->customer->id,
                'name' => 'Mariam Pharmacie SARL',
                'tax_number' => '1234567BM000',
            ],
            'customer' => [
                'customer_category' => 'business',
                'customer_id' => $this->customer->id,
                'name' => 'Mariam Pharmacie SARL',
                'tax_number' => '1234567BM000',
            ],
            'invoice_classification' => 'b2b_facture_draft_requested',
        ];
    }

    private function createSystemAccount(
        SystemAccountPurpose $purpose,
        AccountType $type,
        string $code,
    ): Account {
        return Account::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'code' => $code,
            'name' => $purpose->label(),
            'type' => $type,
            'system_purpose' => $purpose,
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function mergeRecursiveDistinct(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                /** @var array<string, mixed> $baseValue */
                $baseValue = $base[$key];
                /** @var array<string, mixed> $overrideValue */
                $overrideValue = $value;
                $base[$key] = $this->mergeRecursiveDistinct($baseValue, $overrideValue);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private function canonicalEncode(array $value): string
    {
        $json = json_encode($this->sortRecursive($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('canonical encode failed in Phase 3 closure fixture');
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

    private function lineForPurpose(JournalEntry $entry, SystemAccountPurpose $purpose): JournalLine
    {
        $lines = $entry->lines->filter(
            fn (JournalLine $line): bool => $line->account->system_purpose === $purpose,
        )->values();

        $this->assertCount(1, $lines);

        /** @var JournalLine $line */
        $line = $lines->first();

        return $line;
    }
}
