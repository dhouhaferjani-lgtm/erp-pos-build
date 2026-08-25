<?php

declare(strict_types=1);

namespace Tests\Unit\Treasury;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Treasury\Domain\Enums\AllocationRefusalReason;
use App\Modules\Treasury\Domain\Enums\AllocationTreatment;
use App\Modules\Treasury\Domain\Exceptions\DocumentNotAllocatableException;
use App\Modules\Treasury\Domain\Services\DocumentAllocationClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C-0a0 — the EXHAUSTIVE, DEFAULT-REFUSE payment-applicability table.
 *
 * SPEC-document-lifecycle-dimensions r11 §2.1, rules 1 and 6–9 plus the F-107
 * fail-closed sequencing rule: until the provenance COLUMNS exist (lane
 * C-PROV0 / C-0a1), a historical opening or a POS-derived Invoice/CreditNote is
 * REFUSED rather than guessed at, because this lane cannot yet tell an AR
 * opening (collection, Cr 411) from an AP opening (vendor payment, Dr 401) and
 * booking the wrong one is a wrong-direction GL entry against a real partner.
 *
 * The expected verdicts below are restated FROM THE SPEC, never read off the
 * implementation: a policy table whose test mirrors the code proves only that
 * the code equals itself.
 *
 * SUPERSEDES `DocumentAllocationClassifierMatrixTest` (N-6 r2 / treasury gate
 * R2-I3), which asserted the same object over 13x6 pairs with no provenance
 * axis and no refusal reasons. Two matrix tests over one policy table would
 * have to disagree — C-0a0 narrows three of N-6's admitted cells — so the
 * narrower one is deleted and its three NAMED cells (gate finding I-5's
 * `SalesOrder + Posted`, the N-6 edge `Invoice + Confirmed`, and
 * `Invoice + Draft`) are carried into `test_the_cells_this_lane_closes()`
 * below, with their verdicts updated and the reason for each change stated.
 *
 * Pure unit test — the classifier touches no database, so `Document` is unsaved.
 */
final class PaymentApplicabilityMatrixTest extends TestCase
{
    private const HISTORICAL_REFERENCE = 'Opening Balance Batch: FY2025 AP open items';

    private const POS_REFERENCE = 'POS-ACCOUNT-CHARGE:0198f2a1-0000-7000-8000-000000000001';

