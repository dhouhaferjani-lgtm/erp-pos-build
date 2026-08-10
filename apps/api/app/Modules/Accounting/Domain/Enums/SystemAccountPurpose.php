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

    // Purchase Price Variance
    case PurchasePriceVarianceExpense = 'purchase_price_variance_expense'; // 6585
    case PurchasePriceVarianceIncome = 'purchase_price_variance_income';   // 7585

    // Sales Returns (for credit notes)
    case SalesReturn = 'sales_return';                            // 709

    // v3-refund-chain-integration spec §5.3 — genuine-loss write-off for an
    // `invalid_refund`-class compensation (quantity cap exceeded, approval
    // evidence unresolved). Distinct from SalesReturn: SalesReturn is the
    // reversal shape for a VALID refund that merely failed to book on time
    // (`valid_unbooked`); RefundWriteOff is booked for a refund that should
    // never have been honored as real.
    case RefundWriteOff = 'refund_write_off';

    // Extensibility: FX (Phase 2)
    case RealizedFxGain = 'realized_fx_gain';                     // 766
    case RealizedFxLoss = 'realized_fx_loss';                     // 666

    // Extensibility: Cash Discounts (Phase 2)
    case SalesDiscount = 'sales_discount';                        // 709 (or separate)

    // Voucher Accounting (Phase 1 — non-taxable MPV layer per EU Directive 2016/1065)
    case SalesReturnsClearing = 'sales_returns_clearing';         // Contra-revenue clearing for refund/exchange-surplus issuance
    case VoucherLiability = 'voucher_liability';                  // Current liability: outstanding unredeemed voucher balance
    case MarketingGoodwillExpense = 'marketing_goodwill_expense'; // Operating expense for goodwill voucher issuance
    case VoucherBreakageIncome = 'voucher_breakage_income';       // Revenue recognised when vouchers expire unredeemed
    case RoundingLossExpense = 'rounding_loss_expense';           // Sub-minor residual write-off on voucher rounding adjustments
    case PosTenderClearing = 'pos_tender_clearing';               // Transient suspense: credit leg when voucher redeems against a POS sale (Task 14)

    // Procurement (GR-IR / Domestic P2P)
    case GoodsReceivedNotInvoiced = 'goods_received_not_invoiced'; // 408 — accrued liability until supplier invoice matched
    // Timbre fiscal borne by the company as a fiscal charge. Named for its
    // original caller (purchase-side GR-IR clearing), but the account it
    // resolves to (PCG "droits d'enregistrement et de timbre", 63xx) is a
    // general stamp-duty EXPENSE, not a purchase-specific one — Q1
    // (2026-08-07 ruling) reuses this SAME purpose for a credit note's own
    // stamp duty, which the company also bears as a charge (never passed
    // through to the customer). See AccountingService::createCreditNoteGLEntries().
    case PurchaseStampDuty = 'purchase_stamp_duty';
    case SalesStampDutyPayable = 'sales_stamp_duty_payable';       // 4375 — droit de timbre collected on sales, remittable to the State (liability)

    // Sales invoice/credit-note tax-rounding difference (W-6 D1a).
    // A sales document debits AR with the HEADER total and credits the LINES'
    // revenue + a RECOMPUTED per-line VAT. Because per-line truncation sums to no
    // more than the per-bucket truncation the header used, the header can exceed
    // the GL credits by up to one unit of the last place per line. On the Tunisian
    // chart that residual rides in `SalesStampDutyPayable` alongside the genuine
    // timbre; charts with no timbre concept (FR/Generic) need a home of their own
    // or the entry cannot balance. PCG 658/758 "charges/produits divers de gestion
    // courante" is the conventional pair for such écarts.
    case SalesRoundingDifferenceIncome = 'sales_rounding_difference_income';   // 7581 — invoice residual (credit)
    case SalesRoundingDifferenceExpense = 'sales_rounding_difference_expense'; // 6581 — credit-note residual (debit)

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
            self::PurchasePriceVarianceExpense => 'Purchase Price Variance Expense',
            self::PurchasePriceVarianceIncome => 'Purchase Price Variance Income',
            self::SalesReturn => 'Sales Return',
            self::RefundWriteOff => 'Refund Write-Off',
            self::RealizedFxGain => 'Realized FX Gain',
            self::RealizedFxLoss => 'Realized FX Loss',
            self::SalesDiscount => 'Sales Discount',
            self::SalesReturnsClearing => 'Sales Returns Clearing',
            self::VoucherLiability => 'Voucher Liability',
            self::MarketingGoodwillExpense => 'Marketing Goodwill Expense',
            self::VoucherBreakageIncome => 'Voucher Breakage Income',
            self::RoundingLossExpense => 'Rounding Loss Expense',
            self::PosTenderClearing => 'POS Tender Clearing',
            self::GoodsReceivedNotInvoiced => 'Goods Received Not Invoiced (GR-IR)',
            self::PurchaseStampDuty => 'Purchase Stamp Duty (Timbre)',
            self::SalesStampDutyPayable => 'Sales Stamp Duty Payable (Timbre à reverser)',
            self::SalesRoundingDifferenceIncome => 'Sales Rounding Difference (Income)',
            self::SalesRoundingDifferenceExpense => 'Sales Rounding Difference (Expense)',
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
            // R2 E-1 (register H-5): both were absent from this list, so
            // ChartOfAccountsService::validateCompanyAccounts() passed the French
            // chart that could post NEITHER — France booked zero COGS silently
            // (PostCOGSOnInvoice swallows the miss) and the expense-document lane
            // had no fallback account. All three country charts now seed them.
            self::CostOfGoodsSold,
            self::GeneralExpense,
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
            self::UninvoicedRevenue, self::PosTenderClearing => AccountType::Asset,

            self::SupplierPayable, self::CustomerAdvance,
            self::VatCollected, self::VoucherLiability,
            self::GoodsReceivedNotInvoiced,
            self::SalesStampDutyPayable => AccountType::Liability,

            self::ProductRevenue, self::ServiceRevenue,
            self::PaymentToleranceIncome, self::PurchasePriceVarianceIncome, self::RealizedFxGain,
            self::VoucherBreakageIncome, self::SalesRoundingDifferenceIncome => AccountType::Revenue,

            self::CostOfGoodsSold, self::PurchaseExpenses, self::OfficeExpense,
            self::TravelExpense, self::MealsExpense, self::UtilitiesExpense, self::GeneralExpense,
            self::PaymentToleranceExpense, self::PurchasePriceVarianceExpense, self::SalesReturn, self::RefundWriteOff, self::RealizedFxLoss,
            self::SalesDiscount, self::SalesReturnsClearing,
            self::MarketingGoodwillExpense, self::RoundingLossExpense,
            self::SalesRoundingDifferenceExpense,
            self::PurchaseStampDuty => AccountType::Expense,

            self::RetainedEarnings, self::OpeningBalanceEquity => AccountType::Equity,
        };
    }
}
