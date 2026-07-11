<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\Exceptions\ClosedFiscalPeriodException;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\PeriodStatus;
use App\Modules\Company\Domain\FiscalPeriod;
use App\Modules\Company\Domain\FiscalYear;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\DTOs\ClearInstrumentData;
use App\Modules\Treasury\Application\Services\InstrumentLifecycleService;
use App\Modules\Treasury\Application\Services\InstrumentRemittanceService;
use App\Modules\Treasury\Domain\Enums\InstrumentDirection;
use App\Modules\Treasury\Domain\Enums\InstrumentKind;
use App\Modules\Treasury\Domain\Enums\InstrumentOrigin;
use App\Modules\Treasury\Domain\Enums\InstrumentStatus;
use App\Modules\Treasury\Domain\Enums\RemittanceLineStatus;
use App\Modules\Treasury\Domain\Enums\RemittanceType;
use App\Modules\Treasury\Domain\InstrumentEvent;
use App\Modules\Treasury\Domain\InstrumentRemittanceLine;
use App\Modules\Treasury\Domain\PaymentInstrument;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\RepositoryMovement;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

final class InstrumentClearTest extends TestCase
{
    use RefreshDatabase;

    public function test_clear_without_fees_posts_two_lines_and_moves_nominal_into_bank(): void
    {
        $context = $this->context();
        $instrument = $this->remittedInstrument($context, InstrumentKind::Cheque, '100.000');

        $cleared = $this->lifecycle()->clear(new ClearInstrumentData(
            instrumentId: $instrument->id,
            currency: 'TND',
            userId: $context['user']->id,
        ));

        $this->assertSame(InstrumentStatus::Cleared, $cleared->status);
        $movement = RepositoryMovement::query()->where('source_id', $instrument->id)->firstOrFail();
        $this->assertSame('100.000', $movement->amount);
        $this->assertSame('100.000', $context['bank']->fresh()?->balance);
        $entry = JournalEntry::query()->with('lines')->findOrFail($movement->journal_entry_id);
        $this->assertCount(2, $entry->lines);
        $bankDebit = $entry->lines->where('account_id', $context['bank']->gl_account_id)
            ->reduce(fn (string $sum, JournalLine $line): string => bcadd($sum, $line->debit, 3), '0');
        $this->assertSame('100.000', $bankDebit);
        $this->assertSame(0, bccomp($movement->amount, '100.000', 3));
    }

    public function test_clear_with_fee_and_vat_moves_net_equal_to_bank_je_line(): void
    {
        $context = $this->context();
        $instrument = $this->remittedInstrument($context, InstrumentKind::Effet, '100.000');

        $this->lifecycle()->clear(new ClearInstrumentData(
            instrumentId: $instrument->id,
            currency: 'TND',
            feeAmount: '1.000',
            feeVatAmount: '0.190',
            valueDate: '2026-07-12',
            userId: $context['user']->id,
        ));

        $movement = RepositoryMovement::query()->where('source_id', $instrument->id)->firstOrFail();
        $entry = JournalEntry::query()->with('lines.account')->findOrFail($movement->journal_entry_id);
        $bankDebit = $entry->lines->where('account_id', $context['bank']->gl_account_id)
            ->reduce(fn (string $sum, JournalLine $line): string => bcadd($sum, $line->debit, 3), '0');
        $this->assertSame('98.810', $movement->amount);
        $this->assertSame(0, bccomp($movement->amount, $bankDebit, 3));
        $this->assertSame('1.000', $entry->lines->firstWhere('account.code', '6275')?->debit);
        $this->assertSame('0.190', $entry->lines->firstWhere('account.code', '43666')?->debit);
        $this->assertSame('100.000', $entry->lines->firstWhere('account.code', '5313')?->credit);
        $this->assertSame(RemittanceLineStatus::Cleared, $this->line($instrument)->line_status);
        $this->assertNotNull(InstrumentEvent::query()->where('instrument_id', $instrument->id)->where('event_type', 'cleared')->first());
        $this->assertSame(0, Artisan::call('treasury:reconcile', ['--tenant' => $context['tenant']->id]));
        $this->assertNull($context['bank']->fresh()?->frozen_at);
    }

