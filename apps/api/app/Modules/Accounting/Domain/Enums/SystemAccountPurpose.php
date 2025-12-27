<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain\Enums;

/**
 * System account purposes for country-agnostic account lookups.
 *
 * Instead of hardcoding account codes (e.g., '411' for customers in Tunisia),
 * the system uses these purposes to find the correct account regardless of
 * the country's chart of accounts structure.
 */
enum SystemAccountPurpose: string
{
    // Asset Accounts
    case Bank = 'bank';
    case Cash = 'cash';
    case CustomerReceivable = 'customer_receivable';
    case SupplierAdvance = 'supplier_advance';
    case Inventory = 'inventory';
    case UninvoicedRevenue = 'uninvoiced_revenue';  // 418 - Clients, produits non encore facturés

    // Liability Accounts
    case SupplierPayable = 'supplier_payable';
    case CustomerAdvance = 'customer_advance';
    case VatCollected = 'vat_collected';
    case VatDeductible = 'vat_deductible';

    // Revenue Accounts
    case ProductRevenue = 'product_revenue';
    case ServiceRevenue = 'service_revenue';

    // Expense Accounts
    case CostOfGoodsSold = 'cost_of_goods_sold';
    case PurchaseExpenses = 'purchase_expenses';
    case OfficeExpense = 'office_expense';
    case TravelExpense = 'travel_expense';
    case MealsExpense = 'meals_expense';
    case UtilitiesExpense = 'utilities_expense';
    case GeneralExpense = 'general_expense';

    // Equity Accounts
    case RetainedEarnings = 'retained_earnings';
    case OpeningBalanceEquity = 'opening_balance_equity';

    // Payment Tolerance
    case PaymentToleranceExpense = 'payment_tolerance_expense';   // 658
    case PaymentToleranceIncome = 'payment_tolerance_income';     // 758

    // Sales Returns (for credit notes)
    case SalesReturn = 'sales_return';                            // 709

    // Extensibility: FX (Phase 2)
    case RealizedFxGain = 'realized_fx_gain';                     // 766
    case RealizedFxLoss = 'realized_fx_loss';                     // 666

    // Extensibility: Cash Discounts (Phase 2)
    case SalesDiscount = 'sales_discount';                        // 709 (or separate)

    /**
     * Get human-readable label for display.
     */
    public function label(): string
    {
        return match ($this) {
            self::Bank => 'Bank Account',
            self::Cash => 'Cash Account',
            self::CustomerReceivable => 'Customer Receivable (AR)',
            self::SupplierAdvance => 'Advance to Supplier',
            self::Inventory => 'Inventory',
            self::UninvoicedRevenue => 'Uninvoiced Revenue (Accrued)',
            self::SupplierPayable => 'Supplier Payable (AP)',
            self::CustomerAdvance => 'Customer Advance/Prepayment',
            self::VatCollected => 'VAT Collected (Output)',
            self::VatDeductible => 'VAT Deductible (Input)',
            self::ProductRevenue => 'Product Sales Revenue',
            self::ServiceRevenue => 'Service Revenue',
            self::CostOfGoodsSold => 'Cost of Goods Sold',
            self::PurchaseExpenses => 'Purchase Expenses',
            self::OfficeExpense => 'Office Expense',
            self::TravelExpense => 'Travel Expense',
            self::MealsExpense => 'Meals & Entertainment',
            self::UtilitiesExpense => 'Utilities Expense',
            self::GeneralExpense => 'General Expense',
            self::RetainedEarnings => 'Retained Earnings',
            self::OpeningBalanceEquity => 'Opening Balance Equity',
            self::PaymentToleranceExpense => 'Payment Tolerance Expense',
            self::PaymentToleranceIncome => 'Payment Tolerance Income',
            self::SalesReturn => 'Sales Return',
            self::RealizedFxGain => 'Realized FX Gain',
            self::RealizedFxLoss => 'Realized FX Loss',
            self::SalesDiscount => 'Sales Discount',
        };
    }

    /**
     * Get all purposes that must be assigned for GL operations to work.
     *
     * @return list<SystemAccountPurpose>
     */
    public static function requiredPurposes(): array
    {
        return [
            self::CustomerReceivable,
            self::CustomerAdvance,
            self::SupplierPayable,
            self::SupplierAdvance,
            self::VatCollected,
            self::VatDeductible,
            self::ProductRevenue,
            self::ServiceRevenue,
            self::Bank,
            self::Cash,
            self::OpeningBalanceEquity,
        ];
    }

    /**
     * Get the expected account type for this purpose.
     */
    public function expectedAccountType(): AccountType
    {
        return match ($this) {
            self::Bank, self::Cash, self::CustomerReceivable,
            self::SupplierAdvance, self::Inventory, self::VatDeductible,
            self::UninvoicedRevenue => AccountType::Asset,

            self::SupplierPayable, self::CustomerAdvance, self::VatCollected => AccountType::Liability,

            self::ProductRevenue, self::ServiceRevenue,
            self::PaymentToleranceIncome, self::RealizedFxGain => AccountType::Revenue,

            self::CostOfGoodsSold, self::PurchaseExpenses, self::OfficeExpense,
            self::TravelExpense, self::MealsExpense, self::UtilitiesExpense, self::GeneralExpense,
            self::PaymentToleranceExpense, self::SalesReturn, self::RealizedFxLoss,
            self::SalesDiscount => AccountType::Expense,

            self::RetainedEarnings, self::OpeningBalanceEquity => AccountType::Equity,
        };
    }
}
