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
            self::entry(SystemAccountPurpose::Bank, 'GeneralLedgerService::createFromExpense paid bank branch', self::REQUIRED, null, 'DIRECT:app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4765|GeneralLedgerService::createFromExpense|getAccountByPurpose|Bank'),
            self::entry(SystemAccountPurpose::Cash, 'GeneralLedgerService::createFromExpense paid cash branch', self::REQUIRED, null, 'DIRECT:app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4766|GeneralLedgerService::createFromExpense|getAccountByPurpose|Cash'),
            self::entry(SystemAccountPurpose::CustomerReceivable, 'AccountingService::createInvoiceGLEntries', self::REQUIRED, null, 'DIRECT:app/Modules/Accounting/Application/Services/AccountingService.php:612|AccountingService::createInvoiceGLEntries|findAccountByPurpose|CustomerReceivable'),
            self::entry(SystemAccountPurpose::Inventory, 'GeneralLedgerService::createGoodsReceiptGrIrEntry', self::REQUIRED, null, 'DIRECT:app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2105|GeneralLedgerService::createGoodsReceiptGrIrEntry|findByPurposeOrFail|Inventory'),
            self::entry(SystemAccountPurpose::SupplierPayable, 'GeneralLedgerService::createSupplierInvoiceGrIrClearingEntry', self::REQUIRED, null, 'DIRECT:app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2265|GeneralLedgerService::createSupplierInvoiceGrIrClearingEntry|findByPurposeOrFail|SupplierPayable'),
            self::entry(SystemAccountPurpose::VatCollected, 'AccountingService::createInvoiceGLEntries', self::REQUIRED, null, 'DIRECT:app/Modules/Accounting/Application/Services/AccountingService.php:624|AccountingService::createInvoiceGLEntries|findAccountByPurpose|VatCollected'),
            self::entry(SystemAccountPurpose::VatDeductible, 'GeneralLedgerService::createSupplierInvoiceGrIrClearingEntry', self::REQUIRED, null, 'DIRECT:app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2260|GeneralLedgerService::createSupplierInvoiceGrIrClearingEntry|findByPurposeOrFail|VatDeductible'),
            self::entry(SystemAccountPurpose::ProductRevenue, 'AccountingService::createInvoiceGLEntries', self::REQUIRED, null, 'DIRECT:app/Modules/Accounting/Application/Services/AccountingService.php:616|AccountingService::createInvoiceGLEntries|findAccountByPurpose|ProductRevenue'),
            self::entry(SystemAccountPurpose::ServiceRevenue, 'AccountingService::createInvoiceGLEntries', self::REQUIRED, null, 'DIRECT:app/Modules/Accounting/Application/Services/AccountingService.php:620|AccountingService::createInvoiceGLEntries|findAccountByPurpose|ServiceRevenue'),
            self::entry(SystemAccountPurpose::CostOfGoodsSold, 'GeneralLedgerService::createLinkedCostCapitalizationEntry', self::REQUIRED, null, 'DIRECT:app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:5012|GeneralLedgerService::createLinkedCostCapitalizationEntry|getAccountByPurpose|CostOfGoodsSold'),
            self::entry(SystemAccountPurpose::InventoryShrinkageExpense, 'GeneralLedgerService::createInventoryWriteOffEntry', self::REQUIRED, null, 'DIRECT:app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:5486|GeneralLedgerService::createInventoryWriteOffEntry|getAccountByPurpose|InventoryShrinkageExpense'),
            self::entry(SystemAccountPurpose::GeneralExpense, 'GeneralLedgerService::createFromExpense category fallback', self::REQUIRED, null, 'DYNAMIC:app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4729|GeneralLedgerService::createFromExpense|getAccountByPurpose|DYNAMIC <- app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4715|GeneralLedgerService::createFromExpense|GeneralExpense'),
            self::entry(SystemAccountPurpose::OpeningBalanceEquity, 'AccountingOpeningService::postBatch', self::REQUIRED, null, 'DIRECT:app/Modules/Accounting/Application/Services/AccountingOpeningService.php:752|AccountingOpeningService::postBatch|findByPurposeOrFail|OpeningBalanceEquity'),
            self::entry(SystemAccountPurpose::PurchasePriceVarianceExpense, 'GeneralLedgerService::createSupplierInvoiceGrIrClearingEntry', self::REQUIRED, null, 'DIRECT:app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2263|GeneralLedgerService::createSupplierInvoiceGrIrClearingEntry|findByPurposeOrFail|PurchasePriceVarianceExpense'),
            self::entry(SystemAccountPurpose::PurchasePriceVarianceIncome, 'GeneralLedgerService::createSupplierInvoiceGrIrClearingEntry', self::REQUIRED, null, 'DIRECT:app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2264|GeneralLedgerService::createSupplierInvoiceGrIrClearingEntry|findByPurposeOrFail|PurchasePriceVarianceIncome'),
            self::entry(SystemAccountPurpose::GoodsReceivedNotInvoiced, 'GeneralLedgerService::createSupplierInvoiceGrIrClearingEntry', self::REQUIRED, null, 'DIRECT:app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2259|GeneralLedgerService::createSupplierInvoiceGrIrClearingEntry|findByPurposeOrFail|GoodsReceivedNotInvoiced'),
            self::entry(SystemAccountPurpose::PurchaseStampDuty, 'GeneralLedgerService::createSupplierInvoiceGrIrClearingEntry', self::REQUIRED, null, 'DIRECT:app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2261|GeneralLedgerService::createSupplierInvoiceGrIrClearingEntry|findByPurposeOrFail|PurchaseStampDuty'),
            self::entry(SystemAccountPurpose::SalesDiscount, 'GeneralLedgerService::createPOSChargeEntry', self::REQUIRED, null, 'DIRECT:app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4578|GeneralLedgerService::createPOSChargeEntry|getAccountByPurpose|SalesDiscount'),
            self::entry(SystemAccountPurpose::CustomerAdvance, 'GeneralLedgerService::createCustomerAdvanceJournalEntry', self::REQUIRED, null, 'DIRECT:app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:532|GeneralLedgerService::createCustomerAdvanceJournalEntry|getAccountByPurpose|CustomerAdvance'),
            self::entry(SystemAccountPurpose::SupplierAdvance, 'GeneralLedgerService::reverseSupplierAdvanceJournalEntry', self::REQUIRED, null, 'DIRECT:app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:631|GeneralLedgerService::reverseSupplierAdvanceJournalEntry|getAccountByPurpose|SupplierAdvance'),
            self::entry(SystemAccountPurpose::SalesReturnsClearing, 'GeneralLedgerService voucher event resolution', self::REQUIRED, null, 'DYNAMIC:app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2943|GeneralLedgerService::createVoucherLedgerEntry|getAccountByPurpose|DYNAMIC <- app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:3003|GeneralLedgerService::resolveVoucherEventAccounts|SalesReturnsClearing'),
            self::entry(SystemAccountPurpose::VoucherLiability, 'GeneralLedgerService voucher event resolution', self::REQUIRED, null, 'DYNAMIC:app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2943|GeneralLedgerService::createVoucherLedgerEntry|getAccountByPurpose|DYNAMIC <- app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:3020|GeneralLedgerService::resolveVoucherEventAccounts|VoucherLiability'),
            self::entry(SystemAccountPurpose::MarketingGoodwillExpense, 'GeneralLedgerService voucher event resolution', self::REQUIRED, null, 'DYNAMIC:app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2943|GeneralLedgerService::createVoucherLedgerEntry|getAccountByPurpose|DYNAMIC <- app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:3007|GeneralLedgerService::resolveVoucherEventAccounts|MarketingGoodwillExpense'),
            self::entry(SystemAccountPurpose::PosTenderClearing, 'GeneralLedgerService voucher event resolution', self::REQUIRED, null, 'DYNAMIC:app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2944|GeneralLedgerService::createVoucherLedgerEntry|getAccountByPurpose|DYNAMIC <- app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:3021|GeneralLedgerService::resolveVoucherEventAccounts|PosTenderClearing'),
            self::entry(SystemAccountPurpose::RoundingLossExpense, 'GeneralLedgerService voucher event resolution', self::REQUIRED, null, 'DYNAMIC:app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2944|GeneralLedgerService::createVoucherLedgerEntry|getAccountByPurpose|DYNAMIC <- app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:3026|GeneralLedgerService::resolveVoucherEventAccounts|RoundingLossExpense'),
            self::entry(SystemAccountPurpose::PaymentToleranceExpense, 'GeneralLedgerService::createRepositoryAdjustmentJournalEntry', self::REQUIRED, null, 'DIRECT:app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1349|GeneralLedgerService::createRepositoryAdjustmentJournalEntry|getAccountByPurpose|PaymentToleranceExpense'),
            self::entry(SystemAccountPurpose::PaymentToleranceIncome, 'GeneralLedgerService::createRepositoryAdjustmentJournalEntry', self::REQUIRED, null, 'DIRECT:app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1350|GeneralLedgerService::createRepositoryAdjustmentJournalEntry|getAccountByPurpose|PaymentToleranceIncome'),
            self::entry(SystemAccountPurpose::PurchaseExpenses, 'GeneralLedgerService::createSupplierCreditNoteEntryWithBonusReturn', self::REQUIRED, null, 'DIRECT:app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2678|GeneralLedgerService::createSupplierCreditNoteEntryWithBonusReturn|findByPurposeOrFail|PurchaseExpenses'),

            self::entry(SystemAccountPurpose::SalesStampDutyPayable, 'GeneralLedgerService::createFromCreditNote stamp-duty scope', self::SCOPE_REQUIRED, null, 'DIRECT:app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:308|GeneralLedgerService::createFromCreditNote|getAccountByPurpose|SalesStampDutyPayable'),

            self::entry(SystemAccountPurpose::SalesReturn, 'RefundCompensationService::compensate precheck', self::CONDITIONAL, self::DOMAIN_PRECHECK_4XX, 'CONDITIONAL:app/Modules/Fiscal/Application/Services/RefundCompensationService.php:187|RefundCompensationService::compensate|SalesReturn'),
            self::entry(SystemAccountPurpose::RefundWriteOff, 'RefundCompensationService::compensate precheck', self::CONDITIONAL, self::DOMAIN_PRECHECK_4XX, 'CONDITIONAL:app/Modules/Fiscal/Application/Services/RefundCompensationService.php:187|RefundCompensationService::compensate|RefundWriteOff'),
            self::entry(SystemAccountPurpose::SalesRoundingDifferenceIncome, 'AccountingService::residualPlan preflight', self::CONDITIONAL, self::DOMAIN_PRECHECK_4XX, 'CONDITIONAL:app/Modules/Accounting/Application/Services/AccountingService.php:399|AccountingService::residualPlan|SalesRoundingDifferenceIncome'),
            self::entry(SystemAccountPurpose::SalesRoundingDifferenceExpense, 'AccountingService::residualPlan preflight', self::CONDITIONAL, self::DOMAIN_PRECHECK_4XX, 'CONDITIONAL:app/Modules/Accounting/Application/Services/AccountingService.php:398|AccountingService::residualPlan|SalesRoundingDifferenceExpense'),

            self::entry(SystemAccountPurpose::OfficeExpense, 'NONE', self::SOFT, null, 'NONE:No registered production throwing purpose-resolution site.'),
            self::entry(SystemAccountPurpose::TravelExpense, 'NONE', self::SOFT, null, 'NONE:No registered production throwing purpose-resolution site.'),
            self::entry(SystemAccountPurpose::MealsExpense, 'NONE', self::SOFT, null, 'NONE:No registered production throwing purpose-resolution site.'),
            self::entry(SystemAccountPurpose::UtilitiesExpense, 'NONE', self::SOFT, null, 'NONE:No registered production throwing purpose-resolution site.'),
            self::entry(SystemAccountPurpose::RetainedEarnings, 'NONE', self::SOFT, null, 'NONE:No registered production throwing purpose-resolution site.'),
            self::entry(SystemAccountPurpose::RealizedFxGain, 'NONE', self::SOFT, null, 'NONE:No registered production throwing purpose-resolution site.'),
            self::entry(SystemAccountPurpose::RealizedFxLoss, 'NONE', self::SOFT, null, 'NONE:No registered production throwing purpose-resolution site.'),
            self::entry(SystemAccountPurpose::VoucherBreakageIncome, 'NONE', self::SOFT, null, 'NONE:No Expired voucher arm is wired in production.'),
            self::entry(SystemAccountPurpose::UninvoicedRevenue, 'NONE', self::SOFT, null, 'NONE:UninvoicedDeliveryNoteService has zero production callers; production-root AST scan enforced.'),
            // Gain remains SOFT until T21. Shrinkage is REQUIRED for every newly
            // certified template because destructive-loss writers are live. The
            // separately ruled frozen-seeder fallback bypasses this publication
            // gate and retains its explicit warning/no-entry compatibility path.
            self::entry(SystemAccountPurpose::InventoryGainIncome, 'NONE', self::SOFT, null, 'NONE:Count-correction GL posting fail-softs when unmapped; no CountCorrection producer until T21.'),
        ];
    }

    /**
     * The REQUIRED partition, as the authority's own answer.
     *
     * ADDED, NOT EDITED (O-27). The manifest's DATA — `entries()`, the
     * classifications, the evidence citations, `registeredThrowingCallSites()`
     * — is frozen and must never be changed by a consuming lane. This is an
     * accessor over that data: it introduces no new fact and cannot change any
     * classification. Adding a read path is permitted; rewriting what is read
     * is not.
     *
     * WHY IT EXISTS. Consumers previously filtered `entries()` themselves with
     * `$entry['classification'] === 'REQUIRED'` — a bare string compared
     * against a PRIVATE const. That comparison FAILS OPEN: change the value of
     * `self::REQUIRED` and every such filter silently matches nothing, so
     * `SystemAccountPurpose::requiredPurposes()` returns `[]` and
     * `ChartOfAccountsService::validateCompanyAccounts()` certifies every chart
     * — including one that cannot post a single entry — as healthy. Callers
     * cannot reference the const, so the only safe fix is for the authority to
     * answer the question itself, comparing against the const in the one scope
     * that can see it.
     *
     * Deliberately does NOT call {@see assertConforms()}: live request paths
     * consume this (validation, the Chart of Accounts screen) and a drifted
     * manifest must fail in CI, not throw at an operator. The conformance gate
     * is `SeededChartManifestRequiredPurposeCompletenessTest`.
     *
     * @return list<SystemAccountPurpose>
     */
    public static function requiredPurposes(): array
    {
        $required = [];

        foreach (self::entries() as $entry) {
            if ($entry['classification'] === self::REQUIRED) {
                $required[] = $entry['purpose'];
            }
        }

        return $required;
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
            'app/Modules/Accounting/Application/Services/AccountingOpeningService.php:752|AccountingOpeningService::postBatch|findByPurposeOrFail|OpeningBalanceEquity',
            'app/Modules/Accounting/Application/Services/AccountingService.php:612|AccountingService::createInvoiceGLEntries|findAccountByPurpose|CustomerReceivable',
            'app/Modules/Accounting/Application/Services/AccountingService.php:616|AccountingService::createInvoiceGLEntries|findAccountByPurpose|ProductRevenue',
            'app/Modules/Accounting/Application/Services/AccountingService.php:620|AccountingService::createInvoiceGLEntries|findAccountByPurpose|ServiceRevenue',
            'app/Modules/Accounting/Application/Services/AccountingService.php:624|AccountingService::createInvoiceGLEntries|findAccountByPurpose|VatCollected',
            'app/Modules/Accounting/Application/Services/AccountingService.php:764|AccountingService::createCreditNoteGLEntries|findAccountByPurpose|CustomerReceivable',
            'app/Modules/Accounting/Application/Services/AccountingService.php:768|AccountingService::createCreditNoteGLEntries|findAccountByPurpose|ProductRevenue',
            'app/Modules/Accounting/Application/Services/AccountingService.php:772|AccountingService::createCreditNoteGLEntries|findAccountByPurpose|ServiceRevenue',
            'app/Modules/Accounting/Application/Services/AccountingService.php:776|AccountingService::createCreditNoteGLEntries|findAccountByPurpose|VatCollected',
            'app/Modules/Accounting/Application/Services/ChartOfAccountsService.php:103|ChartOfAccountsService::getAccountByPurpose|findByPurposeOrFail|DYNAMIC',
            'app/Modules/Accounting/Application/Services/PartnerBalanceService.php:172|PartnerBalanceService::getControlAccountBalance|findByPurposeOrFail|DYNAMIC',
            'app/Modules/Accounting/Application/Services/PartnerBalanceService.php:206|PartnerBalanceService::reconcileSubledger|findByPurposeOrFail|DYNAMIC',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1010|GeneralLedgerService::createSupplierPaymentJournalEntry|getAccountByPurpose|SupplierPayable',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1074|GeneralLedgerService::createOutboundInstrumentIssueEntry|getAccountByPurpose|SupplierPayable',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1148|GeneralLedgerService::createOutboundInstrumentCancellationEntry|getAccountByPurpose|SupplierPayable',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1256|GeneralLedgerService::createExpenseSettlementJournalEntry|getAccountByPurpose|SupplierPayable',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1349|GeneralLedgerService::createRepositoryAdjustmentJournalEntry|getAccountByPurpose|PaymentToleranceExpense',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1350|GeneralLedgerService::createRepositoryAdjustmentJournalEntry|getAccountByPurpose|PaymentToleranceIncome',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:154|GeneralLedgerService::createFromInvoice|getAccountByPurpose|CustomerReceivable',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:155|GeneralLedgerService::createFromInvoice|getAccountByPurpose|ProductRevenue',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:156|GeneralLedgerService::createFromInvoice|getAccountByPurpose|VatCollected',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1579|GeneralLedgerService::createPaymentReceivedJournalEntry|getAccountByPurpose|CustomerReceivable',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1669|GeneralLedgerService::createPaymentToleranceJournalEntry|getAccountByPurpose|CustomerReceivable',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1679|GeneralLedgerService::createPaymentToleranceJournalEntry|getAccountByPurpose|DYNAMIC',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1800|GeneralLedgerService::clearCustomerAdvanceToReceivable|getAccountByPurpose|CustomerAdvance',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1801|GeneralLedgerService::clearCustomerAdvanceToReceivable|getAccountByPurpose|CustomerReceivable',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2010|GeneralLedgerService::availableCustomerAdvance|getAccountByPurpose|CustomerAdvance',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2105|GeneralLedgerService::createGoodsReceiptGrIrEntry|findByPurposeOrFail|Inventory',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2106|GeneralLedgerService::createGoodsReceiptGrIrEntry|findByPurposeOrFail|GoodsReceivedNotInvoiced',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2259|GeneralLedgerService::createSupplierInvoiceGrIrClearingEntry|findByPurposeOrFail|GoodsReceivedNotInvoiced',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2260|GeneralLedgerService::createSupplierInvoiceGrIrClearingEntry|findByPurposeOrFail|VatDeductible',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2261|GeneralLedgerService::createSupplierInvoiceGrIrClearingEntry|findByPurposeOrFail|PurchaseStampDuty',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2262|GeneralLedgerService::createSupplierInvoiceGrIrClearingEntry|findByPurposeOrFail|Inventory',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2263|GeneralLedgerService::createSupplierInvoiceGrIrClearingEntry|findByPurposeOrFail|PurchasePriceVarianceExpense',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2264|GeneralLedgerService::createSupplierInvoiceGrIrClearingEntry|findByPurposeOrFail|PurchasePriceVarianceIncome',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2265|GeneralLedgerService::createSupplierInvoiceGrIrClearingEntry|findByPurposeOrFail|SupplierPayable',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:238|GeneralLedgerService::createFromCreditNote|getAccountByPurpose|CustomerReceivable',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:239|GeneralLedgerService::createFromCreditNote|getAccountByPurpose|ProductRevenue',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:240|GeneralLedgerService::createFromCreditNote|getAccountByPurpose|VatCollected',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2480|GeneralLedgerService::createSupplierCreditNoteEntry|findByPurposeOrFail|VatDeductible',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2481|GeneralLedgerService::createSupplierCreditNoteEntry|findByPurposeOrFail|Inventory',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2482|GeneralLedgerService::createSupplierCreditNoteEntry|findByPurposeOrFail|SupplierPayable',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2675|GeneralLedgerService::createSupplierCreditNoteEntryWithBonusReturn|findByPurposeOrFail|VatDeductible',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2676|GeneralLedgerService::createSupplierCreditNoteEntryWithBonusReturn|findByPurposeOrFail|Inventory',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2677|GeneralLedgerService::createSupplierCreditNoteEntryWithBonusReturn|findByPurposeOrFail|SupplierPayable',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2678|GeneralLedgerService::createSupplierCreditNoteEntryWithBonusReturn|findByPurposeOrFail|PurchaseExpenses',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2943|GeneralLedgerService::createVoucherLedgerEntry|getAccountByPurpose|DYNAMIC',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2944|GeneralLedgerService::createVoucherLedgerEntry|getAccountByPurpose|DYNAMIC',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:307|GeneralLedgerService::createFromCreditNote|getAccountByPurpose|PurchaseStampDuty',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:308|GeneralLedgerService::createFromCreditNote|getAccountByPurpose|SalesStampDutyPayable',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:3129|GeneralLedgerService::createInstrumentRepresentationEntry|getAccountByPurpose|CustomerReceivable',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:3641|GeneralLedgerService::b2bCancellationDebits|getAccountByPurpose|CustomerReceivable',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:3679|GeneralLedgerService::b2bCancellationDebits|getAccountByPurpose|CustomerAdvance',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:3855|GeneralLedgerService::createPOSPaymentToleranceEntry|getAccountByPurpose|PaymentToleranceExpense',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:3856|GeneralLedgerService::createPOSPaymentToleranceEntry|getAccountByPurpose|ProductRevenue',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4141|GeneralLedgerService::posRevenueAndVatLineSpecs|getAccountByPurpose|ProductRevenue',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4158|GeneralLedgerService::posRevenueAndVatLineSpecs|getAccountByPurpose|SalesDiscount',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4170|GeneralLedgerService::posRevenueAndVatLineSpecs|getAccountByPurpose|VatCollected',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4336|GeneralLedgerService::createPosCashRoundingEntry|getAccountByPurpose|ProductRevenue',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4338|GeneralLedgerService::createPosCashRoundingEntry|getAccountByPurpose|DYNAMIC',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4447|GeneralLedgerService::createRefundCompensationEntry|getAccountByPurpose|DYNAMIC',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:446|GeneralLedgerService::reclassifyCustomerPaymentToAdvance|getAccountByPurpose|CustomerReceivable',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:447|GeneralLedgerService::reclassifyCustomerPaymentToAdvance|getAccountByPurpose|CustomerAdvance',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4514|GeneralLedgerService::createPosToleranceWriteoffEntry|getAccountByPurpose|ProductRevenue',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4515|GeneralLedgerService::createPosToleranceWriteoffEntry|getAccountByPurpose|PaymentToleranceExpense',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4572|GeneralLedgerService::createPOSChargeEntry|getAccountByPurpose|CustomerReceivable',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4573|GeneralLedgerService::createPOSChargeEntry|getAccountByPurpose|ProductRevenue',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4575|GeneralLedgerService::createPOSChargeEntry|getAccountByPurpose|VatCollected',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4578|GeneralLedgerService::createPOSChargeEntry|getAccountByPurpose|SalesDiscount',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4729|GeneralLedgerService::createFromExpense|getAccountByPurpose|DYNAMIC',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4765|GeneralLedgerService::createFromExpense|getAccountByPurpose|Bank',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4766|GeneralLedgerService::createFromExpense|getAccountByPurpose|Cash',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4786|GeneralLedgerService::createFromExpense|getAccountByPurpose|Bank',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4791|GeneralLedgerService::createFromExpense|getAccountByPurpose|SupplierPayable',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4836|GeneralLedgerService::createFromExpense|getAccountByPurpose|VatDeductible',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4913|GeneralLedgerService::createFromIncome|getAccountByPurpose|ProductRevenue',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4930|GeneralLedgerService::createFromIncome|getAccountByPurpose|Bank',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:4931|GeneralLedgerService::createFromIncome|getAccountByPurpose|Cash',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:5011|GeneralLedgerService::createLinkedCostCapitalizationEntry|getAccountByPurpose|Inventory',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:5012|GeneralLedgerService::createLinkedCostCapitalizationEntry|getAccountByPurpose|CostOfGoodsSold',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:5099|GeneralLedgerService::createLinkedCostCapitalizationReversalEntry|getAccountByPurpose|Inventory',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:5100|GeneralLedgerService::createLinkedCostCapitalizationReversalEntry|getAccountByPurpose|CostOfGoodsSold',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:5168|GeneralLedgerService::expensePaymentAccount|getAccountByPurpose|Bank',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:5169|GeneralLedgerService::expensePaymentAccount|getAccountByPurpose|Cash',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:5263|GeneralLedgerService::createInventoryMovementEntry|getAccountByPurpose|Inventory',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:5264|GeneralLedgerService::createInventoryMovementEntry|getAccountByPurpose|DYNAMIC',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:532|GeneralLedgerService::createCustomerAdvanceJournalEntry|getAccountByPurpose|CustomerAdvance',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:5486|GeneralLedgerService::createInventoryWriteOffEntry|getAccountByPurpose|InventoryShrinkageExpense',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:5487|GeneralLedgerService::createInventoryWriteOffEntry|getAccountByPurpose|Inventory',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:5672|GeneralLedgerService::getAccountByPurpose|findByPurposeOrFail|DYNAMIC',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:631|GeneralLedgerService::reverseSupplierAdvanceJournalEntry|getAccountByPurpose|SupplierAdvance',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:742|GeneralLedgerService::reverseCustomerAdvanceJournalEntry|getAccountByPurpose|CustomerAdvance',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:832|GeneralLedgerService::createPaymentRefundJournalEntry|getAccountByPurpose|CustomerReceivable',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:921|GeneralLedgerService::createSupplierInvoiceJournalEntry|getAccountByPurpose|SupplierPayable',
            'app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:922|GeneralLedgerService::createSupplierInvoiceJournalEntry|getAccountByPurpose|VatDeductible',
            'app/Modules/Expense/Application/Services/ExpenseService.php:784|ExpenseService::settle|findByPurposeOrFail|Bank',
            'app/Modules/Expense/Application/Services/ExpenseService.php:785|ExpenseService::settle|findByPurposeOrFail|Cash',
            'app/Modules/Inventory/Application/Services/OpeningBalancePostingService.php:237|OpeningBalancePostingService::post|findByPurposeOrFail|Inventory',
            'app/Modules/Inventory/Application/Services/OpeningBalancePostingService.php:238|OpeningBalancePostingService::post|findByPurposeOrFail|OpeningBalanceEquity',
            'app/Modules/Inventory/Application/Services/ResetOpeningBalanceService.php:152|ResetOpeningBalanceService::reset|findByPurposeOrFail|Inventory',
            'app/Modules/Inventory/Application/Services/ResetOpeningBalanceService.php:153|ResetOpeningBalanceService::reset|findByPurposeOrFail|OpeningBalanceEquity',
            'app/Modules/Treasury/Application/Services/InstrumentLifecycleService.php:421|InstrumentLifecycleService::bounce|findByPurposeOrFail|CustomerReceivable',
            'app/Modules/Treasury/Application/Services/InstrumentLifecycleService.php:425|InstrumentLifecycleService::bounce|findByPurposeOrFail|CustomerReceivable',
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

        if ($counts !== [self::REQUIRED => 28, self::SCOPE_REQUIRED => 1, self::CONDITIONAL => 4, self::SOFT => 10]) {
            throw new LogicException('The v1 purpose partition must be exactly 28 + 1 + 4 + 10.');
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