    public function test_double_clear_writes_one_movement_and_second_call_is_rejected(): void
    {
        $context = $this->context();
        $instrument = $this->remittedInstrument($context, InstrumentKind::Cheque, '40.000');
        $data = new ClearInstrumentData($instrument->id, 'TND', userId: $context['user']->id);
        $this->lifecycle()->clear($data);

        try {
            $this->lifecycle()->clear($data);
            $this->fail('second clear must be rejected');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(1, RepositoryMovement::query()->where('source_id', $instrument->id)->count());
    }

    public function test_clear_requires_pending_line_on_remitted_slip(): void
    {
        $context = $this->context();
        $instrument = $this->instrument($context, InstrumentKind::Cheque, '25.000');

        $this->expectException(DomainException::class);
        $this->lifecycle()->clear(new ClearInstrumentData($instrument->id, 'TND', userId: $context['user']->id));
    }

    public function test_gl_post_failure_rolls_back_the_entire_clear(): void
    {
        $context = $this->context();
        $instrument = $this->remittedInstrument($context, InstrumentKind::Cheque, '25.000');
        FiscalPeriod::query()->where('company_id', $context['company']->id)->delete();
        FiscalYear::query()->where('company_id', $context['company']->id)->delete();
        $year = FiscalYear::query()->create([
            'company_id' => $context['company']->id,
            'name' => '2025',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
        ]);
        FiscalPeriod::query()->create([
            'fiscal_year_id' => $year->id,
            'company_id' => $context['company']->id,
            'name' => 'July 2025',
            'period_number' => 7,
            'start_date' => '2025-07-01',
            'end_date' => '2025-07-31',
            'status' => PeriodStatus::Closed,
            'closed_at' => now(),
            'closed_by' => $context['user']->id,
        ]);
        $journalCount = JournalEntry::query()->count();

        try {
            $this->lifecycle()->clear(new ClearInstrumentData(
                $instrument->id,
                'TND',
                valueDate: '2025-07-12',
                userId: $context['user']->id,
            ));
            $this->fail('closed-period GL post must fail');
        } catch (ClosedFiscalPeriodException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(InstrumentStatus::Deposited, $instrument->fresh()?->status);
        $this->assertSame(RemittanceLineStatus::Pending, $this->line($instrument)->line_status);
        $this->assertSame('0.000', $context['bank']->fresh()?->balance);
        $this->assertSame(0, RepositoryMovement::query()->where('source_id', $instrument->id)->count());
        $this->assertSame($journalCount, JournalEntry::query()->count());
    }

    private function lifecycle(): InstrumentLifecycleService
    {
        return app(InstrumentLifecycleService::class);
    }

    /** @param array{tenant: Tenant, company: Company, user: User, bank: PaymentRepository, safe: PaymentRepository} $context */
    private function remittedInstrument(array $context, InstrumentKind $kind, string $amount): PaymentInstrument
    {
        $instrument = $this->instrument($context, $kind, $amount);
        $service = app(InstrumentRemittanceService::class);
        $slip = $service->createDraft(
            $context['company']->id,
            $context['tenant']->id,
            $context['bank']->id,
            RemittanceType::Collection,
            $kind,
            $context['user']->id,
        );
        $service->addLine($slip->id, $instrument->id);
        $service->remit($slip->id, $context['user']->id);

        return $instrument->fresh() ?? $instrument;
    }

    private function line(PaymentInstrument $instrument): InstrumentRemittanceLine
    {
        return InstrumentRemittanceLine::query()->where('instrument_id', $instrument->id)->latest('id')->firstOrFail();
    }

    /** @param array{tenant: Tenant, company: Company, user: User, bank: PaymentRepository, safe: PaymentRepository} $context */
    private function instrument(array $context, InstrumentKind $kind, string $amount): PaymentInstrument
    {
        $method = PaymentMethod::factory()->create([
            'tenant_id' => $context['tenant']->id,
            'company_id' => $context['company']->id,
            'code' => strtoupper($kind->value).'-'.Str::upper(Str::random(8)),
            'has_maturity' => true,
            'instrument_kind' => $kind,
        ]);

        return PaymentInstrument::query()->create([
            'tenant_id' => $context['tenant']->id,
            'company_id' => $context['company']->id,
            'payment_method_id' => $method->id,
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
    }

    /** @return array{tenant: Tenant, company: Company, user: User, bank: PaymentRepository, safe: PaymentRepository} */
    private function context(): array
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
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

        return compact('tenant', 'company', 'user', 'bank', 'safe');
    }
}
