<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Listeners;

use App\Modules\Accounting\Application\Services\AccountingService;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Events\InvoicePosted;

final class InvoicePostedListener
{
    public function __construct(
        private readonly AccountingService $accountingService,
    ) {}

    public function handle(InvoicePosted $event): void
    {
        // Load document from database
        $document = Document::find($event->invoiceId);

        if ($document === null) {
            return;
        }

        // Create GL entries based on document type
        // The InvoicePosted event is dispatched for both Invoice and CreditNote types
        if ($document->type === DocumentType::CreditNote) {
            $this->accountingService->createCreditNoteGLEntries($document);
        } else {
            $this->accountingService->createInvoiceGLEntries($document);
        }
    }
}
