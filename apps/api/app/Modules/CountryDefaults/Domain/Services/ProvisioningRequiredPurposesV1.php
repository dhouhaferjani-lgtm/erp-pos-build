<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Domain\Services;

use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use LogicException;

final class ProvisioningRequiredPurposesV1
{
    private const REQUIRED = 'REQUIRED';

    private const SCOPE_REQUIRED = 'SCOPE_REQUIRED';

    private const CONDITIONAL = 'CONDITIONAL';

    private const SOFT = 'SOFT';

    private const DOMAIN_PRECHECK_4XX = 'DOMAIN_PRECHECK_4XX';

    /** @return list<string> */
    public static function allowedGateKinds(): array
    {
        return ['MODULE_GATE', self::DOMAIN_PRECHECK_4XX];
    }

    /**
     * @return list<array{purpose: SystemAccountPurpose, call_site: string, classification: string, gate_kind: ?string, evidence_citation: string}>
     */
    public static function entries(): array
    {
        return [
            self::entry(SystemAccountPurpose::Bank, 'GeneralLedgerService paid-expense repository branch', self::REQUIRED, null, 'GeneralLedgerService.php:3950-3951'),
            self::entry(SystemAccountPurpose::Cash, 'GeneralLedgerService paid-expense repository branch', self::REQUIRED, null, 'GeneralLedgerService.php:3950-3951'),
            self::entry(SystemAccountPurpose::CustomerReceivable, 'AccountingService invoice posting', self::REQUIRED, null, 'AccountingService.php:409'),
            self::entry(SystemAccountPurpose::Inventory, 'GeneralLedgerService inventory and GR-IR posting', self::REQUIRED, null, 'GeneralLedgerService.php:1721,1829,1986'),
            self::entry(SystemAccountPurpose::SupplierPayable, 'GeneralLedgerService supplier posting', self::REQUIRED, null, 'GeneralLedgerService.php:1989'),
            self::entry(SystemAccountPurpose::VatCollected, 'AccountingService invoice posting', self::REQUIRED, null, 'AccountingService.php:427'),
            self::entry(SystemAccountPurpose::VatDeductible, 'GeneralLedgerService GR-IR clearing', self::REQUIRED, null, 'GeneralLedgerService.php:1984'),
            self::entry(SystemAccountPurpose::ProductRevenue, 'AccountingService invoice posting', self::REQUIRED, null, 'AccountingService.php:414'),
            self::entry(SystemAccountPurpose::ServiceRevenue, 'AccountingService eager invoice resolution', self::REQUIRED, null, 'AccountingService.php:422,574'),
            self::entry(SystemAccountPurpose::CostOfGoodsSold, 'PostCOGSOnInvoice listener to GeneralLedgerService', self::REQUIRED, null, 'PostCOGSOnInvoice.php:43-80 -> GeneralLedgerService.php:1720'),
            self::entry(SystemAccountPurpose::GeneralExpense, 'GeneralLedgerService category-less expense fallback', self::REQUIRED, null, 'GeneralLedgerService.php:3924,3938'),
            self::entry(SystemAccountPurpose::OpeningBalanceEquity, 'AccountingOpeningService opening balance post', self::REQUIRED, null, 'AccountingOpeningService.php:314'),
            self::entry(SystemAccountPurpose::PurchasePriceVarianceExpense, 'GeneralLedgerService eager GR-IR clearing', self::REQUIRED, null, 'GeneralLedgerService.php:1987'),
            self::entry(SystemAccountPurpose::PurchasePriceVarianceIncome, 'GeneralLedgerService eager GR-IR clearing', self::REQUIRED, null, 'GeneralLedgerService.php:1988'),
            self::entry(SystemAccountPurpose::GoodsReceivedNotInvoiced, 'GeneralLedgerService GR-IR posting and clearing', self::REQUIRED, null, 'GeneralLedgerService.php:1830,1983'),
            self::entry(SystemAccountPurpose::PurchaseStampDuty, 'GeneralLedgerService eager GR-IR supplier-invoice clearing', self::REQUIRED, null, 'GeneralLedgerService.php:1985'),
            self::entry(SystemAccountPurpose::SalesDiscount, 'GeneralLedgerService POS account-charge discount path', self::REQUIRED, null, 'GeneralLedgerService.php:3807-3827; TreasuryAccountChargeBridge.php:239-249'),
            self::entry(SystemAccountPurpose::CustomerAdvance, 'PaymentAllocationService ordinary order/excess allocation', self::REQUIRED, null, 'PaymentAllocationService.php:307-365 -> GeneralLedgerService.php:396-417'),
            self::entry(SystemAccountPurpose::SupplierAdvance, 'VendorRefundService ordinary prepayment refund', self::REQUIRED, null, 'VendorRefundService.php:153-175 -> GeneralLedgerService.php:496-517'),
            self::entry(SystemAccountPurpose::SalesReturnsClearing, 'GeneralLedgerService voucher issuance/redemption', self::REQUIRED, null, 'Voucher routes.php:20-42; GeneralLedgerService.php:2563-2566,2619-2648'),
            self::entry(SystemAccountPurpose::VoucherLiability, 'GeneralLedgerService voucher issuance/redemption', self::REQUIRED, null, 'VoucherIssuanceService.php:295-356; VoucherRedemptionService.php:184-204,226-253'),
            self::entry(SystemAccountPurpose::MarketingGoodwillExpense, 'GeneralLedgerService voucher issuance', self::REQUIRED, null, 'VoucherIssuanceService.php:295-356; GeneralLedgerService.php:2563-2566'),
            self::entry(SystemAccountPurpose::PosTenderClearing, 'GeneralLedgerService voucher redemption', self::REQUIRED, null, 'VoucherRedemptionService.php:184-204,226-253; GeneralLedgerService.php:2619-2648'),
            self::entry(SystemAccountPurpose::RoundingLossExpense, 'GeneralLedgerService voucher rounding', self::REQUIRED, null, 'VoucherRedemptionService.php:226-253; GeneralLedgerService.php:2619-2648'),
            self::entry(SystemAccountPurpose::PaymentToleranceExpense, 'Treasury tolerance projection', self::REQUIRED, null, 'TreasuryReceiptBridge.php:567-583'),
            self::entry(SystemAccountPurpose::PaymentToleranceIncome, 'Treasury tolerance projection', self::REQUIRED, null, 'TreasuryReceiptBridge.php:567-583'),
            self::entry(SystemAccountPurpose::PurchaseExpenses, 'GeneralLedgerService supplier bonus-return posting', self::REQUIRED, null, 'GeneralLedgerService.php:2311-2370; CreateSupplierInvoiceRequest.php:68-72,140-148'),

            self::entry(SystemAccountPurpose::SalesStampDutyPayable, 'GeneralLedgerService credit-note stamp branch', self::SCOPE_REQUIRED, null, 'GeneralLedgerService.php:291 inside if ($hasStampDuty)'),

            self::entry(SystemAccountPurpose::SalesReturn, 'RefundCompensationService prechecked throwing GL path', self::CONDITIONAL, self::DOMAIN_PRECHECK_4XX, 'RefundCompensationService.php:184-193; bootstrap/app.php:487-496 (422)'),
            self::entry(SystemAccountPurpose::RefundWriteOff, 'RefundCompensationService prechecked throwing GL path', self::CONDITIONAL, self::DOMAIN_PRECHECK_4XX, 'RefundCompensationService.php:184-193; bootstrap/app.php:487-496 (422)'),
            self::entry(SystemAccountPurpose::SalesRoundingDifferenceIncome, 'AccountingService residual preflight', self::CONDITIONAL, self::DOMAIN_PRECHECK_4XX, 'AccountingService.php:212-233,82-96; DocumentPostingService.php:95-109 (422 before sealing)'),
            self::entry(SystemAccountPurpose::SalesRoundingDifferenceExpense, 'AccountingService residual preflight', self::CONDITIONAL, self::DOMAIN_PRECHECK_4XX, 'AccountingService.php:212-233,82-96; DocumentPostingService.php:95-109 (422 before sealing)'),

            self::entry(SystemAccountPurpose::OfficeExpense, 'NONE', self::SOFT, null, 'No registered production throwing purpose-resolution site.'),
            self::entry(SystemAccountPurpose::TravelExpense, 'NONE', self::SOFT, null, 'No registered production throwing purpose-resolution site.'),
            self::entry(SystemAccountPurpose::MealsExpense, 'NONE', self::SOFT, null, 'No registered production throwing purpose-resolution site.'),
            self::entry(SystemAccountPurpose::UtilitiesExpense, 'NONE', self::SOFT, null, 'No registered production throwing purpose-resolution site.'),
            self::entry(SystemAccountPurpose::RetainedEarnings, 'NONE', self::SOFT, null, 'No registered production throwing purpose-resolution site.'),
            self::entry(SystemAccountPurpose::RealizedFxGain, 'NONE', self::SOFT, null, 'No registered production throwing purpose-resolution site.'),
            self::entry(SystemAccountPurpose::RealizedFxLoss, 'NONE', self::SOFT, null, 'No registered production throwing purpose-resolution site.'),
            self::entry(SystemAccountPurpose::VoucherBreakageIncome, 'NONE', self::SOFT, null, 'No Expired voucher arm is wired in production.'),
            self::entry(SystemAccountPurpose::UninvoicedRevenue, 'NONE', self::SOFT, null, 'UninvoicedDeliveryNoteService has zero production callers; production-root AST scan enforced.'),
        ];
    }

