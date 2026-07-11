<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Models\Country;
use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\Exceptions\ClosedFiscalPeriodException;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\PeriodStatus;
use App\Modules\Company\Domain\FiscalPeriod;
use App\Modules\Company\Domain\FiscalYear;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\DTOs\BounceInstrumentData;
use App\Modules\Treasury\Application\DTOs\ClearInstrumentData;
use App\Modules\Treasury\Application\Services\CloseInvoiceWithToleranceService;
use App\Modules\Treasury\Application\Services\InstrumentLifecycleService;
use App\Modules\Treasury\Application\Services\InstrumentRemittanceService;
use App\Modules\Treasury\Domain\CountryPaymentSettings;
use App\Modules\Treasury\Domain\Enums\DishonorRouting;
use App\Modules\Treasury\Domain\Enums\InstrumentDirection;
use App\Modules\Treasury\Domain\Enums\InstrumentKind;
use App\Modules\Treasury\Domain\Enums\InstrumentOrigin;
use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentStatus;
use App\Modules\Treasury\Domain\Enums\PaymentType;
use App\Modules\Treasury\Domain\Enums\RemittanceLineStatus;
use App\Modules\Treasury\Domain\Enums\RemittanceType;
use App\Modules\Treasury\Domain\InstrumentEvent;
use App\Modules\Treasury\Domain\InstrumentRemittanceLine;
use App\Modules\Treasury\Domain\Payment;
use App\Modules\Treasury\Domain\PaymentAllocation;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\RepositoryMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class InstrumentBounceTest extends TestCase
{
    use RefreshDatabase;

    public function test_dishonored_at_migration_is_idempotent_and_payment_casts_it(): void
    {
        $migration = require database_path('migrations/tenant/2026_07_12_100500_add_dishonored_at_to_payments.php');
        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasColumn('payments', 'dishonored_at'));
    }

    public function test_effet_bounce_before_clear_routes_to_receivable_and_reopens_allocations(): void
    {
        $context = $this->context();
        [$instrument, $payment, $document] = $this->paidRemittedInstrument($context, InstrumentKind::Effet, '100.000');

        $this->lifecycle()->bounce(new BounceInstrumentData(
            instrumentId: $instrument->id,
            routing: DishonorRouting::Receivable,
            currency: 'TND',
            reason: 'Insufficient funds',
            userId: $context['user']->id,
        ));

        $this->assertSame(InstrumentStatus::Bounced, $instrument->fresh()?->status);
        $this->assertSame(RemittanceLineStatus::Bounced, $this->line($instrument)->line_status);
        $this->assertSame('closed', $this->line($instrument)->remittance->status->value);
        $this->assertSame(0, RepositoryMovement::query()->where('source_id', $instrument->id)->count());
        $this->assertSame(2, PaymentAllocation::query()->where('payment_id', $payment->id)->count());
        $this->assertSame('-100.000', PaymentAllocation::query()->where('payment_id', $payment->id)->where('amount', '<', 0)->sole()->amount);
        $document->refresh();
        $this->assertSame('100.000', $document->balance_due);
        $this->assertSame(DocumentStatus::Posted, $document->status);
        $this->assertNotNull($payment->fresh()?->dishonored_at);

        $entry = JournalEntry::query()->with('lines.account')->where('source_id', $instrument->id)->latest('created_at')->firstOrFail();
        $this->assertSame('100.000', $entry->lines->firstWhere('account.code', '411')?->debit);
        $this->assertSame($context['partner']->id, $entry->lines->firstWhere('account.code', '411')->partner_id);
        $this->assertSame('100.000', $entry->lines->firstWhere('account.code', '5313')?->credit);
    }

    public function test_cheque_bounce_fee_moves_only_fee_and_reconciles(): void
    {
        $context = $this->context();
        [$instrument] = $this->paidRemittedInstrument($context, InstrumentKind::Cheque, '100.000');

        $this->lifecycle()->bounce(new BounceInstrumentData(
            instrumentId: $instrument->id,
            routing: DishonorRouting::Receivable,
            currency: 'TND',
            feeAmount: '1.000',
            feeVatAmount: '0.190',
            userId: $context['user']->id,
        ));

        $movement = RepositoryMovement::query()->where('source_id', $instrument->id)->sole();
        $this->assertSame('1.190', $movement->amount);
        $context['bank']->refresh();
        $this->assertSame('-1.190', $context['bank']->balance);
        $this->assertSame(0, Artisan::call('treasury:reconcile', ['--tenant' => $context['tenant']->id]));
        $context['bank']->refresh();
        $this->assertNull($context['bank']->frozen_at);
    }

    public function test_dishonor_after_clear_claws_back_nominal_plus_fees_equal_to_bank_credit(): void
    {
        $context = $this->context();
        [$instrument] = $this->paidRemittedInstrument($context, InstrumentKind::Cheque, '100.000');
        $this->lifecycle()->clear(new ClearInstrumentData($instrument->id, 'TND', userId: $context['user']->id));

        $this->lifecycle()->bounce(new BounceInstrumentData(
            instrumentId: $instrument->id,
            routing: DishonorRouting::Doubtful,
            currency: 'TND',
            feeAmount: '1.000',
            feeVatAmount: '0.190',
            userId: $context['user']->id,
        ));

        $movement = RepositoryMovement::query()->where('idempotency_key', 'like', '%:dishonor:%')->sole();
        $entry = JournalEntry::query()->with('lines.account')->findOrFail($movement->journal_entry_id);
        $bankCredit = $entry->lines->where('account_id', $context['bank']->gl_account_id)
            ->reduce(fn (string $sum, JournalLine $line): string => bcadd($sum, $line->credit, 3), '0');
        $this->assertSame('101.190', $movement->amount);
        $this->assertSame(0, bccomp($movement->amount, $bankCredit, 3));
        $this->assertSame('100.000', $entry->lines->firstWhere('account.code', '416')?->debit);
        $context['bank']->refresh();
        $this->assertSame('-1.190', $context['bank']->balance);
        $this->assertSame(0, Artisan::call('treasury:reconcile', ['--tenant' => $context['tenant']->id]));
        $context['bank']->refresh();
        $this->assertNull($context['bank']->frozen_at);
    }

    public function test_represent_routing_keeps_allocations_and_paid_document_untouched(): void
    {
        $context = $this->context();
        [$instrument, $payment, $document] = $this->paidRemittedInstrument($context, InstrumentKind::Effet, '80.000');

        $this->lifecycle()->bounce(new BounceInstrumentData(
            $instrument->id,
            DishonorRouting::RePresent,
            'TND',
            userId: $context['user']->id,
        ));

        $this->assertSame(1, PaymentAllocation::query()->where('payment_id', $payment->id)->count());
        $document->refresh();
        $this->assertSame(DocumentStatus::Paid, $document->status);
        $this->assertSame('0.000', $document->balance_due);
        $this->assertNotNull($payment->fresh()?->dishonored_at);
        $this->assertSame(DishonorRouting::RePresent, $instrument->fresh()?->dishonor_routing);
        $this->assertNotNull(InstrumentEvent::query()->where('instrument_id', $instrument->id)->where('event_type', 'bounced')->first());
    }

    public function test_tolerance_closed_invoice_reopens_the_full_original_receivable(): void
    {
        $context = $this->context();
        [$instrument, $payment, $document] = $this->paidRemittedInstrument($context, InstrumentKind::Effet, '100.000');
        PaymentAllocation::query()->where('payment_id', $payment->id)->update(['amount' => '99.800']);
        $document->update(['balance_due' => '0.200', 'status' => DocumentStatus::Posted]);
        Country::query()->firstOrCreate(
            ['code' => 'TN'],
            ['name' => 'Tunisia', 'currency_code' => 'TND', 'currency_symbol' => 'DT'],
        );
        CountryPaymentSettings::query()->updateOrCreate(
            ['country_code' => 'TN'],
            [
                'payment_tolerance_enabled' => true,
                'payment_tolerance_percentage' => '0.0100',
                'max_payment_tolerance_amount' => '1.000',
            ],
        );
        app(CloseInvoiceWithToleranceService::class)->close($document->id, $context['user']->id);
        $toleranceEntry = JournalEntry::query()
            ->where('source_type', 'payment_tolerance')
            ->where('source_id', $document->id)
            ->firstOrFail();
        if ($toleranceEntry->status->value === 'draft') {
            app(GeneralLedgerService::class)->postEntryNow($toleranceEntry->load('lines'), $context['user'], 'TND');
        }

        $this->lifecycle()->bounce(new BounceInstrumentData(
            $instrument->id,
            DishonorRouting::Receivable,
            'TND',
            userId: $context['user']->id,
        ));

        $negativeTolerance = PaymentAllocation::query()
            ->whereNull('payment_id')
            ->where('document_id', $document->id)
            ->where('amount', '<', 0)
            ->sole();
        $this->assertSame('-0.200', $negativeTolerance->amount);
        $this->assertSame('-0.2000', $negativeTolerance->tolerance_writeoff);
        $document->refresh();
        $this->assertSame('100.000', $document->balance_due);
        $this->assertSame(DocumentStatus::Posted, $document->status);
        $this->assertSame(
            1,
            JournalEntry::query()
                ->where('source_type', 'instrument_tolerance_reversal')
                ->where('source_id', $instrument->id)
                ->count(),
        );
    }

    public function test_gl_failure_rolls_back_dishonor_allocations_and_lifecycle_state(): void
    {
        $context = $this->context();
        [$instrument, $payment, $document] = $this->paidRemittedInstrument($context, InstrumentKind::Cheque, '30.000');
        FiscalPeriod::query()->where('company_id', $context['company']->id)->delete();
        FiscalYear::query()->where('company_id', $context['company']->id)->delete();
        $year = FiscalYear::query()->create([
            'company_id' => $context['company']->id,
            'name' => '2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]);
        FiscalPeriod::query()->create([
            'fiscal_year_id' => $year->id,
            'company_id' => $context['company']->id,
            'name' => 'Current year',
            'period_number' => 1,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => PeriodStatus::Closed,
            'closed_at' => now(),
            'closed_by' => $context['user']->id,
        ]);
        $journalCount = JournalEntry::query()->count();

        try {
            $this->lifecycle()->bounce(new BounceInstrumentData(
                $instrument->id,
                DishonorRouting::Receivable,
                'TND',
                feeAmount: '1.000',
                userId: $context['user']->id,
            ));
            $this->fail('closed-period dishonor post must fail');
        } catch (ClosedFiscalPeriodException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(InstrumentStatus::Deposited, $instrument->fresh()?->status);
        $this->assertSame(RemittanceLineStatus::Pending, $this->line($instrument)->line_status);
        $document->refresh();
        $this->assertSame(DocumentStatus::Paid, $document->status);
        $this->assertSame('0.000', $document->balance_due);
        $this->assertNull($payment->fresh()?->dishonored_at);
        $this->assertSame(1, PaymentAllocation::query()->where('payment_id', $payment->id)->count());
        $this->assertSame(0, RepositoryMovement::query()->where('source_id', $instrument->id)->count());
        $this->assertSame($journalCount, JournalEntry::query()->count());
    }

    public function test_postgres_lock_trace_places_documents_before_the_gl_advisory_lock(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Row/advisory lock ordering is PostgreSQL-specific.');
        }

        $context = $this->context();
        [$instrument] = $this->paidRemittedInstrument($context, InstrumentKind::Effet, '20.000');
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->lifecycle()->bounce(new BounceInstrumentData(
            $instrument->id,
            DishonorRouting::Receivable,
            'TND',
            userId: $context['user']->id,
        ));

        $instrumentLock = $this->queryPosition($queries, 'from "payment_instruments"', 'for update');
        $documentLock = $this->queryPosition($queries, 'from "documents"', 'for update');
        $glAdvisory = $this->queryPosition($queries, 'pg_advisory_xact_lock');
        $this->assertLessThan($documentLock, $instrumentLock);
        $this->assertLessThan($glAdvisory, $documentLock);
    }

    /** @param list<string> $queries */
    private function queryPosition(array $queries, string ...$needles): int
    {
        foreach ($queries as $position => $query) {
            if (collect($needles)->every(fn (string $needle): bool => str_contains($query, $needle))) {
                return $position;
            }
        }

        $this->fail('Expected lock query was not observed: '.implode(', ', $needles));
    }

    private function lifecycle(): InstrumentLifecycleService
    {
        return app(InstrumentLifecycleService::class);
    }

    private function line(PaymentInstrument $instrument): InstrumentRemittanceLine
    {
        return InstrumentRemittanceLine::query()->where('instrument_id', $instrument->id)->orderByDesc('id')->firstOrFail();
    }

    /**
     * @param  array{tenant: Tenant, company: Company, user: User, partner: Partner, bank: PaymentRepository, safe: PaymentRepository}  $context
     * @return array{PaymentInstrument, Payment, Document}
     */
    private function paidRemittedInstrument(array $context, InstrumentKind $kind, string $amount): array
    {
        $method = PaymentMethod::factory()->create([
            'tenant_id' => $context['tenant']->id,
            'company_id' => $context['company']->id,
            'code' => strtoupper($kind->value).'-'.Str::upper(Str::random(8)),
            'has_maturity' => true,
            'instrument_kind' => $kind,
        ]);
        $document = Document::factory()->create([
            'tenant_id' => $context['tenant']->id,
            'company_id' => $context['company']->id,
            'partner_id' => $context['partner']->id,
            'currency' => 'TND',
            'total' => $amount,
            'balance_due' => '0.000',
            'status' => DocumentStatus::Paid,
        ]);
        $payment = Payment::query()->create([
            'tenant_id' => $context['tenant']->id,
            'company_id' => $context['company']->id,
            'partner_id' => $context['partner']->id,
            'payment_method_id' => $method->id,
            'amount' => $amount,
            'currency' => 'TND',
            'payment_date' => now(),
            'status' => PaymentStatus::Completed,
            'payment_type' => PaymentType::DocumentPayment,
        ]);
        $instrument = PaymentInstrument::query()->create([
            'tenant_id' => $context['tenant']->id,
            'company_id' => $context['company']->id,
            'payment_method_id' => $method->id,
            'payment_id' => $payment->id,
            'partner_id' => $context['partner']->id,
            'reference' => 'REF-'.Str::upper(Str::random(8)),
            'amount' => $amount,
            'currency' => 'TND',
            'received_date' => now()->toDateString(),
            'status' => InstrumentStatus::Received,
            'kind' => $kind,
            'direction' => InstrumentDirection::Inbound,
            'origin' => InstrumentOrigin::Web,
            'repository_id' => $context['safe']->id,
        ]);
        $payment->update(['instrument_id' => $instrument->id]);
        PaymentAllocation::query()->create([
            'payment_id' => $payment->id,
            'document_id' => $document->id,
            'amount' => $amount,
        ]);
        $remittance = app(InstrumentRemittanceService::class)->createDraft(
            $context['company']->id,
            $context['tenant']->id,
            $context['bank']->id,
            RemittanceType::Collection,
            $kind,
            $context['user']->id,
        );
        app(InstrumentRemittanceService::class)->addLine($remittance->id, $instrument->id);
        app(InstrumentRemittanceService::class)->remit($remittance->id, $context['user']->id);

        return [$instrument->fresh() ?? $instrument, $payment, $document];
    }

    /** @return array{tenant: Tenant, company: Company, user: User, partner: Partner, bank: PaymentRepository, safe: PaymentRepository} */
    private function context(): array
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $partner = Partner::factory()->create(['tenant_id' => $tenant->id, 'company_id' => $company->id]);
        app(ChartOfAccountsService::class)->seedForCompany($company);
        $bankAccount = Account::findByPurposeOrFail($company->id, SystemAccountPurpose::Bank);
        $bank = PaymentRepository::factory()->for($company)->create([
            'tenant_id' => $tenant->id,
            'type' => 'bank_account',
            'code' => 'BANK-'.Str::upper(Str::random(6)),
            'balance' => '0.000',
            'gl_account_id' => $bankAccount->id,
            'currency' => 'TND',
        ]);
        $safe = PaymentRepository::factory()->for($company)->create([
            'tenant_id' => $tenant->id,
            'type' => 'safe',
            'code' => 'SAFE-'.Str::upper(Str::random(6)),
            'balance' => '0.000',
            'currency' => 'TND',
        ]);

        return compact('tenant', 'company', 'user', 'partner', 'bank', 'safe');
    }
}
