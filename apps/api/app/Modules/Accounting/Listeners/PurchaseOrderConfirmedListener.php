<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Listeners;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Accounting\Domain\Services\GeneralLedgerService;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Events\PurchaseOrderConfirmed;
use App\Modules\Identity\Domain\User;

final class PurchaseOrderConfirmedListener
{
    public function __construct(
        private readonly GeneralLedgerService $glService,
    ) {}

    public function handle(PurchaseOrderConfirmed $event): void
    {
        if ($this->alreadyPosted($event->companyId, $event->purchaseOrderId)) {
            return;
        }

        /** @var Document|null $purchaseOrder */
        $purchaseOrder = Document::query()
            ->where('company_id', $event->companyId)
            ->where('type', DocumentType::PurchaseOrder)
            ->find($event->purchaseOrderId);

        if ($purchaseOrder === null) {
            return;
        }

        /** @var User|null $user */
        $user = User::query()
            ->where('tenant_id', $event->tenantId)
            ->find($event->confirmedBy);

        if ($user === null) {
            return;
        }

        $purchaseExpenseAccount = Account::findByPurposeOrFail($event->companyId, SystemAccountPurpose::PurchaseExpenses);

        $this->glService->createSupplierInvoiceJournalEntry(
            companyId: $event->companyId,
            partnerId: $event->partnerId,
            invoiceId: $event->purchaseOrderId,
            totalAmount: $purchaseOrder->total ?? '0.00',
            netAmount: $purchaseOrder->subtotal ?? '0.00',
            vatAmount: $purchaseOrder->tax_amount ?? '0.00',
            expenseAccountId: $purchaseExpenseAccount->id,
            date: $purchaseOrder->document_date,
            user: $user,
            description: "Supplier invoice - {$event->documentNumber}",
            currencyCode: $event->currency
        );
    }

    private function alreadyPosted(string $companyId, string $purchaseOrderId): bool
    {
        return JournalEntry::query()
            ->where('company_id', $companyId)
            ->where('source_type', 'supplier_invoice')
            ->where('source_id', $purchaseOrderId)
            ->exists();
    }
}
