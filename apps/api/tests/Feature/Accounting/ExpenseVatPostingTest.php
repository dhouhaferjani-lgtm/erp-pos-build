<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Application\Services\PartnerBalanceService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Compliance\Domain\AuditEvent;
use App\Modules\Document\Domain\Document;
use App\Modules\Expense\Application\DTOs\PayExpenseRequestData;
use App\Modules\Expense\Application\Services\ExpenseService;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Taxation\Domain\Entities\DocumentTaxDetail;
use App\Modules\Taxation\Domain\Enums\TaxType;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\RepositoryMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class ExpenseVatPostingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Partner $supplier;

    private ExpenseService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->tunisia()->create([
            'tenant_id' => $this->tenant->id,
        ]);
        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);
        $this->supplier = Partner::factory()->supplier()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
        app(ChartOfAccountsService::class)->seedForCompany($this->company);
        $this->service = app(ExpenseService::class);
    }

    public function test_unpaid_expense_posts_net_and_input_vat_against_partner_payable(): void
    {
        [, $entry] = $this->postVatExpense();

        $this->assertSame([
            $this->expectedLine(SystemAccountPurpose::GeneralExpense, null, '100.000', '0.000', 'VAT Vendor', 0),
            $this->expectedLine(SystemAccountPurpose::VatDeductible, null, '19.000', '0.000', 'TVA déductible', 1),
            $this->expectedLine(SystemAccountPurpose::SupplierPayable, $this->supplier->id, '0.000', '119.000', 'Expense payable', 2),
        ], $this->linePayloads($entry));
    }

    public function test_paid_expense_posts_total_to_cash_without_changing_the_credit_side(): void
    {
        $repository = $this->cashRepository();

        [, $entry] = $this->postVatExpense([
            'is_paid' => true,
            'payment_repository_id' => $repository->id,
        ]);

        $this->assertSame([
            $this->expectedLine(SystemAccountPurpose::GeneralExpense, null, '100.000', '0.000', 'VAT Vendor', 0),
            $this->expectedLine(SystemAccountPurpose::VatDeductible, null, '19.000', '0.000', 'TVA déductible', 1),
            $this->expectedLine(SystemAccountPurpose::Cash, null, '0.000', '119.000', 'Expense payment', 2),
        ], $this->linePayloads($entry));
    }

    public function test_eighty_percent_deductible_vat_uses_remainder_method_and_writes_matching_tax_detail(): void
    {
        [$expense, $entry] = $this->postVatExpense([
            'vat_deductible_percent' => '80.00',
        ]);

        $this->assertSame([
            $this->expectedLine(SystemAccountPurpose::GeneralExpense, null, '103.800', '0.000', 'VAT Vendor', 0),
            $this->expectedLine(SystemAccountPurpose::VatDeductible, null, '15.200', '0.000', 'TVA déductible', 1),
            $this->expectedLine(SystemAccountPurpose::SupplierPayable, $this->supplier->id, '0.000', '119.000', 'Expense payable', 2),
        ], $this->linePayloads($entry));

        $detail = DocumentTaxDetail::query()->where('document_id', $expense->id)->firstOrFail();
        $this->assertSame(TaxType::Percentage, $detail->tax_type);
        $this->assertSame('TVA 19.00%', $detail->tax_name);
        $this->assertSame('19.00', $detail->tax_rate);
        $this->assertSame('100.000', $detail->tax_base);
        $this->assertSame('15.200', $detail->tax_amount);
        $this->assertFalse($detail->is_stamp_duty);
    }

    public function test_half_millime_deductible_vat_rounds_half_up_to_one_millime(): void
    {
        [, $entry] = $this->postVatExpense([
            'total' => '1.001',
            'vat_amount' => '0.001',
            'vat_rate' => '0.10',
            'vat_deductible_percent' => '50.00',
        ]);

        $this->assertSame([
            $this->expectedLine(SystemAccountPurpose::GeneralExpense, null, '1.000', '0.000', 'VAT Vendor', 0),
            $this->expectedLine(SystemAccountPurpose::VatDeductible, null, '0.001', '0.000', 'TVA déductible', 1),
            $this->expectedLine(SystemAccountPurpose::SupplierPayable, $this->supplier->id, '0.000', '1.001', 'Expense payable', 2),
        ], $this->linePayloads($entry));
    }

    public function test_zero_percent_deductible_vat_omits_the_input_vat_line(): void
    {
        [$expense, $entry] = $this->postVatExpense([
            'vat_deductible_percent' => '0.00',
        ]);

        $this->assertSame([
            $this->expectedLine(SystemAccountPurpose::GeneralExpense, null, '119.000', '0.000', 'VAT Vendor', 0),
            $this->expectedLine(SystemAccountPurpose::SupplierPayable, $this->supplier->id, '0.000', '119.000', 'Expense payable', 1),
        ], $this->linePayloads($entry));
        $this->assertSame('0.000', DocumentTaxDetail::query()
            ->where('document_id', $expense->id)
            ->firstOrFail()
            ->tax_amount);
    }

    public function test_vatless_expense_keeps_the_exact_legacy_two_line_shape_and_has_no_tax_detail(): void
    {
        [$expense, $entry] = $this->postExpense([
            'total' => '119.000',
            'is_paid' => false,
        ]);

        $this->assertSame([
            $this->expectedLine(SystemAccountPurpose::GeneralExpense, null, '119.000', '0.000', 'VAT Vendor', 0),
            $this->expectedLine(SystemAccountPurpose::SupplierPayable, $this->supplier->id, '0.000', '119.000', 'Expense payable', 1),
        ], $this->linePayloads($entry));
        $this->assertFalse(DocumentTaxDetail::query()->where('document_id', $expense->id)->exists());
    }

    public function test_eur_expense_uses_two_decimal_currency_scale_for_the_split(): void
    {
        $this->company->update(['currency' => 'EUR']);
        app(CompanyContext::class)->clear();

        [, $entry] = $this->postVatExpense([
            'total' => '119.00',
            'vat_amount' => '19.00',
            'vat_deductible_percent' => '80.00',
        ]);

        $this->assertSame([
            $this->expectedLine(SystemAccountPurpose::GeneralExpense, null, '103.800', '0.000', 'VAT Vendor', 0),
            $this->expectedLine(SystemAccountPurpose::VatDeductible, null, '15.200', '0.000', 'TVA déductible', 1),
            $this->expectedLine(SystemAccountPurpose::SupplierPayable, $this->supplier->id, '0.000', '119.000', 'Expense payable', 2),
        ], $this->linePayloads($entry));
    }

    public function test_supplier_payable_balance_increases_by_gross_total_then_returns_to_prior_after_settlement(): void
    {
        $balanceService = app(PartnerBalanceService::class);
        $balanceService->refreshPartnerBalance($this->company->id, $this->supplier->id);
        $this->supplier->refresh();
        $priorPayable = $this->supplier->payable_balance;

        [$expense] = $this->postVatExpense();
        $balanceService->refreshPartnerBalance($this->company->id, $this->supplier->id);
        $this->supplier->refresh();
        $this->assertSame(bcadd($priorPayable, '119.000', 3), $this->supplier->payable_balance);

        $repository = $this->cashRepository();
        $this->service->settle(
            $expense,
            new PayExpenseRequestData($repository->id, null, '2026-07-13'),
            $this->user,
        );

        $balanceService->refreshPartnerBalance($this->company->id, $this->supplier->id);
        $this->supplier->refresh();
        $this->assertSame($priorPayable, $this->supplier->payable_balance);
    }

    public function test_paid_vat_expense_is_green_across_treasury_reconcile_checks_one_through_four(): void
    {
        $repository = $this->cashRepository();
        $cashAccount = $this->account(SystemAccountPurpose::Cash);
        $this->assertSame($cashAccount->id, $repository->gl_account_id);

        [, $entry] = $this->postVatExpense([
            'is_paid' => true,
            'payment_repository_id' => $repository->id,
        ]);
        $cashLine = $entry->lines()->where('account_id', $repository->gl_account_id)->firstOrFail();
        $this->assertSame('119.000', $cashLine->credit);

        app(CompanyContext::class)->clear();
        $exitCode = Artisan::call('treasury:reconcile', ['--tenant' => $this->tenant->id]);

        $this->assertSame(0, $exitCode, Artisan::output());
        $repository->refresh();
        $this->assertSame('-119.000', $repository->balance);
        $this->assertSame(1, $repository->next_movement_ordinal);
        $this->assertNull($repository->frozen_at);
        $this->assertNull($repository->frozen_reason);

        $movement = RepositoryMovement::query()
            ->where('payment_repository_id', $repository->id)
            ->firstOrFail();
        $this->assertSame($entry->id, $movement->journal_entry_id);
        $this->assertSame('-119.000', $movement->balance_after);
        $this->assertSame(1, $movement->ordinal);
        $this->assertSame(JournalEntryStatus::Posted, $entry->fresh()?->status);
        $this->assertFalse(AuditEvent::query()
            ->whereIn('event_type', [
                'treasury.reconcile.drift',
                'treasury.reconcile.portfolio_drift',
            ])
            ->exists());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{0: Document, 1: JournalEntry}
     */
    private function postVatExpense(array $overrides = []): array
    {
        return $this->postExpense(array_merge([
            'total' => '119.000',
            'vat_amount' => '19.000',
            'vat_rate' => '19.00',
            'vat_deductible_percent' => '100.00',
            'is_paid' => false,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{0: Document, 1: JournalEntry}
     */
    private function postExpense(array $overrides): array
    {
        $expense = $this->service->create(array_merge([
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'document_date' => '2026-07-13',
            'vendor_name' => 'VAT Vendor',
        ], $overrides), $this->user);

        $posted = $this->service->post($expense, $this->user);
        $entry = JournalEntry::query()
            ->where('source_type', 'expense')
            ->where('source_id', $expense->id)
            ->firstOrFail();

        return [$posted, $entry];
    }

    private function cashRepository(): PaymentRepository
    {
        return PaymentRepository::factory()->for($this->company)->create([
            'tenant_id' => $this->tenant->id,
            'type' => RepositoryType::CashRegister,
            'gl_account_id' => $this->account(SystemAccountPurpose::Cash)->id,
        ]);
    }

    private function account(SystemAccountPurpose $purpose): Account
    {
        return Account::findByPurposeOrFail($this->company->id, $purpose);
    }

    /**
     * @return array{account_id: string, partner_id: string|null, debit: string, credit: string, description: string|null, line_order: int}
     */
    private function expectedLine(
        SystemAccountPurpose $purpose,
        ?string $partnerId,
        string $debit,
        string $credit,
        ?string $description,
        int $lineOrder,
    ): array {
        return [
            'account_id' => $this->account($purpose)->id,
            'partner_id' => $partnerId,
            'debit' => $debit,
            'credit' => $credit,
            'description' => $description,
            'line_order' => $lineOrder,
        ];
    }

    /**
     * @return list<array{account_id: string, partner_id: string|null, debit: string, credit: string, description: string|null, line_order: int}>
     */
    private function linePayloads(JournalEntry $entry): array
    {
        return array_values($entry->lines()
            ->orderBy('line_order')
            ->get()
            ->map(static fn (JournalLine $line): array => [
                'account_id' => $line->account_id,
                'partner_id' => $line->partner_id,
                'debit' => $line->debit,
                'credit' => $line->credit,
                'description' => $line->description,
                'line_order' => $line->line_order,
            ])
            ->values()
            ->all());
    }
}
