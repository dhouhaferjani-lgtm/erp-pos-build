<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Treasury\Domain\Enums\AllocationTreatment;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * N-6 — decide how an AR allocation must be booked, by POSTED-NESS.
 *
 * The pre-N-6 rule was `DocumentType::SalesOrder ⇒ advance, else Cr 411`
 * (`PaymentAllocationService` JE branch). It never asked whether a receivable
 * existed. A confirmed invoice is numbered and payable in the product but has
 * NO GL footprint: posting is what creates 411 / revenue / VAT. Crediting 411
 * against it produced a dangling credit — the partner's receivable went
 * negative, no VAT was ever declared, and the invoice was flipped to `Paid`,
 * a state `DocumentPostingService::post()` refuses, so it could never be
 * posted afterwards.
 *
 * THE RULE:
 *   - Invoice + Posted/Paid          → {@see AllocationTreatment::ReceivableClearing}
 *   - Invoice + Confirmed (unposted) → {@see AllocationTreatment::Prepayment} (Cr 419)
 *   - SalesOrder + Confirmed         → {@see AllocationTreatment::Prepayment} (Cr 419)
 *   - anything else                  → 422 `DOCUMENT_NOT_ALLOCATABLE`
 *
 * "Anything else" is deliberately broad and includes drafts (nothing has been
 * committed to the customer), cancelled/withdrawn documents (W-7 F-6 —
 * {@see DocumentAllocationStateGuard} covers the same ground from the state
 * side and keeps its own refusal), credit notes (money owed BY us, never
 * settled by a customer receipt on this path) and quotes.
 *
 * AR ONLY. Supplier invoices are payable through the supplier-aware
 * `PaymentController::store()` branch, which posts Dr 401 / Cr Bank and runs
 * its own posted-ness + Cr-401 evidence guard. They are refused here so no
 * caller can route AP money through an AR classification.
 */
final class DocumentAllocationClassifier
{
    /**
     * @throws HttpResponseException 422 `DOCUMENT_NOT_ALLOCATABLE`
     */
    public function classify(Document $document): AllocationTreatment
    {
        $treatment = $this->classifyOrNull($document);

        if ($treatment === null) {
            throw $this->refuse($document);
        }

        return $treatment;
    }

    /**
     * The non-throwing counterpart, for read models that must decide whether to
     * OFFER a document as payable without provoking an exception (the
     * open-documents lookup, the allocation preview).
     */
    public function classifyOrNull(Document $document): ?AllocationTreatment
    {
        return match (true) {
            $document->type === DocumentType::Invoice
                && in_array($document->status, [DocumentStatus::Posted, DocumentStatus::Paid], true)
                => AllocationTreatment::ReceivableClearing,

            $document->type === DocumentType::Invoice
                && $document->status === DocumentStatus::Confirmed
                => AllocationTreatment::Prepayment,

            $document->type === DocumentType::SalesOrder
                && $document->status === DocumentStatus::Confirmed
                => AllocationTreatment::Prepayment,

            default => null,
        };
    }

    public function isAllocatable(Document $document): bool
    {
        return $this->classifyOrNull($document) !== null;
    }

    private function refuse(Document $document): HttpResponseException
    {
        return new HttpResponseException(response()->json([
            'error' => [
                'code' => 'DOCUMENT_NOT_ALLOCATABLE',
                'message' => 'This document cannot receive a customer payment allocation in its current state.',
                'details' => [
                    'document_id' => $document->id,
                    'document_number' => $document->document_number,
                    'document_type' => $document->type->value,
                    'status' => $document->status->value,
                ],
            ],
        ], 422));
    }
}
