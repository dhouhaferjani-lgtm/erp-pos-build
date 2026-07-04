<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\JournalLine;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Taxation\Domain\Enums\PartnerTaxStatus;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SupplierInvoicePpvGlTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Partner $supplier;

    private Account $grirAccount;

    private Account $vatAccount;

    private Account $payableAccount;

    private Account $inventoryAccount;

    private Account $ppvExpenseAccount;

    private Account $ppvIncomeAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create([
            'name' => 'PPV GL Tenant',
            'slug' => 'ppv-gl-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'PPV GL Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
        ]);

        app(ChartOfAccountsService::class)->seedForCompany($this->company);

        $this->grirAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::GoodsReceivedNotInvoiced);
        $this->vatAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::VatDeductible);
        $this->payableAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::SupplierPayable);
        $this->inventoryAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::Inventory);
        $this->ppvExpenseAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::PurchasePriceVarianceExpense);
        $this->ppvIncomeAccount = Account::findByPurposeOrFail($this->company->id, SystemAccountPurpose::PurchasePriceVarianceIncome);

        $this->assertSame(AccountType::Expense, $this->ppvExpenseAccount->type);
        $this->assertSame(AccountType::Revenue, $this->ppvIncomeAccount->type);

        $this->supplier = Partner::create([
            'tenant_id' => $tenant->id,
            'company_id' => $this->company->id,
            'name' => 'PPV GL Supplier',
            'type' => PartnerType::Supplier,
            'tax_status' => PartnerTaxStatus::REGISTERED,
        ]);
    }

    #[Test]
    public function case_a_matching_bill_posts_no_ppv_and_inventory_stays_equal_to_wac(): void
    {
        $entry = $this->postCase(billedHt: '520.000', vat: '98.800', total: '618.800');

        $this->assertLeg($entry, $this->grirAccount, debit: '520.000', credit: '0.000');
        $this->assertLeg($entry, $this->vatAccount, debit: '98.800', credit: '0.000');
        $this->assertLeg($entry, $this->payableAccount, debit: '0.000', credit: '618.800');
        $this->assertNull($this->legOn($entry, $this->ppvExpenseAccount));
        $this->assertNull($this->legOn($entry, $this->ppvIncomeAccount));
        $this->assertNull($this->legOn($entry, $this->inventoryAccount));
        $this->assertSame('0.000', $this->netAccount($this->grirAccount));
        $this->assertWacEqualsInventoryGl();
    }

    #[Test]
    public function case_b_favorable_bill_routes_price_delta_to_ppv_income(): void
    {
        $entry = $this->postCase(billedHt: '500.000', vat: '95.000', total: '595.000');

        $this->assertLeg($entry, $this->grirAccount, debit: '520.000', credit: '0.000');
        $this->assertLeg($entry, $this->vatAccount, debit: '95.000', credit: '0.000');
        $this->assertLeg($entry, $this->ppvIncomeAccount, debit: '0.000', credit: '20.000');
        $this->assertLeg($entry, $this->payableAccount, debit: '0.000', credit: '595.000');
        $this->assertNull($this->legOn($entry, $this->inventoryAccount));
        $this->assertSame('0.000', $this->netAccount($this->grirAccount));
        $this->assertWacEqualsInventoryGl();
    }

    #[Test]
    public function case_c_unfavorable_bill_routes_price_delta_to_ppv_expense(): void
    {
        $entry = $this->postCase(billedHt: '540.000', vat: '102.600', total: '642.600');

        $this->assertLeg($entry, $this->grirAccount, debit: '520.000', credit: '0.000');
        $this->assertLeg($entry, $this->vatAccount, debit: '102.600', credit: '0.000');
        $this->assertLeg($entry, $this->ppvExpenseAccount, debit: '20.000', credit: '0.000');
        $this->assertLeg($entry, $this->payableAccount, debit: '0.000', credit: '642.600');
        $this->assertNull($this->legOn($entry, $this->inventoryAccount));
        $this->assertSame('0.000', $this->netAccount($this->grirAccount));
        $this->assertWacEqualsInventoryGl();
    }

    #[Test]
    public function non_recoverable_vat_still_capitalizes_to_inventory_while_price_delta_routes_to_ppv(): void
    {
        $entry = $this->postCase(billedHt: '500.000', vat: '95.000', total: '600.000', nonRecoverableVat: '5.000');

        $this->assertLeg($entry, $this->ppvIncomeAccount, debit: '0.000', credit: '20.000');
        $this->assertLeg($entry, $this->inventoryAccount, debit: '5.000', credit: '0.000');
        $this->assertLeg($entry, $this->payableAccount, debit: '0.000', credit: '600.000');
    }

    private function postCase(string $billedHt, string $vat, string $total, string $nonRecoverableVat = '0.000'): JournalEntry
    {
        $invoice = Document::create([
            'tenant_id' => $this->company->tenant_id,
            'company_id' => $this->company->id,
            'partner_id' => $this->supplier->id,
            'type' => DocumentType::SupplierInvoice,
            'fiscal_category' => FiscalCategory::NonFiscal,
            'fiscal_status' => FiscalStatus::Draft,
            'status' => DocumentStatus::Draft,
            'document_number' => 'SI-PPV-'.Str::upper(Str::random(6)),
            'document_date' => now(),
            'currency' => 'TND',
            'subtotal' => $billedHt,
            'line_tax_amount' => $vat,
            'stamp_duty_amount' => '0.000',
            'tax_amount' => bcadd($vat, $nonRecoverableVat, 3),
            'total' => $total,
        ]);

        app(GeneralLedgerService::class)->createGoodsReceiptGrIrEntry(
            $this->company->id,
            Str::uuid()->toString(),
            '100.0000',
            '5.200',
            'TND',
        );

        return DB::transaction(fn (): JournalEntry => app(GeneralLedgerService::class)
            ->createSupplierInvoiceGrIrClearingEntry(
                $invoice,
                accruedHt: '520.000',
                billedHt: $billedHt,
                recoverableVat: $vat,
                nonRecoverableVat: $nonRecoverableVat,
                timbre: '0.000',
            ));
    }

    private function assertLeg(JournalEntry $entry, Account $account, string $debit, string $credit): void
    {
        $leg = $this->legOn($entry, $account);
        $this->assertNotNull($leg, "Missing leg for account {$account->code}");
        $this->assertSame($debit, $leg->debit);
        $this->assertSame($credit, $leg->credit);
    }

    private function legOn(JournalEntry $entry, Account $account): ?JournalLine
    {
        return $entry->lines->firstWhere('account_id', $account->id);
    }

    private function netAccount(Account $account): string
    {
        $net = '0.000';
        $lines = JournalLine::query()
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entries.company_id', $this->company->id)
            ->where('journal_lines.account_id', $account->id)
            ->select('journal_lines.debit', 'journal_lines.credit')
            ->get();

        foreach ($lines as $line) {
            $net = $account->type->increasesWithDebit()
                ? bcadd($net, bcsub($line->debit, $line->credit, 3), 3)
                : bcadd($net, bcsub($line->credit, $line->debit, 3), 3);
        }

        return $net;
    }

    private function assertWacEqualsInventoryGl(): void
    {
        $wacInventoryValue = bcmul('100.0000', '5.200', 3);

        $this->assertSame($wacInventoryValue, $this->netAccount($this->inventoryAccount));
    }
}
