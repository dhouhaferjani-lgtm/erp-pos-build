<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Accounting\Application\Services\PartnerBalanceService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Company\Domain\Company;
use App\Modules\Fiscal\Application\Contracts\FiscalEventProjector;
use App\Modules\Fiscal\Application\Services\CanonicalPayloadReader;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Partner\Domain\Partner;
use App\Modules\POS\Domain\PosCustomerAlias;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\Projections\TreasuryAccountChargeBridge;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class TreasuryAccountChargeBridgeTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $companyId;

    private string $customerId;

    private string $operatorId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        $this->tenantId = $tenant->id;

        $company = Company::factory()->create([
            'tenant_id' => $this->tenantId,
            'country_code' => 'TN',
            'currency' => 'TND',
        ]);
        $this->companyId = $company->id;

        $this->operatorId = Str::uuid()->toString();

        $customer = Partner::factory()->customer()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'name' => 'Mariam Ben Ali',
        ]);
        $this->customerId = $customer->id;

        $this->createSystemAccount(SystemAccountPurpose::CustomerReceivable, AccountType::Asset, '411000');
        $this->createSystemAccount(SystemAccountPurpose::ProductRevenue, AccountType::Revenue, '707000');
        $this->createSystemAccount(SystemAccountPurpose::VatCollected, AccountType::Liability, '436700');
        $this->createSystemAccount(SystemAccountPurpose::SalesDiscount, AccountType::Expense, '709000');
    }

    public function test_treasury_bridge_creates_ar_journal_entry_from_account_charge_event(): void
    {
        $event = $this->storeAccountChargeFiscalEvent();

        $this->bridge()->apply($event);

        $entry = JournalEntry::query()->with('lines.account')->firstOrFail();
        $this->assertSame($this->tenantId, $entry->tenant_id);
        $this->assertSame($this->companyId, $entry->company_id);
        $this->assertSame(JournalEntryStatus::Draft, $entry->status);
        $this->assertSame('pos_account_charge', $entry->source_type);
        $this->assertSame($event->id, $entry->source_id);
        $this->assertSame('POS Account Charge 66666666-6666-4666-8666-666666666666', $entry->description);

        $receivable = $this->lineForPurpose($entry, SystemAccountPurpose::CustomerReceivable);
        $this->assertSame($this->customerId, $receivable->partner_id);
        $this->assertSame('119.000', $receivable->debit);

        $revenue = $this->lineForPurpose($entry, SystemAccountPurpose::ProductRevenue);
        $this->assertSame('100.000', $revenue->credit);

        $vat = $this->lineForPurpose($entry, SystemAccountPurpose::VatCollected);
        $this->assertSame('19.000', $vat->credit);
    }

    public function test_bridge_is_idempotent_by_fiscal_event_id(): void
    {
        $event = $this->storeAccountChargeFiscalEvent();
        $bridge = $this->bridge();

        $bridge->apply($event);
        $bridge->apply($event);

        $this->assertSame(1, JournalEntry::query()
            ->where('source_type', 'pos_account_charge')
            ->where('source_id', $event->id)
            ->count());
    }

    public function test_bridge_fails_loud_on_conflicting_existing_ar_journal_entry(): void
    {
        $event = $this->storeAccountChargeFiscalEvent();
        JournalEntry::query()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'entry_number' => 'JE-2026-000001',
            'entry_date' => '2026-05-21',
            'description' => 'Conflicting stale entry',
            'status' => JournalEntryStatus::Draft,
            'source_type' => 'pos_account_charge',
            'source_id' => $event->id,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('idempotency_conflict');

        $this->bridge()->apply($event);
    }

    public function test_bridge_fails_loud_on_header_matching_existing_entry_with_wrong_lines(): void
    {
        $event = $this->storeAccountChargeFiscalEvent();
        JournalEntry::query()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'entry_number' => 'JE-2026-000001',
            'entry_date' => '2026-05-21',
            'description' => 'POS Account Charge 66666666-6666-4666-8666-666666666666',
            'status' => JournalEntryStatus::Draft,
            'source_type' => 'pos_account_charge',
            'source_id' => $event->id,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('idempotency_conflict:line_count');

        try {
            $this->bridge()->apply($event);
        } finally {
            $this->assertSame(1, JournalEntry::query()
                ->where('source_type', 'pos_account_charge')
                ->where('source_id', $event->id)
                ->count());
            $this->assertSame(0, JournalLine::query()->count());
        }
    }

    public function test_bridge_resolves_pending_customer_alias_before_ar_creation(): void
    {
        $clientCustomerUuid = '55555555-5555-4555-8555-555555555555';
        PosCustomerAlias::query()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'client_customer_uuid' => $clientCustomerUuid,
            'server_partner_id' => $this->customerId,
        ]);
        $event = $this->storeAccountChargeFiscalEvent($this->accountChargePayload([
            'customer' => [
                'customer_id' => $clientCustomerUuid,
                'customer_sync_status' => 'pending_create',
            ],
        ]));

        $this->bridge()->apply($event);

        $entry = JournalEntry::query()->with('lines.account')->firstOrFail();
        $this->assertSame($this->customerId, $this->lineForPurpose($entry, SystemAccountPurpose::CustomerReceivable)->partner_id);
    }

    public function test_bridge_fails_loud_for_cross_company_partner(): void
    {
        $otherCompany = Company::factory()->create(['tenant_id' => $this->tenantId]);
        $foreignCustomer = Partner::factory()->customer()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $otherCompany->id,
        ]);
        $event = $this->storeAccountChargeFiscalEvent($this->accountChargePayload([
            'customer' => ['customer_id' => $foreignCustomer->id],
        ]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('customer_not_found');

        try {
            $this->bridge()->apply($event);
        } finally {
            $this->assertSame(0, JournalEntry::query()->count());
        }
    }

    public function test_bridge_does_not_create_payment_rows_or_call_pos_payment_path(): void
    {
        $event = $this->storeAccountChargeFiscalEvent();

        $this->bridge()->apply($event);

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('pos_receipt_payments', 0);
        $this->assertSame(1, JournalEntry::query()
            ->where('source_type', 'pos_account_charge')
            ->where('source_id', $event->id)
            ->count());
    }

    public function test_discounted_charge_posts_sales_discount_line(): void
    {
        $event = $this->storeAccountChargeFiscalEvent($this->accountChargePayload([
            'local_balance_snapshot' => [
                'charge_amount' => '114.000',
                'projected_net_balance_after' => '414.000',
                'projected_receivable_balance_after' => '414.000',
            ],
            'totals' => [
                'amount_charged_to_account' => '114.000',
                'grand_total_before_charge' => '114.000',
                'total' => '114.000',
            ],
            'transaction_discount_amount' => '5.000',
            'transaction_discount_reason' => 'loyalty_discount',
        ]));

        $this->bridge()->apply($event);

        $entry = JournalEntry::query()->with('lines.account')->firstOrFail();

        $discount = $this->lineForPurpose($entry, SystemAccountPurpose::SalesDiscount);
        $this->assertSame('5.000', $discount->debit);
        $this->assertSame('0.000', $discount->credit);

        $receivable = $this->lineForPurpose($entry, SystemAccountPurpose::CustomerReceivable);
        $this->assertSame('114.000', $receivable->debit);
    }

    public function test_bridge_copies_canonical_vat_breakdown_and_line_summary_into_command(): void
    {
        $source = file_get_contents(app_path('Modules/Treasury/Application/Projections/TreasuryAccountChargeBridge.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString('vatBreakdown: $view->vatBreakdown', $source);
        $this->assertStringContainsString('lineVatSummary: $view->lineItems', $source);
    }

    public function test_bridge_bubbles_partner_balance_refresh_failure_without_persisting_journal(): void
    {
        $event = $this->storeAccountChargeFiscalEvent();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('partner balance refresh failed');

        try {
            $this->bridge(new ThrowingPartnerBalanceService)->apply($event);
        } finally {
            $this->assertSame(0, JournalEntry::query()->count());
            $this->assertSame(0, JournalLine::query()->count());
        }
    }

    public function test_bridge_contract_and_provider_registration(): void
    {
        $bridge = $this->bridge();

        $this->assertSame('treasury_account_charge_bridge', $bridge->name());
        $this->assertTrue($bridge->handlesEventType(FiscalEventType::ACCOUNT_CHARGE));
        $this->assertFalse($bridge->handlesEventType(FiscalEventType::SALE_RECEIPT));
        $this->assertFalse($bridge->handlesEventType(FiscalEventType::ACCOUNT_PAYMENT));
        $this->assertSame('Treasury', $bridge->requiresModule());
        $this->assertSame(150, $bridge->priority());

        $names = array_map(
            static fn (FiscalEventProjector $projector): string => $projector->name(),
            iterator_to_array($this->app->tagged(FiscalEventProjector::class), false),
        );

        $this->assertContains('treasury_account_charge_bridge', $names);
    }

    private function bridge(?PartnerBalanceService $partnerBalanceService = null): TreasuryAccountChargeBridge
    {
        return new TreasuryAccountChargeBridge(
            canonicalReader: new CanonicalPayloadReader,
            ledgerService: new GeneralLedgerService(
                $partnerBalanceService ?? new PartnerBalanceService,
                new FixedCurrencyScaleResolver(3),
            ),
        );
    }

    private function createSystemAccount(
        SystemAccountPurpose $purpose,
        AccountType $type,
        string $code,
    ): Account {
        return Account::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'code' => $code,
            'name' => $purpose->label(),
            'type' => $type,
            'system_purpose' => $purpose,
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function storeAccountChargeFiscalEvent(?array $payload = null): FiscalEvent
    {
        $payload ??= $this->accountChargePayload();
        $eventId = Str::uuid()->toString();

        return FiscalEvent::query()->create([
            'id' => $eventId,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'terminal_id' => '33333333-3333-4333-8333-333333333333',
            'operator_id' => $this->operatorId,
            'event_type' => FiscalEventType::ACCOUNT_CHARGE,
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => 9,
            'event_time_device' => '2026-05-21 10:15:30',
            'business_date' => '2026-05-21',
            'last_server_time_seen' => null,
            'server_received_at' => '2026-05-21 10:15:31',
            'reference_event_id' => null,
            'reference_document_id' => null,
            'source_event_class' => 'account_charge',
            'source_event_id' => $payload['account_charge_uuid'] ?? null,
            'partner_id' => null,
            'partner_identity_snapshot' => null,
            'canonical_bytes' => $this->canonicalEncode($payload),
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

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function accountChargePayload(array $overrides = []): array
    {
        $payload = [
            'account_charge_uuid' => '66666666-6666-4666-8666-666666666666',
            'business_date' => '2026-05-21',
            'buyer' => null,
            'cashier_id' => $this->operatorId,
            'cashier_name' => 'Default Cashier',
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
                'customer_id' => $this->customerId,
                'customer_sync_status' => 'synced',
                'email' => null,
                'name' => 'Mariam Ben Ali',
                'phone' => '+21611111111',
                'tax_number' => null,
            ],
            'event_time_device' => '2026-05-21T10:15:30.000Z',
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
                'name' => 'Default Seller',
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
            'terminal_id' => '33333333-3333-4333-8333-333333333333',
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
        return json_encode($this->sortRecursive($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function sortRecursive(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(
                fn (mixed $item): mixed => is_array($item) ? $this->sortRecursive($item) : $item,
                $value,
            );
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortRecursive($item);
            }
        }

        return $value;
    }

    private function lineForPurpose(JournalEntry $entry, SystemAccountPurpose $purpose): JournalLine
    {
        $lines = $entry->lines->filter(
            fn (JournalLine $line): bool => $line->account->system_purpose === $purpose
        )->values();

        $this->assertCount(1, $lines);

        /** @var JournalLine $line */
        $line = $lines->first();

        return $line;
    }
}

final class FixedCurrencyScaleResolver implements CurrencyScaleResolverInterface
{
    public function __construct(private readonly int $scale) {}

    public function getScale(?string $currencyCode = null): int
    {
        return $this->scale;
    }
}

final class ThrowingPartnerBalanceService extends PartnerBalanceService
{
    public function refreshPartnerBalance(string $companyId, string $partnerId): void
    {
        throw new RuntimeException('partner balance refresh failed');
    }
}
