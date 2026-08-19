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
            self::entry(SystemAccountPurpose::Bank, 'GeneralLedgerService::createFromExpense paid bank branch', self::REQUIRED, null, 'DIRECT:app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4113|GeneralLedgerService::createFromExpense|getAccountByPurpose|Bank'),
            self::entry(SystemAccountPurpose::Cash, 'GeneralLedgerService::createFromExpense paid cash branch', self::REQUIRED, null, 'DIRECT:app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4114|GeneralLedgerService::createFromExpense|getAccountByPurpose|Cash'),
            self::entry(SystemAccountPurpose::CustomerReceivable, 'AccountingService::createInvoiceGLEntries', self::REQUIRED, null, 'DIRECT:app/Modules/Accounting/Application/Services/AccountingService.php:412|AccountingService::createInvoiceGLEntries|findAccountByPurpose|CustomerReceivable'),
            self::entry(SystemAccountPurpose::Inventory, 'GeneralLedgerService::createGoodsReceiptGrIrEntry', self::REQUIRED, null, 'DIRECT:app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1897|GeneralLedgerService::createGoodsReceiptGrIrEntry|findByPurposeOrFail|Inventory'),
            self::entry(SystemAccountPurpose::SupplierPayable, 'GeneralLedgerService::createSupplierInvoiceGrIrClearingEntry', self::REQUIRED, null, 'DIRECT:app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2057|GeneralLedgerService::createSupplierInvoiceGrIrClearingEntry|findByPurposeOrFail|SupplierPayable'),
            self::entry(SystemAccountPurpose::VatCollected, 'AccountingService::createInvoiceGLEntries', self::REQUIRED, null, 'DIRECT:app/Modules/Accounting/Application/Services/AccountingService.php:424|AccountingService::createInvoiceGLEntries|findAccountByPurpose|VatCollected'),
            self::entry(SystemAccountPurpose::VatDeductible, 'GeneralLedgerService::createSupplierInvoiceGrIrClearingEntry', self::REQUIRED, null, 'DIRECT:app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2052|GeneralLedgerService::createSupplierInvoiceGrIrClearingEntry|findByPurposeOrFail|VatDeductible'),
            self::entry(SystemAccountPurpose::ProductRevenue, 'AccountingService::createInvoiceGLEntries', self::REQUIRED, null, 'DIRECT:app/Modules/Accounting/Application/Services/AccountingService.php:416|AccountingService::createInvoiceGLEntries|findAccountByPurpose|ProductRevenue'),
            self::entry(SystemAccountPurpose::ServiceRevenue, 'AccountingService::createInvoiceGLEntries', self::REQUIRED, null, 'DIRECT:app/Modules/Accounting/Application/Services/AccountingService.php:420|AccountingService::createInvoiceGLEntries|findAccountByPurpose|ServiceRevenue'),
            self::entry(SystemAccountPurpose::CostOfGoodsSold, 'GeneralLedgerService::createLinkedCostCapitalizationEntry', self::REQUIRED, null, 'DIRECT:app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4340|GeneralLedgerService::createLinkedCostCapitalizationEntry|getAccountByPurpose|CostOfGoodsSold'),
            self::entry(SystemAccountPurpose::GeneralExpense, 'GeneralLedgerService::createFromExpense category fallback', self::REQUIRED, null, 'DYNAMIC:app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4101|GeneralLedgerService::createFromExpense|getAccountByPurpose|DYNAMIC <- app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4087|GeneralLedgerService::createFromExpense|GeneralExpense'),
            self::entry(SystemAccountPurpose::OpeningBalanceEquity, 'AccountingOpeningService::postBatch', self::REQUIRED, null, 'DIRECT:app/Modules/Accounting/Application/Services/AccountingOpeningService.php:314|AccountingOpeningService::postBatch|findByPurposeOrFail|OpeningBalanceEquity'),
            self::entry(SystemAccountPurpose::PurchasePriceVarianceExpense, 'GeneralLedgerService::createSupplierInvoiceGrIrClearingEntry', self::REQUIRED, null, 'DIRECT:app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2055|GeneralLedgerService::createSupplierInvoiceGrIrClearingEntry|findByPurposeOrFail|PurchasePriceVarianceExpense'),
            self::entry(SystemAccountPurpose::PurchasePriceVarianceIncome, 'GeneralLedgerService::createSupplierInvoiceGrIrClearingEntry', self::REQUIRED, null, 'DIRECT:app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2056|GeneralLedgerService::createSupplierInvoiceGrIrClearingEntry|findByPurposeOrFail|PurchasePriceVarianceIncome'),
            self::entry(SystemAccountPurpose::GoodsReceivedNotInvoiced, 'GeneralLedgerService::createSupplierInvoiceGrIrClearingEntry', self::REQUIRED, null, 'DIRECT:app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2051|GeneralLedgerService::createSupplierInvoiceGrIrClearingEntry|findByPurposeOrFail|GoodsReceivedNotInvoiced'),
            self::entry(SystemAccountPurpose::PurchaseStampDuty, 'GeneralLedgerService::createSupplierInvoiceGrIrClearingEntry', self::REQUIRED, null, 'DIRECT:app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2053|GeneralLedgerService::createSupplierInvoiceGrIrClearingEntry|findByPurposeOrFail|PurchaseStampDuty'),
            self::entry(SystemAccountPurpose::SalesDiscount, 'GeneralLedgerService::createPOSChargeEntry', self::REQUIRED, null, 'DIRECT:app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:3989|GeneralLedgerService::createPOSChargeEntry|getAccountByPurpose|SalesDiscount'),
            self::entry(SystemAccountPurpose::CustomerAdvance, 'GeneralLedgerService::createCustomerAdvanceJournalEntry', self::REQUIRED, null, 'DIRECT:app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:420|GeneralLedgerService::createCustomerAdvanceJournalEntry|getAccountByPurpose|CustomerAdvance'),
            self::entry(SystemAccountPurpose::SupplierAdvance, 'GeneralLedgerService::reverseSupplierAdvanceJournalEntry', self::REQUIRED, null, 'DIRECT:app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:520|GeneralLedgerService::reverseSupplierAdvanceJournalEntry|getAccountByPurpose|SupplierAdvance'),
            self::entry(SystemAccountPurpose::SalesReturnsClearing, 'GeneralLedgerService voucher event resolution', self::REQUIRED, null, 'DYNAMIC:app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2633|GeneralLedgerService::createVoucherLedgerEntry|getAccountByPurpose|DYNAMIC <- app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2693|GeneralLedgerService::resolveVoucherEventAccounts|SalesReturnsClearing'),
            self::entry(SystemAccountPurpose::VoucherLiability, 'GeneralLedgerService voucher event resolution', self::REQUIRED, null, 'DYNAMIC:app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2633|GeneralLedgerService::createVoucherLedgerEntry|getAccountByPurpose|DYNAMIC <- app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2710|GeneralLedgerService::resolveVoucherEventAccounts|VoucherLiability'),
            self::entry(SystemAccountPurpose::MarketingGoodwillExpense, 'GeneralLedgerService voucher event resolution', self::REQUIRED, null, 'DYNAMIC:app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2633|GeneralLedgerService::createVoucherLedgerEntry|getAccountByPurpose|DYNAMIC <- app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2697|GeneralLedgerService::resolveVoucherEventAccounts|MarketingGoodwillExpense'),
            self::entry(SystemAccountPurpose::PosTenderClearing, 'GeneralLedgerService voucher event resolution', self::REQUIRED, null, 'DYNAMIC:app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2634|GeneralLedgerService::createVoucherLedgerEntry|getAccountByPurpose|DYNAMIC <- app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2711|GeneralLedgerService::resolveVoucherEventAccounts|PosTenderClearing'),
            self::entry(SystemAccountPurpose::RoundingLossExpense, 'GeneralLedgerService voucher event resolution', self::REQUIRED, null, 'DYNAMIC:app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2634|GeneralLedgerService::createVoucherLedgerEntry|getAccountByPurpose|DYNAMIC <- app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2716|GeneralLedgerService::resolveVoucherEventAccounts|RoundingLossExpense'),
            self::entry(SystemAccountPurpose::PaymentToleranceExpense, 'GeneralLedgerService::createRepositoryAdjustmentJournalEntry', self::REQUIRED, null, 'DIRECT:app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1241|GeneralLedgerService::createRepositoryAdjustmentJournalEntry|getAccountByPurpose|PaymentToleranceExpense'),
            self::entry(SystemAccountPurpose::PaymentToleranceIncome, 'GeneralLedgerService::createRepositoryAdjustmentJournalEntry', self::REQUIRED, null, 'DIRECT:app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1242|GeneralLedgerService::createRepositoryAdjustmentJournalEntry|getAccountByPurpose|PaymentToleranceIncome'),
            self::entry(SystemAccountPurpose::PurchaseExpenses, 'GeneralLedgerService::createSupplierCreditNoteEntryWithBonusReturn', self::REQUIRED, null, 'DIRECT:app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2438|GeneralLedgerService::createSupplierCreditNoteEntryWithBonusReturn|findByPurposeOrFail|PurchaseExpenses'),

            self::entry(SystemAccountPurpose::SalesStampDutyPayable, 'GeneralLedgerService::createFromCreditNote stamp-duty scope', self::SCOPE_REQUIRED, null, 'DIRECT:app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:294|GeneralLedgerService::createFromCreditNote|getAccountByPurpose|SalesStampDutyPayable'),

            self::entry(SystemAccountPurpose::SalesReturn, 'RefundCompensationService::compensate precheck', self::CONDITIONAL, self::DOMAIN_PRECHECK_4XX, 'CONDITIONAL:app/Modules/Fiscal/Application/Services/RefundCompensationService.php:187|RefundCompensationService::compensate|SalesReturn'),
            self::entry(SystemAccountPurpose::RefundWriteOff, 'RefundCompensationService::compensate precheck', self::CONDITIONAL, self::DOMAIN_PRECHECK_4XX, 'CONDITIONAL:app/Modules/Fiscal/Application/Services/RefundCompensationService.php:187|RefundCompensationService::compensate|RefundWriteOff'),
            self::entry(SystemAccountPurpose::SalesRoundingDifferenceIncome, 'AccountingService::residualPlan preflight', self::CONDITIONAL, self::DOMAIN_PRECHECK_4XX, 'CONDITIONAL:app/Modules/Accounting/Application/Services/AccountingService.php:224|AccountingService::residualPlan|SalesRoundingDifferenceIncome'),
            self::entry(SystemAccountPurpose::SalesRoundingDifferenceExpense, 'AccountingService::residualPlan preflight', self::CONDITIONAL, self::DOMAIN_PRECHECK_4XX, 'CONDITIONAL:app/Modules/Accounting/Application/Services/AccountingService.php:223|AccountingService::residualPlan|SalesRoundingDifferenceExpense'),

            self::entry(SystemAccountPurpose::OfficeExpense, 'NONE', self::SOFT, null, 'NONE:No registered production throwing purpose-resolution site.'),
            self::entry(SystemAccountPurpose::TravelExpense, 'NONE', self::SOFT, null, 'NONE:No registered production throwing purpose-resolution site.'),
            self::entry(SystemAccountPurpose::MealsExpense, 'NONE', self::SOFT, null, 'NONE:No registered production throwing purpose-resolution site.'),
            self::entry(SystemAccountPurpose::UtilitiesExpense, 'NONE', self::SOFT, null, 'NONE:No registered production throwing purpose-resolution site.'),
            self::entry(SystemAccountPurpose::RetainedEarnings, 'NONE', self::SOFT, null, 'NONE:No registered production throwing purpose-resolution site.'),
            self::entry(SystemAccountPurpose::RealizedFxGain, 'NONE', self::SOFT, null, 'NONE:No registered production throwing purpose-resolution site.'),
            self::entry(SystemAccountPurpose::RealizedFxLoss, 'NONE', self::SOFT, null, 'NONE:No registered production throwing purpose-resolution site.'),
            self::entry(SystemAccountPurpose::VoucherBreakageIncome, 'NONE', self::SOFT, null, 'NONE:No Expired voucher arm is wired in production.'),
            self::entry(SystemAccountPurpose::UninvoicedRevenue, 'NONE', self::SOFT, null, 'NONE:UninvoicedDeliveryNoteService has zero production callers; production-root AST scan enforced.'),
            // Both variance purposes remain SOFT at the template publication
            // gate. Live destructive-loss writers preflight the shrinkage mapping
            // and guard missing legacy purposes as a warning/no-entry no-op; the
            // gain purpose has no producer until T21.
            self::entry(SystemAccountPurpose::InventoryShrinkageExpense, 'NONE', self::SOFT, null, 'NONE:Destructive-loss posting preflights the purpose and fails soft for frozen legacy charts.'),
            self::entry(SystemAccountPurpose::InventoryGainIncome, 'NONE', self::SOFT, null, 'NONE:Count-correction GL posting fail-softs when unmapped; no CountCorrection producer until T21.'),
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
            'app/Modules/Accounting/Application/Services/ChartOfAccountsService.php:90|ChartOfAccountsService::getAccountByPurpose|findByPurposeOrFail|DYNAMIC',
            'app/Modules/Accounting/Application/Services/PartnerBalanceService.php:172|PartnerBalanceService::getControlAccountBalance|findByPurposeOrFail|DYNAMIC',
            'app/Modules/Accounting/Application/Services/PartnerBalanceService.php:206|PartnerBalanceService::reconcileSubledger|findByPurposeOrFail|DYNAMIC',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1040|GeneralLedgerService::createOutboundInstrumentCancellationEntry|getAccountByPurpose|SupplierPayable',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1148|GeneralLedgerService::createExpenseSettlementJournalEntry|getAccountByPurpose|SupplierPayable',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1241|GeneralLedgerService::createRepositoryAdjustmentJournalEntry|getAccountByPurpose|PaymentToleranceExpense',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1242|GeneralLedgerService::createRepositoryAdjustmentJournalEntry|getAccountByPurpose|PaymentToleranceIncome',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:140|GeneralLedgerService::createFromInvoice|getAccountByPurpose|CustomerReceivable',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:141|GeneralLedgerService::createFromInvoice|getAccountByPurpose|ProductRevenue',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:142|GeneralLedgerService::createFromInvoice|getAccountByPurpose|VatCollected',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1471|GeneralLedgerService::createPaymentReceivedJournalEntry|getAccountByPurpose|CustomerReceivable',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1562|GeneralLedgerService::createPaymentToleranceJournalEntry|getAccountByPurpose|CustomerReceivable',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1572|GeneralLedgerService::createPaymentToleranceJournalEntry|getAccountByPurpose|DYNAMIC',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1672|GeneralLedgerService::clearCustomerAdvanceToReceivable|getAccountByPurpose|CustomerAdvance',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1673|GeneralLedgerService::clearCustomerAdvanceToReceivable|getAccountByPurpose|CustomerReceivable',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1802|GeneralLedgerService::availableCustomerAdvance|getAccountByPurpose|CustomerAdvance',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1897|GeneralLedgerService::createGoodsReceiptGrIrEntry|findByPurposeOrFail|Inventory',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1898|GeneralLedgerService::createGoodsReceiptGrIrEntry|findByPurposeOrFail|GoodsReceivedNotInvoiced',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2051|GeneralLedgerService::createSupplierInvoiceGrIrClearingEntry|findByPurposeOrFail|GoodsReceivedNotInvoiced',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2052|GeneralLedgerService::createSupplierInvoiceGrIrClearingEntry|findByPurposeOrFail|VatDeductible',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2053|GeneralLedgerService::createSupplierInvoiceGrIrClearingEntry|findByPurposeOrFail|PurchaseStampDuty',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2054|GeneralLedgerService::createSupplierInvoiceGrIrClearingEntry|findByPurposeOrFail|Inventory',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2055|GeneralLedgerService::createSupplierInvoiceGrIrClearingEntry|findByPurposeOrFail|PurchasePriceVarianceExpense',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2056|GeneralLedgerService::createSupplierInvoiceGrIrClearingEntry|findByPurposeOrFail|PurchasePriceVarianceIncome',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2057|GeneralLedgerService::createSupplierInvoiceGrIrClearingEntry|findByPurposeOrFail|SupplierPayable',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:224|GeneralLedgerService::createFromCreditNote|getAccountByPurpose|CustomerReceivable',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:225|GeneralLedgerService::createFromCreditNote|getAccountByPurpose|ProductRevenue',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:226|GeneralLedgerService::createFromCreditNote|getAccountByPurpose|VatCollected',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2272|GeneralLedgerService::createSupplierCreditNoteEntry|findByPurposeOrFail|VatDeductible',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2273|GeneralLedgerService::createSupplierCreditNoteEntry|findByPurposeOrFail|Inventory',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2274|GeneralLedgerService::createSupplierCreditNoteEntry|findByPurposeOrFail|SupplierPayable',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2435|GeneralLedgerService::createSupplierCreditNoteEntryWithBonusReturn|findByPurposeOrFail|VatDeductible',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2436|GeneralLedgerService::createSupplierCreditNoteEntryWithBonusReturn|findByPurposeOrFail|Inventory',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2437|GeneralLedgerService::createSupplierCreditNoteEntryWithBonusReturn|findByPurposeOrFail|SupplierPayable',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2438|GeneralLedgerService::createSupplierCreditNoteEntryWithBonusReturn|findByPurposeOrFail|PurchaseExpenses',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2633|GeneralLedgerService::createVoucherLedgerEntry|getAccountByPurpose|DYNAMIC',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2634|GeneralLedgerService::createVoucherLedgerEntry|getAccountByPurpose|DYNAMIC',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2819|GeneralLedgerService::createInstrumentRepresentationEntry|getAccountByPurpose|CustomerReceivable',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:293|GeneralLedgerService::createFromCreditNote|getAccountByPurpose|PurchaseStampDuty',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:294|GeneralLedgerService::createFromCreditNote|getAccountByPurpose|SalesStampDutyPayable',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:3223|GeneralLedgerService::createInstrumentCancellationEntry|getAccountByPurpose|ProductRevenue',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:3289|GeneralLedgerService::b2bCancellationDebits|getAccountByPurpose|CustomerReceivable',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:3327|GeneralLedgerService::b2bCancellationDebits|getAccountByPurpose|CustomerAdvance',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:3479|GeneralLedgerService::createPOSPaymentToleranceEntry|getAccountByPurpose|PaymentToleranceExpense',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:3480|GeneralLedgerService::createPOSPaymentToleranceEntry|getAccountByPurpose|ProductRevenue',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:3558|GeneralLedgerService::createPOSPaymentEntry|getAccountByPurpose|ProductRevenue',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:3642|GeneralLedgerService::createPOSRefundReversalEntry|getAccountByPurpose|ProductRevenue',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:3747|GeneralLedgerService::createPosCashRoundingEntry|getAccountByPurpose|ProductRevenue',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:3749|GeneralLedgerService::createPosCashRoundingEntry|getAccountByPurpose|DYNAMIC',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:3858|GeneralLedgerService::createRefundCompensationEntry|getAccountByPurpose|DYNAMIC',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:3925|GeneralLedgerService::createPosToleranceWriteoffEntry|getAccountByPurpose|ProductRevenue',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:3926|GeneralLedgerService::createPosToleranceWriteoffEntry|getAccountByPurpose|PaymentToleranceExpense',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:3983|GeneralLedgerService::createPOSChargeEntry|getAccountByPurpose|CustomerReceivable',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:3984|GeneralLedgerService::createPOSChargeEntry|getAccountByPurpose|ProductRevenue',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:3986|GeneralLedgerService::createPOSChargeEntry|getAccountByPurpose|VatCollected',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:3989|GeneralLedgerService::createPOSChargeEntry|getAccountByPurpose|SalesDiscount',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4101|GeneralLedgerService::createFromExpense|getAccountByPurpose|DYNAMIC',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4113|GeneralLedgerService::createFromExpense|getAccountByPurpose|Bank',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4114|GeneralLedgerService::createFromExpense|getAccountByPurpose|Cash',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4119|GeneralLedgerService::createFromExpense|getAccountByPurpose|SupplierPayable',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4164|GeneralLedgerService::createFromExpense|getAccountByPurpose|VatDeductible',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:420|GeneralLedgerService::createCustomerAdvanceJournalEntry|getAccountByPurpose|CustomerAdvance',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4241|GeneralLedgerService::createFromIncome|getAccountByPurpose|ProductRevenue',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4258|GeneralLedgerService::createFromIncome|getAccountByPurpose|Bank',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4259|GeneralLedgerService::createFromIncome|getAccountByPurpose|Cash',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4339|GeneralLedgerService::createLinkedCostCapitalizationEntry|getAccountByPurpose|Inventory',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4340|GeneralLedgerService::createLinkedCostCapitalizationEntry|getAccountByPurpose|CostOfGoodsSold',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4427|GeneralLedgerService::createLinkedCostCapitalizationReversalEntry|getAccountByPurpose|Inventory',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4428|GeneralLedgerService::createLinkedCostCapitalizationReversalEntry|getAccountByPurpose|CostOfGoodsSold',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4496|GeneralLedgerService::expensePaymentAccount|getAccountByPurpose|Bank',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4497|GeneralLedgerService::expensePaymentAccount|getAccountByPurpose|Cash',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4590|GeneralLedgerService::createInventoryMovementEntry|getAccountByPurpose|Inventory',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4591|GeneralLedgerService::createInventoryMovementEntry|getAccountByPurpose|DYNAMIC',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4814|GeneralLedgerService::createInventoryWriteOffEntry|getAccountByPurpose|DYNAMIC',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4815|GeneralLedgerService::createInventoryWriteOffEntry|getAccountByPurpose|Inventory',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:5000|GeneralLedgerService::getAccountByPurpose|findByPurposeOrFail|DYNAMIC',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:520|GeneralLedgerService::reverseSupplierAdvanceJournalEntry|getAccountByPurpose|SupplierAdvance',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:632|GeneralLedgerService::reverseCustomerAdvanceJournalEntry|getAccountByPurpose|CustomerAdvance',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:723|GeneralLedgerService::createPaymentRefundJournalEntry|getAccountByPurpose|CustomerReceivable',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:813|GeneralLedgerService::createSupplierInvoiceJournalEntry|getAccountByPurpose|SupplierPayable',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:814|GeneralLedgerService::createSupplierInvoiceJournalEntry|getAccountByPurpose|VatDeductible',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:902|GeneralLedgerService::createSupplierPaymentJournalEntry|getAccountByPurpose|SupplierPayable',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:966|GeneralLedgerService::createOutboundInstrumentIssueEntry|getAccountByPurpose|SupplierPayable',
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
            $evidenceKind = strstr($entry['evidence_citation'], ':', true);
            $allowedEvidenceKinds = match ($classification) {
                self::REQUIRED, self::SCOPE_REQUIRED => ['DIRECT', 'DYNAMIC'],
                self::CONDITIONAL => ['CONDITIONAL'],
                self::SOFT => ['NONE'],
            };
            if (! in_array($evidenceKind, $allowedEvidenceKinds, true)) {
                throw new LogicException("Purpose {$purpose} has evidence pointing in the wrong classification direction.");
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

        if ($counts !== [self::REQUIRED => 27, self::SCOPE_REQUIRED => 1, self::CONDITIONAL => 4, self::SOFT => 11]) {
            throw new LogicException('The v1 purpose partition must be exactly 27 + 1 + 4 + 11.');
        }
        if (count($seen) !== count(SystemAccountPurpose::cases())) {
            throw new LogicException('The v1 purpose manifest must cover every SystemAccountPurpose exactly once.');
        }
    }

    /**
     * @param  list<array{purpose: SystemAccountPurpose, call_site: string, classification: string, gate_kind: ?string, evidence_citation: string}>  $entries
     * @param  list<SystemAccountPurpose>  $purposes
     */
    public static function assertDynamicRequiredPurposes(array $entries, array $purposes): void
    {
        $byPurpose = [];
        foreach ($entries as $entry) {
            $byPurpose[$entry['purpose']->value] = $entry;
        }

        foreach ($purposes as $purpose) {
            $entry = $byPurpose[$purpose->value] ?? null;
            if ($entry === null
                || $entry['classification'] !== self::REQUIRED
                || ! str_starts_with($entry['evidence_citation'], 'DYNAMIC:')
                || ! str_contains($entry['evidence_citation'], $purpose->name)
            ) {
                throw new LogicException("DYNAMIC-only purpose {$purpose->value} must remain REQUIRED with typed source evidence.");
            }
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