    /**
     * Complete AST ratchet inventory. A line is deliberately part of the registration key: moving or
     * adding a throwing lookup requires re-reviewing its manifest evidence rather than silently passing.
     *
     * @return list<string>
     */
    public static function registeredThrowingCallSites(): array
    {
        return [
            'app/Modules/Accounting/Application/Services/AccountingOpeningService.php:314|AccountingOpeningService::postBatch|findByPurposeOrFail|OpeningBalanceEquity',
            'app/Modules/Accounting/Application/Services/AccountingService.php:412|AccountingService::createInvoiceGLEntries|findAccountByPurpose|CustomerReceivable',
            'app/Modules/Accounting/Application/Services/AccountingService.php:416|AccountingService::createInvoiceGLEntries|findAccountByPurpose|ProductRevenue',
            'app/Modules/Accounting/Application/Services/AccountingService.php:420|AccountingService::createInvoiceGLEntries|findAccountByPurpose|ServiceRevenue',
            'app/Modules/Accounting/Application/Services/AccountingService.php:424|AccountingService::createInvoiceGLEntries|findAccountByPurpose|VatCollected',
            'app/Modules/Accounting/Application/Services/AccountingService.php:564|AccountingService::createCreditNoteGLEntries|findAccountByPurpose|CustomerReceivable',
            'app/Modules/Accounting/Application/Services/AccountingService.php:568|AccountingService::createCreditNoteGLEntries|findAccountByPurpose|ProductRevenue',
            'app/Modules/Accounting/Application/Services/AccountingService.php:572|AccountingService::createCreditNoteGLEntries|findAccountByPurpose|ServiceRevenue',
            'app/Modules/Accounting/Application/Services/AccountingService.php:576|AccountingService::createCreditNoteGLEntries|findAccountByPurpose|VatCollected',
            'app/Modules/Accounting/Application/Services/ChartOfAccountsService.php:72|ChartOfAccountsService::getAccountByPurpose|findByPurposeOrFail|DYNAMIC',
            'app/Modules/Accounting/Application/Services/PartnerBalanceService.php:172|PartnerBalanceService::getControlAccountBalance|findByPurposeOrFail|DYNAMIC',
            'app/Modules/Accounting/Application/Services/PartnerBalanceService.php:206|PartnerBalanceService::reconcileSubledger|findByPurposeOrFail|DYNAMIC',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1038|GeneralLedgerService::createOutboundInstrumentCancellationEntry|getAccountByPurpose|SupplierPayable',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1146|GeneralLedgerService::createExpenseSettlementJournalEntry|getAccountByPurpose|SupplierPayable',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1239|GeneralLedgerService::createRepositoryAdjustmentJournalEntry|getAccountByPurpose|PaymentToleranceExpense',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1240|GeneralLedgerService::createRepositoryAdjustmentJournalEntry|getAccountByPurpose|PaymentToleranceIncome',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:138|GeneralLedgerService::createFromInvoice|getAccountByPurpose|CustomerReceivable',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:139|GeneralLedgerService::createFromInvoice|getAccountByPurpose|ProductRevenue',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:140|GeneralLedgerService::createFromInvoice|getAccountByPurpose|VatCollected',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1469|GeneralLedgerService::createPaymentReceivedJournalEntry|getAccountByPurpose|CustomerReceivable',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1560|GeneralLedgerService::createPaymentToleranceJournalEntry|getAccountByPurpose|CustomerReceivable',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1570|GeneralLedgerService::createPaymentToleranceJournalEntry|getAccountByPurpose|DYNAMIC',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1670|GeneralLedgerService::clearCustomerAdvanceToReceivable|getAccountByPurpose|CustomerAdvance',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1671|GeneralLedgerService::clearCustomerAdvanceToReceivable|getAccountByPurpose|CustomerReceivable',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1800|GeneralLedgerService::availableCustomerAdvance|getAccountByPurpose|CustomerAdvance',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1890|GeneralLedgerService::createCOGSEntry|getAccountByPurpose|CostOfGoodsSold',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1891|GeneralLedgerService::createCOGSEntry|getAccountByPurpose|Inventory',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1999|GeneralLedgerService::createGoodsReceiptGrIrEntry|findByPurposeOrFail|Inventory',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2000|GeneralLedgerService::createGoodsReceiptGrIrEntry|findByPurposeOrFail|GoodsReceivedNotInvoiced',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2153|GeneralLedgerService::createSupplierInvoiceGrIrClearingEntry|findByPurposeOrFail|GoodsReceivedNotInvoiced',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2154|GeneralLedgerService::createSupplierInvoiceGrIrClearingEntry|findByPurposeOrFail|VatDeductible',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2155|GeneralLedgerService::createSupplierInvoiceGrIrClearingEntry|findByPurposeOrFail|PurchaseStampDuty',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2156|GeneralLedgerService::createSupplierInvoiceGrIrClearingEntry|findByPurposeOrFail|Inventory',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2157|GeneralLedgerService::createSupplierInvoiceGrIrClearingEntry|findByPurposeOrFail|PurchasePriceVarianceExpense',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2158|GeneralLedgerService::createSupplierInvoiceGrIrClearingEntry|findByPurposeOrFail|PurchasePriceVarianceIncome',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2159|GeneralLedgerService::createSupplierInvoiceGrIrClearingEntry|findByPurposeOrFail|SupplierPayable',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:222|GeneralLedgerService::createFromCreditNote|getAccountByPurpose|CustomerReceivable',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:223|GeneralLedgerService::createFromCreditNote|getAccountByPurpose|ProductRevenue',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:224|GeneralLedgerService::createFromCreditNote|getAccountByPurpose|VatCollected',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2374|GeneralLedgerService::createSupplierCreditNoteEntry|findByPurposeOrFail|VatDeductible',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2375|GeneralLedgerService::createSupplierCreditNoteEntry|findByPurposeOrFail|Inventory',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2376|GeneralLedgerService::createSupplierCreditNoteEntry|findByPurposeOrFail|SupplierPayable',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2537|GeneralLedgerService::createSupplierCreditNoteEntryWithBonusReturn|findByPurposeOrFail|VatDeductible',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2538|GeneralLedgerService::createSupplierCreditNoteEntryWithBonusReturn|findByPurposeOrFail|Inventory',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2539|GeneralLedgerService::createSupplierCreditNoteEntryWithBonusReturn|findByPurposeOrFail|SupplierPayable',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2540|GeneralLedgerService::createSupplierCreditNoteEntryWithBonusReturn|findByPurposeOrFail|PurchaseExpenses',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2735|GeneralLedgerService::createVoucherLedgerEntry|getAccountByPurpose|DYNAMIC',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2736|GeneralLedgerService::createVoucherLedgerEntry|getAccountByPurpose|DYNAMIC',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:291|GeneralLedgerService::createFromCreditNote|getAccountByPurpose|PurchaseStampDuty',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2921|GeneralLedgerService::createInstrumentRepresentationEntry|getAccountByPurpose|CustomerReceivable',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:292|GeneralLedgerService::createFromCreditNote|getAccountByPurpose|SalesStampDutyPayable',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:3325|GeneralLedgerService::createInstrumentCancellationEntry|getAccountByPurpose|ProductRevenue',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:3391|GeneralLedgerService::b2bCancellationDebits|getAccountByPurpose|CustomerReceivable',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:3429|GeneralLedgerService::b2bCancellationDebits|getAccountByPurpose|CustomerAdvance',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:3581|GeneralLedgerService::createPOSPaymentToleranceEntry|getAccountByPurpose|PaymentToleranceExpense',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:3582|GeneralLedgerService::createPOSPaymentToleranceEntry|getAccountByPurpose|ProductRevenue',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:3660|GeneralLedgerService::createPOSPaymentEntry|getAccountByPurpose|ProductRevenue',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:3744|GeneralLedgerService::createPOSRefundReversalEntry|getAccountByPurpose|ProductRevenue',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:3849|GeneralLedgerService::createPosCashRoundingEntry|getAccountByPurpose|ProductRevenue',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:3851|GeneralLedgerService::createPosCashRoundingEntry|getAccountByPurpose|DYNAMIC',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:3960|GeneralLedgerService::createRefundCompensationEntry|getAccountByPurpose|DYNAMIC',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4027|GeneralLedgerService::createPosToleranceWriteoffEntry|getAccountByPurpose|ProductRevenue',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4028|GeneralLedgerService::createPosToleranceWriteoffEntry|getAccountByPurpose|PaymentToleranceExpense',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4085|GeneralLedgerService::createPOSChargeEntry|getAccountByPurpose|CustomerReceivable',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4086|GeneralLedgerService::createPOSChargeEntry|getAccountByPurpose|ProductRevenue',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4088|GeneralLedgerService::createPOSChargeEntry|getAccountByPurpose|VatCollected',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4091|GeneralLedgerService::createPOSChargeEntry|getAccountByPurpose|SalesDiscount',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:418|GeneralLedgerService::createCustomerAdvanceJournalEntry|getAccountByPurpose|CustomerAdvance',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4203|GeneralLedgerService::createFromExpense|getAccountByPurpose|DYNAMIC',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4215|GeneralLedgerService::createFromExpense|getAccountByPurpose|Bank',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4216|GeneralLedgerService::createFromExpense|getAccountByPurpose|Cash',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4221|GeneralLedgerService::createFromExpense|getAccountByPurpose|SupplierPayable',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4266|GeneralLedgerService::createFromExpense|getAccountByPurpose|VatDeductible',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4343|GeneralLedgerService::createFromIncome|getAccountByPurpose|ProductRevenue',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4360|GeneralLedgerService::createFromIncome|getAccountByPurpose|Bank',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4361|GeneralLedgerService::createFromIncome|getAccountByPurpose|Cash',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4441|GeneralLedgerService::createLinkedCostCapitalizationEntry|getAccountByPurpose|Inventory',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4442|GeneralLedgerService::createLinkedCostCapitalizationEntry|getAccountByPurpose|CostOfGoodsSold',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4529|GeneralLedgerService::createLinkedCostCapitalizationReversalEntry|getAccountByPurpose|Inventory',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4530|GeneralLedgerService::createLinkedCostCapitalizationReversalEntry|getAccountByPurpose|CostOfGoodsSold',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4598|GeneralLedgerService::expensePaymentAccount|getAccountByPurpose|Bank',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4599|GeneralLedgerService::expensePaymentAccount|getAccountByPurpose|Cash',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4669|GeneralLedgerService::createInventoryWriteOffEntry|getAccountByPurpose|CostOfGoodsSold',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4670|GeneralLedgerService::createInventoryWriteOffEntry|getAccountByPurpose|Inventory',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4849|GeneralLedgerService::getAccountByPurpose|findByPurposeOrFail|DYNAMIC',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:518|GeneralLedgerService::reverseSupplierAdvanceJournalEntry|getAccountByPurpose|SupplierAdvance',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:630|GeneralLedgerService::reverseCustomerAdvanceJournalEntry|getAccountByPurpose|CustomerAdvance',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:721|GeneralLedgerService::createPaymentRefundJournalEntry|getAccountByPurpose|CustomerReceivable',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:811|GeneralLedgerService::createSupplierInvoiceJournalEntry|getAccountByPurpose|SupplierPayable',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:812|GeneralLedgerService::createSupplierInvoiceJournalEntry|getAccountByPurpose|VatDeductible',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:900|GeneralLedgerService::createSupplierPaymentJournalEntry|getAccountByPurpose|SupplierPayable',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:964|GeneralLedgerService::createOutboundInstrumentIssueEntry|getAccountByPurpose|SupplierPayable',
            'app/Modules/Expense/Application/Services/ExpenseService.php:693|ExpenseService::settle|findByPurposeOrFail|Bank',
            'app/Modules/Expense/Application/Services/ExpenseService.php:694|ExpenseService::settle|findByPurposeOrFail|Cash',
            'app/Modules/Inventory/Application/Services/OpeningBalancePostingService.php:229|OpeningBalancePostingService::post|findByPurposeOrFail|Inventory',
            'app/Modules/Inventory/Application/Services/OpeningBalancePostingService.php:230|OpeningBalancePostingService::post|findByPurposeOrFail|OpeningBalanceEquity',
            'app/Modules/Inventory/Application/Services/ResetOpeningBalanceService.php:152|ResetOpeningBalanceService::reset|findByPurposeOrFail|Inventory',
            'app/Modules/Inventory/Application/Services/ResetOpeningBalanceService.php:153|ResetOpeningBalanceService::reset|findByPurposeOrFail|OpeningBalanceEquity',
            'app/Modules/Treasury/Application/Services/InstrumentLifecycleService.php:420|InstrumentLifecycleService::bounce|findByPurposeOrFail|CustomerReceivable',
            'app/Modules/Treasury/Application/Services/InstrumentLifecycleService.php:424|InstrumentLifecycleService::bounce|findByPurposeOrFail|CustomerReceivable',
        ];
    }