    private DocumentAllocationClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new DocumentAllocationClassifier;
    }

    /**
     * 13 `DocumentType` cases x 6 `DocumentStatus` cases x 3 provenance families.
     *
     * @return iterable<string, array{DocumentType, DocumentStatus, string}>
     */
    public static function everyCell(): iterable
    {
        foreach (DocumentType::cases() as $type) {
            foreach (DocumentStatus::cases() as $status) {
                foreach (['native', 'historical', 'pos'] as $provenance) {
                    yield "{$type->value} + {$status->value} + {$provenance}" => [$type, $status, $provenance];
                }
            }
        }
    }

    #[DataProvider('everyCell')]
    public function test_every_cell_of_the_applicability_matrix(
        DocumentType $type,
        DocumentStatus $status,
        string $provenance,
    ): void {
        $document = $this->document($type, $status, $provenance);
        $label = "{$type->value} + {$status->value} + {$provenance}";

        $expectedReason = self::expectedRefusalReason($type, $status, $provenance);
        $expectedTreatment = $expectedReason === null
            ? self::expectedTreatment($type, $status)
            : null;

        $this->assertSame(
            $expectedReason,
            $this->classifier->refusalReasonFor($document),
            "refusal reason for {$label}",
        );
        $this->assertSame(
            $expectedTreatment,
            $this->classifier->classifyOrNull($document),
            "treatment for {$label}",
        );
        $this->assertSame(
            $expectedReason === null,
            $this->classifier->isAllocatable($document),
            "allocatable for {$label}",
        );

        if ($expectedReason !== null) {
            try {
                $this->classifier->classify($document);
                $this->fail("{$label} must be refused with DocumentNotAllocatableException");
            } catch (DocumentNotAllocatableException $e) {
                $this->assertSame($expectedReason, $e->reason, "exception reason for {$label}");
            }

            return;
        }

        $this->assertSame($expectedTreatment, $this->classifier->classify($document), $label);
    }

    /**
     * Rule 9 is a DEFAULT-REFUSE: the admitted set is tiny and CLOSED — four
     * (type, status) cells out of 78, listed here in full.
     *
     * The sales-order and supplier-invoice rows repeat across all three
     * provenance families on purpose: rules 2–5 are scoped to Invoice and
     * CreditNote (the only two types the opening importer and the POS bridge
     * mint), so a provenance marker on any other type is meaningless and must
     * NOT change its verdict. Asserting that here is what stops the fail-closed
     * detector from quietly widening into types it was never meant to judge.
     */
    public function test_the_admitted_set_is_closed_and_exactly_this(): void
    {
        $admitted = [];

        foreach (DocumentType::cases() as $type) {
            foreach (DocumentStatus::cases() as $status) {
                foreach (['native', 'historical', 'pos'] as $provenance) {
                    $treatment = $this->classifier->classifyOrNull($this->document($type, $status, $provenance));
                    if ($treatment !== null) {
                        $admitted[] = "{$type->value}/{$status->value}/{$provenance}={$treatment->value}";
                    }
                }
            }
        }

        sort($admitted);

        $this->assertSame([
            'invoice/confirmed/native=prepayment',
            'invoice/posted/native=receivable_clearing',
            'sales_order/confirmed/historical=prepayment',
            'sales_order/confirmed/native=prepayment',
            'sales_order/confirmed/pos=prepayment',
            'supplier_invoice/posted/historical=payable_settlement',
            'supplier_invoice/posted/native=payable_settlement',
            'supplier_invoice/posted/pos=payable_settlement',
        ], $admitted);
    }

    /**
     * The named cells this lane exists to change. A future edit has to change a
     * named test, not a generated one.
     */
    public function test_the_cells_this_lane_closes(): void
    {
        // F-153 / LEDGER OQ-3: a purchase order allocation booked a NEGATIVE
        // customer receivable against a supplier partner. Refused throughout
        // this program.
        $this->assertSame(
            AllocationRefusalReason::PurchaseOrderWrongDirection,
            $this->classifier->refusalReasonFor(
                $this->document(DocumentType::PurchaseOrder, DocumentStatus::Confirmed, 'native'),
            ),
        );

        // F-107: an AP historical opening is minted as an Invoice. Admitting it
        // here would Dr bank / Cr 411 for a payable. Fail closed until C-0a1.
        $this->assertSame(
            AllocationRefusalReason::HistoricalOpeningProvenance,
            $this->classifier->refusalReasonFor(
                $this->document(DocumentType::Invoice, DocumentStatus::Posted, 'historical'),
            ),
        );

        // F-89: a POS account-charge invoice already carries a 411 from the POS
        // fiscal event. It is never a 419 advance — but this lane cannot tell
        // the adopted receivable apart yet, so it refuses.
        $this->assertSame(
            AllocationRefusalReason::PosDerivedProvenance,
            $this->classifier->refusalReasonFor(
                $this->document(DocumentType::Invoice, DocumentStatus::Confirmed, 'pos'),
            ),
        );

        // Spec rule 7: a sales order is a prepayment target only while
        // `confirmed`. N-6 admitted it at EVERY live status.
        $this->assertSame(
            AllocationTreatment::Prepayment,
            $this->classifier->classifyOrNull(
                $this->document(DocumentType::SalesOrder, DocumentStatus::Confirmed, 'native'),
            ),
        );
        $this->assertSame(
            AllocationRefusalReason::StatusNotAllocatableForType,
            $this->classifier->refusalReasonFor(
                $this->document(DocumentType::SalesOrder, DocumentStatus::Posted, 'native'),
            ),
        );

        // Spec rule 8: the guarded supplier path, now expressed IN the
        // classifier instead of bypassing it.
        $this->assertSame(
            AllocationTreatment::PayableSettlement,
            $this->classifier->classifyOrNull(
                $this->document(DocumentType::SupplierInvoice, DocumentStatus::Posted, 'native'),
            ),
        );

        // Rule 1 wins over every later rule: a cancelled POS invoice is refused
        // as NOT LIVE, not as POS-derived (precedence, first match wins).
        $this->assertSame(
            AllocationRefusalReason::DocumentNotLive,
            $this->classifier->refusalReasonFor(
                $this->document(DocumentType::Invoice, DocumentStatus::Cancelled, 'pos'),
            ),
        );

        // `paid` / `received` are retired lifecycle values (F-88): no new money
        // may be admitted against them. N-6 admitted `Invoice + Paid` as a
        // ReceivableClearing — a settled document taking further money.
        $this->assertSame(
            AllocationRefusalReason::DocumentNotLive,
            $this->classifier->refusalReasonFor(
                $this->document(DocumentType::Invoice, DocumentStatus::Paid, 'native'),
            ),
        );

        // Carried from the superseded N-6 matrix test, verdict UNCHANGED: the
        // N-6 edge itself. A confirmed invoice has no 411 to clear, so the money
        // is an advance (Cr 419), not a settlement.
        $this->assertSame(
            AllocationTreatment::Prepayment,
            $this->classifier->classifyOrNull(
                $this->document(DocumentType::Invoice, DocumentStatus::Confirmed, 'native'),
            ),
        );

        // Carried from the superseded N-6 matrix test, verdict UNCHANGED: a
        // draft has committed nothing to the customer.
        $this->assertNull(
            $this->classifier->classifyOrNull(
                $this->document(DocumentType::Invoice, DocumentStatus::Draft, 'native'),
            ),
        );
    }

    /**
     * A REVERSAL is the inverse operation: it unwinds money already recorded.
     * It must never fail closed, or a refund strands cash on a document this
     * lane has just stopped admitting.
     */
    public function test_reversal_admission_is_total_so_refunds_can_always_unwind(): void
    {
        foreach (DocumentType::cases() as $type) {
            foreach (DocumentStatus::cases() as $status) {
                $document = $this->document($type, $status, 'native');
                $this->classifier->assertReversalAdmitted($document);
            }
        }

        $this->addToAssertionCount(1);
    }

    /**
     * Every refusal reason carries an i18n key, and none of them is the raw
     * enum value leaking into a user-facing string.
     */
    public function test_every_refusal_reason_has_a_namespaced_translation_key(): void
    {
        foreach (AllocationRefusalReason::cases() as $reason) {
            $this->assertSame(
                'treasury.allocation_refused.'.$reason->value,
                $reason->translationKey(),
            );
        }
    }

    /**
     * Rules 1 and 6–9, restated from the spec.
     */
    private static function expectedRefusalReason(
        DocumentType $type,
        DocumentStatus $status,
        string $provenance,
    ): ?AllocationRefusalReason {
        // Rule 1 — first match wins, nothing below may shadow it.
        if (! in_array($status, [DocumentStatus::Confirmed, DocumentStatus::Posted], true)) {
            return AllocationRefusalReason::DocumentNotLive;
        }

        // F-107 sequencing — provenance fail-closed, Invoice/CreditNote only
        // (they are the two types the opening importer and the POS bridge mint).
        if ($type === DocumentType::Invoice || $type === DocumentType::CreditNote) {
            if ($provenance === 'historical') {
                return AllocationRefusalReason::HistoricalOpeningProvenance;
            }
            if ($provenance === 'pos') {
                return AllocationRefusalReason::PosDerivedProvenance;
            }
        }

        return match ($type) {
            // Rule 6.
            DocumentType::Invoice => null,
            // Rule 7.
            DocumentType::SalesOrder => $status === DocumentStatus::Confirmed
                ? null
                : AllocationRefusalReason::StatusNotAllocatableForType,
            // Rule 8.
            DocumentType::SupplierInvoice => $status === DocumentStatus::Posted
                ? null
                : AllocationRefusalReason::StatusNotAllocatableForType,
            // Rule 9 — outward money: settled by application or refund, never
            // by an inbound allocation.
            DocumentType::CreditNote,
            DocumentType::SupplierCreditNote => AllocationRefusalReason::OutwardDocumentType,
            // Rule 9 — F-153.
            DocumentType::PurchaseOrder => AllocationRefusalReason::PurchaseOrderWrongDirection,
            // Rule 9 — types that carry no partner balance at all.
            DocumentType::Quote,
            DocumentType::PurchaseQuoteRequest,
            DocumentType::DeliveryNote,
            DocumentType::ReturnNote,
            DocumentType::CorrectingEntry,
            DocumentType::Expense,
            DocumentType::Income => AllocationRefusalReason::TypeNeverAllocatable,
        };
    }

    private static function expectedTreatment(DocumentType $type, DocumentStatus $status): AllocationTreatment
    {
        return match (true) {
            $type === DocumentType::Invoice && $status === DocumentStatus::Posted => AllocationTreatment::ReceivableClearing,
            $type === DocumentType::Invoice => AllocationTreatment::Prepayment,
            $type === DocumentType::SalesOrder => AllocationTreatment::Prepayment,
            $type === DocumentType::SupplierInvoice => AllocationTreatment::PayableSettlement,
            default => throw new \LogicException("no admitted treatment for {$type->value} + {$status->value}"),
        };
    }

    private function document(DocumentType $type, DocumentStatus $status, string $provenance): Document
    {
        $document = new Document;
        $document->id = "doc-{$type->value}-{$status->value}-{$provenance}";
        $document->document_number = strtoupper($type->value).'-1';
        $document->type = $type;
        $document->status = $status;
        $document->is_historical = false;
        $document->reference = null;

        if ($provenance === 'historical') {
            $document->is_historical = true;
            $document->reference = self::HISTORICAL_REFERENCE;
        }

        if ($provenance === 'pos') {
            $document->reference = self::POS_REFERENCE;
        }

        return $document;
    }
}
