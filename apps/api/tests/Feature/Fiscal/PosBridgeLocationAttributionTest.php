<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use App\Modules\Fiscal\Domain\Enums\IntegrityStatus;
use App\Modules\Fiscal\Domain\Enums\PayloadParseStatus;
use App\Modules\Fiscal\Domain\Enums\SignatureStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\POS\Application\Projections\PosCoreReceiptProjection;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\Projections\TreasuryAccountPaymentBridge;
use App\Modules\Treasury\Application\Projections\TreasuryDepositBridge;
use App\Modules\Treasury\Application\Projections\TreasuryReceiptBridge;
use App\Modules\Treasury\Domain\Enums\InstrumentKind;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Locks the shared terminal→location resolver used by all three POS bridges. */
final class PosBridgeLocationAttributionTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $companyId;

    private string $locationId;

    private string $terminalId;

    private string $repositoryId;

    private string $otherLocationBankId;

    private string $actorId;

    private string $partnerId;

    protected function setUp(): void
    {
        parent::setUp();
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id, 'currency' => 'TND', 'country_code' => 'TN']);
        $location = Location::factory()->create(['company_id' => $company->id]);
        $repositoryLocation = Location::factory()->create(['company_id' => $company->id]);
        $terminal = Terminal::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
            'genesis_seed' => str_repeat('0', 64),
        ]);
        $actor = User::factory()->create(['tenant_id' => $tenant->id]);
        UserCompanyMembership::query()->create([
            'user_id' => $actor->id,
            'company_id' => $company->id,
            'role' => 'cashier',
            'status' => 'active',
        ]);
        $partner = Partner::factory()->customer()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
        ]);
        $this->tenantId = $tenant->id;
        $this->companyId = $company->id;
        $this->locationId = $location->id;
        $this->terminalId = $terminal->id;
        $this->actorId = $actor->id;
        $this->partnerId = $partner->id;

        app(CompanyContext::class)->setCompanyId($company->id);
        $this->app->make(ChartOfAccountsService::class)->seedForCompany($company);
        $cashAccount = Account::findByPurposeOrFail($company->id, SystemAccountPurpose::Cash);
        PaymentMethod::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => 'CASH',
            'name' => 'Cash',
            // Gate r2 finding 3 — `is_cash_tender` is NOT NULL DEFAULT false, so
            // omitting it declared a method called CASH to be a non-cash tender.
            // It matters now: a cash tender resolves only to a drawer.
            'is_cash_tender' => true,
        ]);
        PaymentMethod::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => 'CARD',
            'name' => 'Card',
            'is_cash_tender' => false,
        ]);
        PaymentMethod::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => 'CHECK',
            'name' => 'Check',
            'has_maturity' => true,
            'instrument_kind' => InstrumentKind::Cheque,
        ]);
        $repository = PaymentRepository::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $repositoryLocation->id,
            'type' => RepositoryType::CashRegister,
            'account_id' => $cashAccount->id,
            'gl_account_id' => $cashAccount->id,
            'currency' => 'TND',
        ]);
        $this->repositoryId = $repository->id;

        // Campaign lane N-12, gate r1 finding 9 — this file's subject is that
        // `payments.location_id` comes from the TERMINAL, not from the
        // repository the money landed in. Post-N-12 a DRAWER at another location
        // is refused outright, so the only shape that still exercises the
        // scenario is a company-wide settlement instrument: `location_id` on a
        // bank account is metadata an operator filed, never a restriction, so it
        // is a candidate from every branch. Mapping the CARD method to this one
        // makes the receipt below resolve a repository that genuinely sits at
        // ANOTHER location — which is what the test's name claims. It has to be
        // a NON-cash tender: gate r2 finding 3 confines physical cash to a
        // drawer, so a cash leg could never legitimately land here.
        //
        // (The first cut of this fix used an unattributed drawer instead, which
        // downgraded the assertion to "terminal location over NULL".)
        $otherLocationBank = PaymentRepository::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $repositoryLocation->id,
            'type' => RepositoryType::BankAccount,
            'code' => 'BANK-OTHER-LOCATION',
            'account_id' => $cashAccount->id,
            'gl_account_id' => $cashAccount->id,
            'currency' => 'TND',
        ]);
        $this->otherLocationBankId = $otherLocationBank->id;

        PaymentMethod::query()
            ->where('company_id', $company->id)
            ->where('code', 'CARD')
            ->update(['default_repository_id' => $otherLocationBank->id]);

        app(CompanyContext::class)->clear();
    }

    public function test_receipt_bridge_apply_persists_terminal_location_over_repository_location_without_context(): void
    {
        $event = $this->saleReceiptEvent();
        $this->app->make(PosCoreReceiptProjection::class)->apply($event);
        $this->app->make(TreasuryReceiptBridge::class)->apply($event);

        $payment = Payment::query()->where('fiscal_event_id', $event->id)->sole();

        // The money landed in a repository that sits at `$repositoryLocation`…
        self::assertSame($this->otherLocationBankId, $payment->repository_id);
        $resolvedRepositoryLocation = PaymentRepository::query()
            ->findOrFail($this->otherLocationBankId)
            ->location_id;
        self::assertNotSame($this->locationId, $resolvedRepositoryLocation);

        // …and the payment is still attributed to the TERMINAL's location.
        self::assertSame($this->locationId, $payment->location_id);
        self::assertNotSame($resolvedRepositoryLocation, $payment->location_id);
    }

    public function test_deposit_apply_uses_repository_location_fallback_when_terminal_is_absent(): void
    {
        $event = $this->depositEvent();
        $this->app->make(TreasuryDepositBridge::class)->apply($event);

        $payment = Payment::query()->where('fiscal_event_id', $event->id)->sole();
        self::assertSame(PaymentRepository::query()->findOrFail($this->repositoryId)->location_id, $payment->location_id);
    }

    public function test_cheque_deposit_without_terminal_shares_repository_fallback_with_instrument(): void
    {
        $event = $this->depositEvent('CHECK');
        $this->app->make(TreasuryDepositBridge::class)->apply($event);

        $payment = Payment::query()->where('fiscal_event_id', $event->id)->sole();
        $instrument = PaymentInstrument::query()->findOrFail($payment->instrument_id);
        $repositoryLocation = PaymentRepository::query()->findOrFail($this->repositoryId)->location_id;

        self::assertSame($repositoryLocation, $payment->location_id);
        self::assertSame($repositoryLocation, $instrument->location_id);
    }

    public function test_account_payment_maturity_apply_propagates_terminal_location_to_instrument(): void
    {
        $event = $this->accountPaymentEvent();
        $this->app->make(TreasuryAccountPaymentBridge::class)->apply($event);

        $payment = Payment::query()->where('fiscal_event_id', $event->id)->sole();
        $instrument = PaymentInstrument::query()->findOrFail($payment->instrument_id);
        self::assertSame($this->locationId, $payment->location_id);
        self::assertSame($this->locationId, $instrument->location_id);
    }

    private function saleReceiptEvent(): FiscalEvent
    {
        $payload = [
            'approval_references' => [],
            'business_date' => now()->toDateString(),
            'buyer' => null,
            'cashier_id' => $this->actorId,
            'cashier_name' => 'Bridge Cashier',
            'consumption_mode' => null,
            'currency_code' => 'TND',
            'currency_scale' => 3,
            'event_time_device' => now()->format('Y-m-d\\TH:i:s.v\\Z'),
            'invoice_type_code' => 'SALE',
            'line_items' => [[
                'gtin' => null,
                'line_discount_amount' => '0.000',
                'line_discount_reason' => null,
                'line_subtotal' => '5.000',
                'line_vat' => '0.000',
                'name' => 'Bridge item',
                'non_collected_subtype' => null,
                'product_id' => 'bridge-product',
                'quantity' => '1.000',
                'sku' => 'BRIDGE-1',
                'tax_category_code' => 'Z',
                'unit_price' => '5.000',
                'vat_rate' => '0.00',
            ]],
            'lottery_code' => null,
            'notes' => null,
            'original_receipt_reference' => null,
            'payments' => [[
                'amount' => '5.000',
                'foreign_currency_amount' => null,
                'foreign_currency_code' => null,
                'instrument_serial' => null,
                'instrument_type' => null,
                'method_code' => 'CARD',
            ]],
            'receipt_uuid' => (string) Str::uuid(),
            'seller' => [
                'address' => ['city' => 'Tunis', 'country_code' => 'TN', 'postal_code' => '1000', 'street' => '1 Test'],
                'name' => 'Bridge Seller',
                'tax_jurisdiction_country_code' => 'TN',
                'tax_number' => '1234567AM000',
            ],
            'shift_id' => (string) Str::uuid(),
            'subtotal' => '5.000',
            'table_id' => null,
            'terminal_id' => $this->terminalId,
            'total' => '5.000',
            'training_flag' => false,
            'transaction_discount_amount' => '0.000',
            'transaction_discount_reason' => null,
            'vat_breakdown' => [[
                'gross_amount' => '5.000',
                'net_amount' => '5.000',
                'rate' => '0.00',
                'tax_category_code' => 'Z',
                'vat_amount' => '0.000',
            ]],
            'vat_total' => '0.000',
            'vouchers_redeemed' => [],
        ];

        return $this->persistEvent(FiscalEventType::SALE_RECEIPT, $payload, $this->terminalId);
    }

    private function depositEvent(string $methodCode = 'CASH'): FiscalEvent
    {
        $foreignTenant = Tenant::factory()->create();
        $foreignCompany = Company::factory()->create(['tenant_id' => $foreignTenant->id]);
        $foreignLocation = Location::factory()->create(['company_id' => $foreignCompany->id]);
        $foreignTerminal = Terminal::factory()->create([
            'tenant_id' => $foreignTenant->id,
            'company_id' => $foreignCompany->id,
            'location_id' => $foreignLocation->id,
            'genesis_seed' => str_repeat('1', 64),
        ]);
        $payload = [
            'actor_name' => 'Bridge Actor',
            'actor_user_id' => $this->actorId,
            'business_date' => now()->toDateString(),
            'company_id' => $this->companyId,
            'currency_code' => 'TND',
            'currency_scale' => 3,
            'customer' => [
                'customer_category' => 'individual',
                'customer_id' => $this->partnerId,
                'email' => null,
                'name' => 'Bridge Customer',
                'phone' => null,
            ],
            'deposit_receipt_uuid' => (string) Str::uuid(),
            'event_time_device' => now()->format('Y-m-d\\TH:i:s.v\\Z'),
            'notes' => null,
            'partner_id' => $this->partnerId,
            'payment' => [
                'amount' => '6.000',
                'method_code' => $methodCode,
                'repository_id' => $this->repositoryId,
                'instrument_serial' => $methodCode === 'CHECK' ? 'CHK-DEPOSIT-FALLBACK' : null,
                'instrument_type' => $methodCode === 'CHECK' ? 'cheque' : null,
            ],
            'tenant_id' => $this->tenantId,
            'terminal_id' => $foreignTerminal->id,
            'training_flag' => false,
            'treasury_allocation_policy' => 'FIFO',
        ];

        return $this->persistEvent(FiscalEventType::DEPOSIT_RECEIPT, $payload, $foreignTerminal->id);
    }

    private function accountPaymentEvent(): FiscalEvent
    {
        $payload = [
            'account_payment_uuid' => (string) Str::uuid(),
            'business_date' => now()->toDateString(),
            'cashier_id' => $this->actorId,
            'cashier_name' => 'Bridge Cashier',
            'currency_code' => 'TND',
            'currency_scale' => 3,
            'customer' => [
                'address' => null,
                'customer_category' => 'retail',
                'customer_id' => $this->partnerId,
                'customer_sync_status' => 'synced',
                'email' => null,
                'name' => 'Bridge Customer',
                'phone' => null,
                'tax_number' => null,
            ],
            'event_time_device' => now()->format('Y-m-d\\TH:i:s.v\\Z'),
            'local_balance_snapshot' => [
                'balance_updated_at' => now()->format('Y-m-d\\TH:i:s.v\\Z'),
                'credit_balance_before' => '0.000',
                'net_balance_before' => '0.000',
                'payment_amount' => '7.000',
                'projected_credit_balance_after' => '7.000',
                'projected_net_balance_after' => '0.000',
                'projected_receivable_balance_after' => '0.000',
                'receivable_balance_before' => '0.000',
            ],
            'notes' => null,
            'payment' => [
                'amount' => '7.000',
                'foreign_currency_amount' => null,
                'foreign_currency_code' => null,
                'instrument_serial' => 'CHK-BRIDGE-1',
                'instrument_type' => 'cheque',
                'method_code' => 'CHECK',
                'repository_id' => $this->repositoryId,
            ],
            'receipt_type_code' => 'ACCOUNT_PAYMENT',
            'references' => null,
            'regime_extensions' => null,
            'seller' => [
                'address' => ['city' => 'Tunis', 'country_code' => 'TN', 'postal_code' => '1000', 'street' => '1 Test'],
                'name' => 'Bridge Seller',
                'tax_jurisdiction_country_code' => 'TN',
                'tax_number' => '1234567AM000',
            ],
            'shift_id' => (string) Str::uuid(),
            'staleness' => [
                'balance_snapshot_stale' => false,
                'customer_snapshot_stale' => false,
                'mirror_last_synced_at' => now()->format('Y-m-d\\TH:i:s.v\\Z'),
                'staleness_reason' => null,
            ],
            'terminal_id' => $this->terminalId,
            'training_flag' => false,
            'treasury_allocation_policy' => 'FIFO',
        ];

        return $this->persistEvent(FiscalEventType::ACCOUNT_PAYMENT, $payload, $this->terminalId);
    }

    /** @param array<string, mixed> $payload */
    private function persistEvent(FiscalEventType $type, array $payload, string $terminalId): FiscalEvent
    {
        $eventTime = now()->utc();
        $businessDate = $eventTime->copy()->startOfDay();
        $canonical = json_encode($payload, JSON_THROW_ON_ERROR);

        return FiscalEvent::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'terminal_id' => $terminalId,
            'operator_id' => $this->actorId,
            'event_type' => $type,
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => random_int(1, 999999),
            'event_time_device' => $eventTime,
            'business_date' => $businessDate,
            'server_received_at' => $eventTime,
            'canonical_bytes' => $canonical,
            'previous_hash' => str_repeat('a', 64),
            'current_hash' => hash('sha256', $canonical),
            'signature_status' => SignatureStatus::NotRequired,
            'integrity_status' => IntegrityStatus::Verified,
            'payload' => $payload,
            'payload_parse_status' => PayloadParseStatus::Parsed,
        ])->refresh();
    }
}
