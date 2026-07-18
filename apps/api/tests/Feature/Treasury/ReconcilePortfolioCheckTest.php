<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Company\Domain\Company;
use App\Modules\Compliance\Domain\AuditEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\DTOs\ClearInstrumentData;
use App\Modules\Treasury\Application\Services\InstrumentLifecycleService;
use App\Modules\Treasury\Application\Services\InstrumentRemittanceService;
use App\Modules\Treasury\Domain\Enums\InstrumentDirection;
use App\Modules\Treasury\Domain\Enums\InstrumentKind;
use App\Modules\Treasury\Domain\Enums\InstrumentOrigin;
use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Enums\RemittanceType;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ReconcilePortfolioCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_linked_pending_portfolio_matches_full_reserved_account_balances(): void
    {
        $context = $this->context();

        $cheque = $this->linkedInstrument($context, InstrumentKind::Cheque, InstrumentStatus::Received, '100.000');
        $this->linkedInstrument($context, InstrumentKind::Effet, InstrumentStatus::Received, '60.000');
        $this->linkedInstrument($context, InstrumentKind::Effet, InstrumentStatus::Deposited, '40.000');
        $this->manualInstrument($context, InstrumentKind::Cheque, '999.000');

        $this->postPortfolioDebit($context, '5312', '100.000');
        $this->postPortfolioDebit($context, '413', '60.000');
        $this->postPortfolioDebit($context, '5313', '40.000');

        $this->assertSame(0, Artisan::call('treasury:reconcile', ['--tenant' => $context['tenant']->id]));

        $remittances = app(InstrumentRemittanceService::class);
        $slip = $remittances->createDraft(
            $context['company']->id,
            $context['tenant']->id,
            $context['bank']->id,
            RemittanceType::Collection,
            InstrumentKind::Cheque,
            $context['user']->id,
        );
        $remittances->addLine($slip->id, $cheque->id);
        $remittances->remit($slip->id, $context['user']->id);
        $this->assertSame(0, Artisan::call('treasury:reconcile', ['--tenant' => $context['tenant']->id]));

        app(InstrumentLifecycleService::class)->clear(new ClearInstrumentData(
            instrumentId: $cheque->id,
            currency: 'TND',
            userId: $context['user']->id,
        ));
        $this->assertSame(0, Artisan::call('treasury:reconcile', ['--tenant' => $context['tenant']->id]));
        $this->assertSame(0, AuditEvent::query()->where('event_type', 'treasury.reconcile.portfolio_drift')->count());
    }

    public function test_drift_in_both_directions_alerts_but_never_freezes_a_repository(): void
    {
        $context = $this->context();
        $repository = $context['bank'];

        // Orphan GL on checks (GL > instrument) and linked paper with no receipt
        // JE on effects (instrument > GL) are both load-bearing drift directions.
        $this->postPortfolioDebit($context, '5312', '25.000');
        $this->linkedInstrument($context, InstrumentKind::Effet, InstrumentStatus::Received, '40.000');

        $this->assertSame(1, Artisan::call('treasury:reconcile', ['--tenant' => $context['tenant']->id]));

        $event = AuditEvent::query()
            ->where('company_id', $context['company']->id)
            ->where('event_type', 'treasury.reconcile.portfolio_drift')
            ->sole();
        $mismatches = $event->payload['mismatches'];
        $this->assertIsArray($mismatches);
        $purposes = [];
        foreach ($mismatches as $mismatch) {
            $this->assertIsArray($mismatch);
            $purpose = $mismatch['purpose'] ?? null;
            $this->assertIsString($purpose);
            $purposes[] = $purpose;
        }
        sort($purposes);
        $this->assertSame(['checks_to_collect', 'effects_receivable'], $purposes);
        $this->assertNull($repository->fresh()?->frozen_at);
        $this->assertNull($repository->fresh()?->frozen_reason);
    }

    public function test_linked_outbound_received_and_bounced_match_payable_liability_balances(): void
    {
        $context = $this->context();

        $this->linkedInstrument(
            $context,
            InstrumentKind::Cheque,
            InstrumentStatus::Received,
            '75.000',
            InstrumentDirection::Outbound,
        );
        $this->linkedInstrument(
            $context,
            InstrumentKind::Effet,
            InstrumentStatus::Bounced,
            '45.000',
            InstrumentDirection::Outbound,
        );
        $this->postPortfolioCredit($context, '4035', '75.000');
        $this->postPortfolioCredit($context, '403', '45.000');

        $this->assertSame(0, Artisan::call('treasury:reconcile', ['--tenant' => $context['tenant']->id]));
        $this->assertSame(0, AuditEvent::query()->where('event_type', 'treasury.reconcile.portfolio_drift')->count());
    }

    public function test_outbound_payable_drift_alerts_but_never_freezes_a_repository(): void
    {
        $context = $this->context();

        $this->linkedInstrument(
            $context,
            InstrumentKind::Cheque,
            InstrumentStatus::Received,
            '40.000',
            InstrumentDirection::Outbound,
        );

        $this->assertSame(1, Artisan::call('treasury:reconcile', ['--tenant' => $context['tenant']->id]));

        $event = AuditEvent::query()
            ->where('company_id', $context['company']->id)
            ->where('event_type', 'treasury.reconcile.portfolio_drift')
            ->sole();
        $this->assertSame('checks_to_pay', $event->payload['mismatches'][0]['purpose']);
        $this->assertSame('40.000', $event->payload['mismatches'][0]['instrument_total']);
        $this->assertSame('0.000', $event->payload['mismatches'][0]['gl_balance']);
        $this->assertNull($context['bank']->fresh()?->frozen_at);
        $this->assertNull($context['bank']->fresh()?->frozen_reason);
    }

    public function test_cutover_watermark_excludes_older_instrument_rows_and_gl_noise(): void
    {
        Carbon::setTestNow('2026-07-01 10:00:00');
        $context = $this->context();
        $this->linkedInstrument($context, InstrumentKind::Cheque, InstrumentStatus::Received, '70.000');
        $this->postPortfolioDebit($context, '5312', '90.000');

        $context['company']->forceFill(['phase2_cutover_at' => '2026-07-02 00:00:00'])->save();
        Carbon::setTestNow('2026-07-03 10:00:00');

        $this->assertSame(0, Artisan::call('treasury:reconcile', ['--tenant' => $context['tenant']->id]));
        $this->assertSame(0, AuditEvent::query()->where('event_type', 'treasury.reconcile.portfolio_drift')->count());
    }

    public function test_pre_cutover_linked_instrument_with_post_cutover_remittance_is_one_excluded_circuit(): void
    {
        Carbon::setTestNow('2026-07-01 10:00:00');
        $context = $this->context();
        $effect = $this->linkedInstrument($context, InstrumentKind::Effet, InstrumentStatus::Received, '70.000');
        $this->postPortfolioDebit($context, '413', '70.000');

        $context['company']->forceFill(['phase2_cutover_at' => '2026-07-02 00:00:00'])->save();
        Carbon::setTestNow('2026-07-03 10:00:00');

        $remittances = app(InstrumentRemittanceService::class);
        $slip = $remittances->createDraft(
            $context['company']->id,
            $context['tenant']->id,
            $context['bank']->id,
            RemittanceType::Collection,
            InstrumentKind::Effet,
            $context['user']->id,
        );
        $remittances->addLine($slip->id, $effect->id);
        $remittances->remit($slip->id, $context['user']->id);

        $this->assertSame(0, Artisan::call('treasury:reconcile', ['--tenant' => $context['tenant']->id]));
        $this->assertSame(0, AuditEvent::query()->where('event_type', 'treasury.reconcile.portfolio_drift')->count());
    }

    public function test_missing_portfolio_accounts_are_skipped_during_chart_reseed_window(): void
    {
        $tenant = Tenant::factory()->create();
        Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);

        $this->assertSame(0, Artisan::call('treasury:reconcile', ['--tenant' => $tenant->id]));
        $this->assertSame(0, AuditEvent::query()->where('event_type', 'treasury.reconcile.portfolio_drift')->count());
    }

    /** @return array{tenant: Tenant, company: Company, partner: Partner, method: PaymentMethod, user: User, bank: PaymentRepository, safe: PaymentRepository} */
    private function context(): array
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);
        app(ChartOfAccountsService::class)->seedForCompany($company);
        $partner = Partner::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
        ]);
        $method = PaymentMethod::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'has_maturity' => true,
            'instrument_kind' => InstrumentKind::Cheque,
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $bankAccount = Account::findByPurposeOrFail($company->id, SystemAccountPurpose::Bank);
        $bank = PaymentRepository::factory()->for($company)->create([
            'tenant_id' => $tenant->id,
            'type' => 'bank_account',
            'code' => 'BANK-'.Str::upper(Str::random(6)),
            'balance' => '0.000',
            'next_movement_ordinal' => 0,
            'gl_account_id' => $bankAccount->id,
            'currency' => 'TND',
        ]);
        $safe = PaymentRepository::factory()->for($company)->create([
            'tenant_id' => $tenant->id,
            'type' => 'safe',
            'code' => 'SAFE-'.Str::upper(Str::random(6)),
            'balance' => '0.000',
            'next_movement_ordinal' => 0,
            'currency' => 'TND',
        ]);

        return compact('tenant', 'company', 'partner', 'method', 'user', 'bank', 'safe');
    }

    /**
     * @param  array{tenant: Tenant, company: Company, partner: Partner, method: PaymentMethod, user: User, bank: PaymentRepository, safe: PaymentRepository}  $context
     */
    private function linkedInstrument(
        array $context,
        InstrumentKind $kind,
        InstrumentStatus $status,
        string $amount,
        InstrumentDirection $direction = InstrumentDirection::Inbound,
    ): PaymentInstrument {
        $instrument = $this->instrument($context, $kind, $status, $amount, $direction);
        $payment = Payment::query()->create([
            'tenant_id' => $context['tenant']->id,
            'company_id' => $context['company']->id,
            'partner_id' => $context['partner']->id,
            'payment_method_id' => $context['method']->id,
            'instrument_id' => $instrument->id,
            'amount' => $amount,
            'currency' => 'TND',
            'payment_date' => now()->toDateString(),
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::DocumentPayment,
            'reference' => 'PAY-'.Str::upper(Str::random(8)),
        ]);
        $instrument->update(['payment_id' => $payment->id]);

        return $instrument;
    }

    /**
     * @param  array{tenant: Tenant, company: Company, partner: Partner, method: PaymentMethod, user: User, bank: PaymentRepository, safe: PaymentRepository}  $context
     */
    private function manualInstrument(array $context, InstrumentKind $kind, string $amount): PaymentInstrument
    {
        return $this->instrument($context, $kind, InstrumentStatus::Received, $amount);
    }

    /**
     * @param  array{tenant: Tenant, company: Company, partner: Partner, method: PaymentMethod, user: User, bank: PaymentRepository, safe: PaymentRepository}  $context
     */
    private function instrument(
        array $context,
        InstrumentKind $kind,
        InstrumentStatus $status,
        string $amount,
        InstrumentDirection $direction = InstrumentDirection::Inbound,
    ): PaymentInstrument {
        return PaymentInstrument::query()->create([
            'tenant_id' => $context['tenant']->id,
            'company_id' => $context['company']->id,
            'payment_method_id' => $context['method']->id,
            'reference' => 'INST-'.Str::upper(Str::random(8)),
            'amount' => $amount,
            'currency' => 'TND',
            'received_date' => now()->toDateString(),
            'maturity_date' => now()->addMonth()->toDateString(),
            'status' => $status,
            'direction' => $direction,
            'kind' => $kind,
            'origin' => InstrumentOrigin::Web,
            'repository_id' => $context['safe']->id,
            'deposited_at' => $status === InstrumentStatus::Deposited ? now() : null,
        ]);
    }

    /**
     * @param  array{tenant: Tenant, company: Company, partner: Partner, method: PaymentMethod, user: User, bank: PaymentRepository, safe: PaymentRepository}  $context
     */
    private function postPortfolioDebit(array $context, string $accountCode, string $amount): void
    {
        $portfolio = Account::query()
            ->where('company_id', $context['company']->id)
            ->where('code', $accountCode)
            ->firstOrFail();
        $offset = Account::query()
            ->where('company_id', $context['company']->id)
            ->where('code', '411')
            ->firstOrFail();
        $entry = JournalEntry::query()->create([
            'tenant_id' => $context['tenant']->id,
            'company_id' => $context['company']->id,
            'entry_number' => 'EF-'.Str::upper(Str::random(8)),
            'entry_date' => now()->toDateString(),
            'description' => 'Portfolio reconcile fixture',
            'status' => JournalEntryStatus::Posted,
            'source_type' => 'payment',
            'source_id' => (string) Str::uuid(),
            'posted_at' => now(),
        ]);
        JournalLine::query()->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $portfolio->id,
            'debit' => $amount,
            'credit' => '0',
            'line_order' => 0,
        ]);
        JournalLine::query()->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $offset->id,
            'debit' => '0',
            'credit' => $amount,
            'line_order' => 1,
        ]);
    }

    /**
     * @param  array{tenant: Tenant, company: Company, partner: Partner, method: PaymentMethod, user: User, bank: PaymentRepository, safe: PaymentRepository}  $context
     */
    private function postPortfolioCredit(array $context, string $accountCode, string $amount): void
    {
        $portfolio = Account::query()
            ->where('company_id', $context['company']->id)
            ->where('code', $accountCode)
            ->firstOrFail();
        $offset = Account::query()
            ->where('company_id', $context['company']->id)
            ->where('code', '401')
            ->firstOrFail();
        $entry = JournalEntry::query()->create([
            'tenant_id' => $context['tenant']->id,
            'company_id' => $context['company']->id,
            'entry_number' => 'EF-'.Str::upper(Str::random(8)),
            'entry_date' => now()->toDateString(),
            'description' => 'Outbound portfolio reconcile fixture',
            'status' => JournalEntryStatus::Posted,
            'source_type' => 'instrument',
            'source_id' => (string) Str::uuid(),
            'posted_at' => now(),
        ]);
        JournalLine::query()->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $offset->id,
            'debit' => $amount,
            'credit' => '0',
            'line_order' => 0,
        ]);
        JournalLine::query()->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $portfolio->id,
            'debit' => '0',
            'credit' => $amount,
            'line_order' => 1,
        ]);
    }
}
