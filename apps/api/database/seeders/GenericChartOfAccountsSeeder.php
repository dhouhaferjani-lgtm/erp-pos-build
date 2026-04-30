<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Generic International Chart of Accounts Seeder.
 *
 * A minimal chart of accounts for countries that do not have a dedicated seeder.
 * Uses simple numeric codes with English names and maps all SystemAccountPurpose values.
 */
class GenericChartOfAccountsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @param  string  $companyId  The company to seed accounts for
     * @param  string|null  $tenantId  The tenant (for backward compatibility)
     */
    public function run(string $companyId, ?string $tenantId = null): void
    {
        $now = now();
        $accounts = $this->getAccountsDefinition();

        // Create parent accounts map for linking
        $accountIdMap = [];

        // First pass: Create all accounts without parent links
        foreach ($accounts as $account) {
            $id = Str::uuid()->toString();
            $accountIdMap[$account['code']] = $id;

            DB::table('accounts')->insert([
                'id' => $id,
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'parent_id' => null, // Will be updated in second pass
                'code' => $account['code'],
                'name' => $account['name'],
                'type' => $account['type'],
                'system_purpose' => $account['system_purpose'] ?? null,
                'is_active' => true,
                'is_system' => $account['is_system'] ?? false,
                'balance' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Second pass: Update parent relationships
        foreach ($accounts as $account) {
            if ($account['parent_code'] !== null && isset($accountIdMap[$account['parent_code']])) {
                DB::table('accounts')
                    ->where('id', $accountIdMap[$account['code']])
                    ->update(['parent_id' => $accountIdMap[$account['parent_code']]]);
            }
        }

    }

    /**
     * Generic international chart of accounts definitions with system purposes.
     *
     * @return list<array{code: string, name: string, type: string, parent_code: string|null, system_purpose?: string, is_system?: bool}>
     */
    private function getAccountsDefinition(): array
    {
        return [
            // Class 1: Equity
            ['code' => '1000', 'name' => 'Equity', 'type' => 'equity', 'parent_code' => null, 'is_system' => true],
            ['code' => '1100', 'name' => 'Retained Earnings', 'type' => 'equity', 'parent_code' => '1000',
                'system_purpose' => SystemAccountPurpose::RetainedEarnings->value, 'is_system' => true],
            ['code' => '1190', 'name' => 'Opening Balance Equity', 'type' => 'equity', 'parent_code' => '1000',
                'system_purpose' => SystemAccountPurpose::OpeningBalanceEquity->value, 'is_system' => true],

            // Class 2: Fixed Assets
            ['code' => '2000', 'name' => 'Fixed Assets', 'type' => 'asset', 'parent_code' => null, 'is_system' => true],
            ['code' => '2100', 'name' => 'Tangible Assets', 'type' => 'asset', 'parent_code' => '2000'],
            ['code' => '2800', 'name' => 'Accumulated Depreciation', 'type' => 'asset', 'parent_code' => '2000'],

            // Class 3: Inventory
            ['code' => '3000', 'name' => 'Inventory', 'type' => 'asset', 'parent_code' => null, 'is_system' => true],
            ['code' => '3700', 'name' => 'Goods for Resale', 'type' => 'asset', 'parent_code' => '3000',
                'system_purpose' => SystemAccountPurpose::Inventory->value, 'is_system' => true],

            // Class 4: Third Parties
            ['code' => '4000', 'name' => 'Third Parties', 'type' => 'asset', 'parent_code' => null, 'is_system' => true],
            ['code' => '4010', 'name' => 'Supplier Payable', 'type' => 'liability', 'parent_code' => '4000',
                'system_purpose' => SystemAccountPurpose::SupplierPayable->value, 'is_system' => true],
            ['code' => '4090', 'name' => 'Advance to Suppliers', 'type' => 'asset', 'parent_code' => '4000',
                'system_purpose' => SystemAccountPurpose::SupplierAdvance->value, 'is_system' => true],
            ['code' => '4100', 'name' => 'Customer Receivable', 'type' => 'asset', 'parent_code' => '4000',
                'system_purpose' => SystemAccountPurpose::CustomerReceivable->value, 'is_system' => true],
            ['code' => '4180', 'name' => 'Uninvoiced Revenue', 'type' => 'asset', 'parent_code' => '4000',
                'system_purpose' => SystemAccountPurpose::UninvoicedRevenue->value, 'is_system' => true],
            ['code' => '4190', 'name' => 'Customer Advance', 'type' => 'liability', 'parent_code' => '4000',
                'system_purpose' => SystemAccountPurpose::CustomerAdvance->value, 'is_system' => true],
            ['code' => '4400', 'name' => 'Tax Accounts', 'type' => 'liability', 'parent_code' => '4000'],
            ['code' => '4456', 'name' => 'VAT Deductible (Input)', 'type' => 'asset', 'parent_code' => '4400',
                'system_purpose' => SystemAccountPurpose::VatDeductible->value, 'is_system' => true],
            ['code' => '4457', 'name' => 'VAT Collected (Output)', 'type' => 'liability', 'parent_code' => '4400',
                'system_purpose' => SystemAccountPurpose::VatCollected->value, 'is_system' => true],

            // Class 5: Cash & Bank
            ['code' => '5000', 'name' => 'Cash & Bank', 'type' => 'asset', 'parent_code' => null, 'is_system' => true],
            ['code' => '5100', 'name' => 'Bank Accounts', 'type' => 'asset', 'parent_code' => '5000',
                'system_purpose' => SystemAccountPurpose::Bank->value, 'is_system' => true],
            ['code' => '5300', 'name' => 'Cash', 'type' => 'asset', 'parent_code' => '5000',
                'system_purpose' => SystemAccountPurpose::Cash->value, 'is_system' => true],

            // Class 6: Expenses
            ['code' => '6000', 'name' => 'Expenses', 'type' => 'expense', 'parent_code' => null, 'is_system' => true],
            ['code' => '6030', 'name' => 'Cost of Goods Sold', 'type' => 'expense', 'parent_code' => '6000',
                'system_purpose' => SystemAccountPurpose::CostOfGoodsSold->value, 'is_system' => true],
            ['code' => '6070', 'name' => 'Purchase Expenses', 'type' => 'expense', 'parent_code' => '6000',
                'system_purpose' => SystemAccountPurpose::PurchaseExpenses->value, 'is_system' => true],
            ['code' => '6130', 'name' => 'Utilities', 'type' => 'expense', 'parent_code' => '6000',
                'system_purpose' => SystemAccountPurpose::UtilitiesExpense->value, 'is_system' => true],
            ['code' => '6170', 'name' => 'Office Supplies', 'type' => 'expense', 'parent_code' => '6000',
                'system_purpose' => SystemAccountPurpose::OfficeExpense->value, 'is_system' => true],
            ['code' => '6250', 'name' => 'Travel', 'type' => 'expense', 'parent_code' => '6000',
                'system_purpose' => SystemAccountPurpose::TravelExpense->value, 'is_system' => true],
            ['code' => '6256', 'name' => 'Meals & Entertainment', 'type' => 'expense', 'parent_code' => '6000',
                'system_purpose' => SystemAccountPurpose::MealsExpense->value, 'is_system' => true],
            ['code' => '6280', 'name' => 'General Expenses', 'type' => 'expense', 'parent_code' => '6000',
                'system_purpose' => SystemAccountPurpose::GeneralExpense->value, 'is_system' => true],
            ['code' => '6400', 'name' => 'Salaries & Wages', 'type' => 'expense', 'parent_code' => '6000'],
            ['code' => '6580', 'name' => 'Payment Tolerance Expense', 'type' => 'expense', 'parent_code' => '6000',
                'system_purpose' => SystemAccountPurpose::PaymentToleranceExpense->value, 'is_system' => true],
            ['code' => '6660', 'name' => 'Realized FX Loss', 'type' => 'expense', 'parent_code' => '6000',
                'system_purpose' => SystemAccountPurpose::RealizedFxLoss->value, 'is_system' => true],

            // Class 7: Revenue
            ['code' => '7000', 'name' => 'Revenue', 'type' => 'revenue', 'parent_code' => null, 'is_system' => true],
            ['code' => '7060', 'name' => 'Service Revenue', 'type' => 'revenue', 'parent_code' => '7000',
                'system_purpose' => SystemAccountPurpose::ServiceRevenue->value, 'is_system' => true],
            ['code' => '7070', 'name' => 'Product Sales Revenue', 'type' => 'revenue', 'parent_code' => '7000',
                'system_purpose' => SystemAccountPurpose::ProductRevenue->value, 'is_system' => true],
            ['code' => '7090', 'name' => 'Sales Returns', 'type' => 'expense', 'parent_code' => '7000',
                'system_purpose' => SystemAccountPurpose::SalesReturn->value, 'is_system' => true],
            ['code' => '7091', 'name' => 'Sales Discounts', 'type' => 'expense', 'parent_code' => '7000',
                'system_purpose' => SystemAccountPurpose::SalesDiscount->value, 'is_system' => true],
            ['code' => '7580', 'name' => 'Payment Tolerance Income', 'type' => 'revenue', 'parent_code' => '7000',
                'system_purpose' => SystemAccountPurpose::PaymentToleranceIncome->value, 'is_system' => true],
            ['code' => '7660', 'name' => 'Realized FX Gain', 'type' => 'revenue', 'parent_code' => '7000',
                'system_purpose' => SystemAccountPurpose::RealizedFxGain->value, 'is_system' => true],

            // Voucher accounting — EU Directive 2016/1065 MPV layer (non-taxable; Phase 1)
            ['code' => '7092', 'name' => 'Sales Returns Clearing (Voucher)', 'type' => 'expense', 'parent_code' => '7000',
                'system_purpose' => SystemAccountPurpose::SalesReturnsClearing->value, 'is_system' => true],
            ['code' => '4197', 'name' => 'Voucher Liability', 'type' => 'liability', 'parent_code' => '4000',
                'system_purpose' => SystemAccountPurpose::VoucherLiability->value, 'is_system' => true],
            ['code' => '6238', 'name' => 'Marketing Goodwill Expense', 'type' => 'expense', 'parent_code' => '6000',
                'system_purpose' => SystemAccountPurpose::MarketingGoodwillExpense->value, 'is_system' => true],
            ['code' => '7592', 'name' => 'Voucher Breakage Income', 'type' => 'revenue', 'parent_code' => '7000',
                'system_purpose' => SystemAccountPurpose::VoucherBreakageIncome->value, 'is_system' => true],
            ['code' => '6588', 'name' => 'Rounding Loss Expense (Voucher)', 'type' => 'expense', 'parent_code' => '6000',
                'system_purpose' => SystemAccountPurpose::RoundingLossExpense->value, 'is_system' => true],
            ['code' => '5810', 'name' => 'POS Tender Clearing (Voucher Redemption)', 'type' => 'asset', 'parent_code' => null,
                'system_purpose' => SystemAccountPurpose::PosTenderClearing->value, 'is_system' => true],
        ];
    }
}
