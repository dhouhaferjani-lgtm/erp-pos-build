<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
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
use App\Modules\POS\Application\Services\VirtualAdminFiscalEventService;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\Projections\TreasuryAccountPaymentBridge;
use App\Modules\Treasury\Application\Projections\TreasuryDepositBridge;
use App\Modules\Treasury\Domain\Enums\InstrumentKind;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PosSiblingBridgesMaturityTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $companyId;

    private string $actorId;

    private string $partnerId;

    private string $repositoryId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        $this->tenantId = $tenant->id;
        $company = Company::factory()->create([
            'tenant_id' => $tenant->id,
            'country_code' => 'TN',
            'currency' => 'TND',
        ]);
        $this->companyId = $company->id;
        Location::factory()->create(['company_id' => $company->id]);
        app(CompanyContext::class)->setCompanyId($company->id);
        $this->app->make(ChartOfAccountsService::class)->seedForCompany($company);

        $actor = User::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Sibling Bridge Actor']);
        $this->actorId = $actor->id;
        UserCompanyMembership::query()->create([
            'user_id' => $actor->id,
            'company_id' => $company->id,
            'role' => MembershipRole::Cashier,
            'is_primary' => true,
            'status' => MembershipStatus::Active,
        ]);
        $partner = Partner::factory()->customer()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'name' => 'Sibling Bridge Customer',
        ]);
        $this->partnerId = $partner->id;

        foreach ([
            ['code' => 'CASH', 'name' => 'Cash', 'has_maturity' => false, 'instrument_kind' => null],
            ['code' => 'CHECK', 'name' => 'Check', 'has_maturity' => true, 'instrument_kind' => InstrumentKind::Cheque],
            ['code' => 'TRAITE', 'name' => 'Traite', 'has_maturity' => true, 'instrument_kind' => InstrumentKind::Effet],
        ] as $method) {
            PaymentMethod::factory()->create([
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                ...$method,
            ]);
        }

        $cashAccount = Account::findByPurposeOrFail($company->id, SystemAccountPurpose::Cash);
        $repository = PaymentRepository::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'type' => RepositoryType::CashRegister,
            'gl_account_id' => $cashAccount->id,
            'account_id' => $cashAccount->id,
            'currency' => 'TND',
            'balance' => '0.000',
        ]);
        $this->repositoryId = $repository->id;

        app(CompanyContext::class)->clear();
    }

    public function test_deposit_by_check_posts_portfolio_to_deposit_liability_without_movement(): void
    {
        $event = $this->depositEvent('20.000', 'CHECK');
        $bridge = $this->app->make(TreasuryDepositBridge::class);

        $bridge->apply($event);
        $bridge->apply($event);

        $this->assertDeferredPureAdvance($event, InstrumentKind::Cheque, '5312', '20.000');
    }

    public function test_account_payment_by_traite_posts_effects_receivable_without_movement_or_actor_context(): void
    {
        $unresolvedCashier = User::factory()->create(['tenant_id' => $this->tenantId]);
        $event = $this->accountPaymentEvent('30.000', 'TRAITE', $unresolvedCashier->id);
        $bridge = $this->app->make(TreasuryAccountPaymentBridge::class);

        $bridge->apply($event);
        $bridge->apply($event);

        $this->assertDeferredPureAdvance($event, InstrumentKind::Effet, '413', '30.000');
        $this->assertNull(Payment::query()->where('fiscal_event_id', $event->id)->sole()->created_by);
    }

    public function test_cash_variants_keep_repository_debit_and_movement_shape(): void
    {
        $deposit = $this->depositEvent('11.000', 'CASH');
        $this->app->make(TreasuryDepositBridge::class)->apply($deposit);
        $this->assertImmediatePureAdvance($deposit, '11.000');

        $accountPayment = $this->accountPaymentEvent('13.000', 'CASH', $this->actorId);
        $this->app->make(TreasuryAccountPaymentBridge::class)->apply($accountPayment);
        $this->assertImmediatePureAdvance($accountPayment, '13.000');

        $this->assertSame('24.000', (string) PaymentRepository::query()->findOrFail($this->repositoryId)->balance);
    }

    public function test_missing_portfolio_account_fails_before_sibling_bridge_writes(): void
    {
        Account::query()->where('company_id', $this->companyId)->where('code', '5312')->delete();
        $event = $this->depositEvent('20.000', 'CHECK');

        try {
            $this->app->make(TreasuryDepositBridge::class)->apply($event);
            $this->fail('Expected missing cheque portfolio account to abort the bridge.');
        } catch (\Throwable $exception) {
            $this->assertStringContainsString('checks_to_collect', $exception->getMessage());
        }

        $this->assertSame(0, Payment::query()->where('fiscal_event_id', $event->id)->count());
        $this->assertSame(0, PaymentInstrument::query()->count());
        $this->assertSame(0, DB::table('repository_movements')->where('source_id', $event->id)->count());
        $this->assertSame('0.000', (string) PaymentRepository::query()->findOrFail($this->repositoryId)->balance);
    }

    public function test_maturity_override_posts_even_when_custody_has_only_the_legacy_account_link(): void
    {
        PaymentRepository::query()->whereKey($this->repositoryId)->update(['gl_account_id' => null]);
        $event = $this->accountPaymentEvent('17.000', 'TRAITE', $this->actorId);

        $this->app->make(TreasuryAccountPaymentBridge::class)->apply($event);

        $this->assertDeferredPureAdvance($event, InstrumentKind::Effet, '413', '17.000');
    }

    public function test_pre_cutover_repository_debit_replay_does_not_mint_sibling_instrument(): void
    {
        $event = $this->depositEvent('19.000', 'CASH');
        $bridge = $this->app->make(TreasuryDepositBridge::class);
        $bridge->apply($event);
        PaymentMethod::query()->where('company_id', $this->companyId)->where('code', 'CASH')->update([
            'has_maturity' => true,
            'instrument_kind' => InstrumentKind::Cheque,
        ]);

        $bridge->apply($event);

        $payment = Payment::query()->where('fiscal_event_id', $event->id)->sole();
        $this->assertNull($payment->instrument_id);
        $this->assertSame(0, PaymentInstrument::query()->count());
        $this->assertSame(1, DB::table('repository_movements')->where('source_id', $event->id)->count());
        $this->assertSame('19.000', (string) PaymentRepository::query()->findOrFail($this->repositoryId)->balance);
        $this->assertSame(1, DB::table('journal_entries')->where('source_id', $payment->id)->count());
    }

    private function assertDeferredPureAdvance(
        FiscalEvent $event,
        InstrumentKind $kind,
        string $debitCode,
        string $amount,
    ): void {
        $payment = Payment::query()->where('fiscal_event_id', $event->id)->sole();
        $this->assertNotNull($payment->instrument_id);
        $instrument = PaymentInstrument::query()->findOrFail($payment->instrument_id);
        $this->assertSame($kind, $instrument->kind);
        $this->assertSame($payment->id, $instrument->payment_id);
        $this->assertSame(1, PaymentInstrument::query()->where('idempotency_key', "fiscal_event:{$event->id}:instrument:0")->count());
        $this->assertSame(0, DB::table('repository_movements')->where('source_id', $event->id)->count());
        $this->assertSame('0.000', (string) PaymentRepository::query()->findOrFail($this->repositoryId)->balance);

        $entry = DB::table('journal_entries')->where('id', $payment->journal_entry_id)->sole();
        $this->assertSame(JournalEntryStatus::Posted->value, $entry->status);
        $debitAccount = Account::query()->where('company_id', $this->companyId)->where('code', $debitCode)->sole();
        $advanceAccount = Account::findByPurposeOrFail($this->companyId, SystemAccountPurpose::CustomerAdvance);
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $entry->id,
            'account_id' => $debitAccount->id,
            'debit' => $amount,
            'credit' => '0.000',
            'line_order' => 0,
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $entry->id,
            'account_id' => $advanceAccount->id,
            'debit' => '0.000',
            'credit' => $amount,
            'line_order' => 1,
        ]);
    }

    private function assertImmediatePureAdvance(FiscalEvent $event, string $amount): void
    {
        $payment = Payment::query()->where('fiscal_event_id', $event->id)->sole();
        $this->assertNull($payment->instrument_id);
        $this->assertSame(0, PaymentInstrument::query()->count());
        $this->assertSame(1, DB::table('repository_movements')->where('source_id', $event->id)->count());
        $repository = PaymentRepository::query()->findOrFail($this->repositoryId);
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $payment->journal_entry_id,
            'account_id' => $repository->gl_account_id,
            'debit' => $amount,
            'credit' => '0.000',
            'line_order' => 0,
        ]);
    }

    private function depositEvent(string $amount, string $methodCode): FiscalEvent
    {
        app(CompanyContext::class)->setCompanyId($this->companyId);
        try {
            return $this->app->make(VirtualAdminFiscalEventService::class)->appendDepositReceipt(
                partner: Partner::query()->findOrFail($this->partnerId),
                actorUserId: $this->actorId,
                actorName: 'Sibling Bridge Actor',
                currencyCode: 'TND',
                amount: $amount,
                methodCode: $methodCode,
                repositoryId: $this->repositoryId,
                notes: null,
            );
        } finally {
            app(CompanyContext::class)->clear();
        }
    }

    private function accountPaymentEvent(string $amount, string $methodCode, string $cashierId): FiscalEvent
    {
        $businessDate = now()->toDateString();
        $payload = [
            'account_payment_uuid' => (string) Str::uuid(),
            'business_date' => $businessDate,
            'cashier_id' => $cashierId,
            'cashier_name' => 'Device Cashier',
            'currency_code' => 'TND',
            'currency_scale' => 3,
            'customer' => [
                'address' => null,
                'customer_category' => 'retail',
                'customer_id' => $this->partnerId,
                'customer_sync_status' => 'synced',
                'email' => null,
                'name' => 'Sibling Bridge Customer',
                'phone' => null,
                'tax_number' => null,
            ],
            'event_time_device' => now()->format('Y-m-d\TH:i:s.v\Z'),
            'local_balance_snapshot' => [
                'balance_updated_at' => now()->format('Y-m-d\TH:i:s.v\Z'),
                'credit_balance_before' => '0.000',
                'net_balance_before' => '0.000',
                'payment_amount' => $amount,
                'projected_credit_balance_after' => $amount,
                'projected_net_balance_after' => '0.000',
                'projected_receivable_balance_after' => '0.000',
                'receivable_balance_before' => '0.000',
            ],
            'notes' => null,
            'payment' => [
                'amount' => $amount,
                'foreign_currency_amount' => null,
                'foreign_currency_code' => null,
                'instrument_serial' => null,
                'instrument_type' => null,
                'method_code' => $methodCode,
                'repository_id' => $this->repositoryId,
            ],
            'receipt_type_code' => 'ACCOUNT_PAYMENT',
            'references' => null,
            'regime_extensions' => null,
            'seller' => [
                'address' => ['city' => 'Tunis', 'country_code' => 'TN', 'postal_code' => '1000', 'street' => '1 rue Test'],
                'name' => 'Default Seller',
                'tax_jurisdiction_country_code' => 'TN',
                'tax_number' => '1234567AM000',
            ],
            'shift_id' => (string) Str::uuid(),
            'staleness' => [
                'balance_snapshot_stale' => false,
                'customer_snapshot_stale' => false,
                'mirror_last_synced_at' => now()->format('Y-m-d\TH:i:s.v\Z'),
                'staleness_reason' => null,
            ],
            'terminal_id' => '33333333-3333-4333-8333-333333333333',
            'training_flag' => false,
            'treasury_allocation_policy' => 'FIFO',
        ];

        return FiscalEvent::query()->create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'terminal_id' => '33333333-3333-4333-8333-333333333333',
            'operator_id' => $cashierId,
            'event_type' => FiscalEventType::ACCOUNT_PAYMENT,
            'event_version' => 1,
            'signature_version' => 'hash-chain-integrity-v1',
            'sequence_number' => 7,
            'event_time_device' => now(),
            'business_date' => $businessDate,
            'server_received_at' => now(),
            'source_event_class' => 'account_payments',
            'source_event_id' => $payload['account_payment_uuid'],
            'canonical_bytes' => json_encode($payload, JSON_THROW_ON_ERROR),
            'previous_hash' => str_repeat('a', 64),
            'current_hash' => str_repeat('b', 64),
            'signature_status' => SignatureStatus::NotRequired,
            'integrity_status' => IntegrityStatus::Verified,
            'payload' => $payload,
            'payload_parse_status' => PayloadParseStatus::Parsed,
        ])->refresh();
    }
}