    /**
     * @param  list<array{purpose: SystemAccountPurpose, call_site: string, classification: string, gate_kind: ?string, evidence_citation: string}>  $entries
     */
    public static function assertConforms(array $entries): void
    {
        $counts = array_fill_keys([self::REQUIRED, self::SCOPE_REQUIRED, self::CONDITIONAL, self::SOFT], 0);
        $seen = [];

        foreach ($entries as $entry) {
            $purpose = $entry['purpose']->value;
            if (isset($seen[$purpose])) {
                throw new LogicException("Purpose {$purpose} appears more than once in the v1 manifest.");
            }
            $seen[$purpose] = true;

            $classification = $entry['classification'];
            if (! array_key_exists($classification, $counts)) {
                throw new LogicException("Unknown purpose classification {$classification}.");
            }
            $counts[$classification]++;

            if (trim($entry['call_site']) === '' || trim($entry['evidence_citation']) === '') {
                throw new LogicException("Purpose {$purpose} lacks operational evidence.");
            }
            if ($entry['gate_kind'] !== null && ! in_array($entry['gate_kind'], self::allowedGateKinds(), true)) {
                throw new LogicException("Purpose {$purpose} uses a forbidden gate kind.");
            }
            if ($classification === self::CONDITIONAL && $entry['gate_kind'] !== self::DOMAIN_PRECHECK_4XX) {
                throw new LogicException("Conditional purpose {$purpose} lacks a DOMAIN_PRECHECK_4XX gate.");
            }
            if ($classification === self::SOFT && $entry['call_site'] !== 'NONE') {
                throw new LogicException("Soft purpose {$purpose} retains a registered throwing call site.");
            }
            if ($classification !== self::CONDITIONAL && $entry['gate_kind'] !== null) {
                throw new LogicException("Non-conditional purpose {$purpose} must not carry a gate.");
            }
            if ($classification !== self::SOFT && $entry['call_site'] === 'NONE') {
                throw new LogicException("Operational purpose {$purpose} lacks a call site.");
            }
        }

        if ($counts !== [self::REQUIRED => 27, self::SCOPE_REQUIRED => 1, self::CONDITIONAL => 4, self::SOFT => 9]) {
            throw new LogicException('The v1 purpose partition must be exactly 27 + 1 + 4 + 9.');
        }
        if (count($seen) !== count(SystemAccountPurpose::cases())) {
            throw new LogicException('The v1 purpose manifest must cover every SystemAccountPurpose exactly once.');
        }
    }

    /**
     * @return array{purpose: SystemAccountPurpose, call_site: string, classification: string, gate_kind: ?string, evidence_citation: string}
     */
    private static function entry(
        SystemAccountPurpose $purpose,
        string $callSite,
        string $classification,
        ?string $gateKind,
        string $evidenceCitation,
    ): array {
        return [
            'purpose' => $purpose,
            'call_site' => $callSite,
            'classification' => $classification,
            'gate_kind' => $gateKind,
            'evidence_citation' => $evidenceCitation,
        ];
    }
}
