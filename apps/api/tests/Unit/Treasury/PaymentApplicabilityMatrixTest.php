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
use App\Shared\Contracts\Accounting\HistoricalOpeningSide;
use App\Shared\Contracts\Accounting\HistoricalOpeningSideReaderInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C-0a0 — the EXHAUSTIVE, DEFAULT-REFUSE payment-applicability table.
 *
 * SPEC-document-lifecycle-dimensions r11 §2.1, rules 1 and 6–9, with the F-107
 * sequencing rule as narrowed by gate r1 F-2: fail closed where the evidence is
 * genuinely absent (the AP side of a historical opening, or a side that cannot
 * be proven), not blanket over a family whose discriminator already exists.
 *
 * THE ORACLE IS A TABLE, NOT A FUNCTION (gate r1 / F-8). r1 wrote the expected
 * verdicts as an `expectedRefusalReason()` method that reproduced the production
 * `refusalReasonFor()` arm for arm, in the same order, with the same comment
 * markers. The gate was right that "restated from the spec" is authorship intent
 * an artefact cannot evidence: any future edit gets applied to both in one pass,
 * and the test agrees with the code by construction. So every cell is now a
 * LITERAL, and there is no shared control flow left to co-evolve — changing a
 * verdict means editing a specific line that names the cell it decides.
 *
 * SUPERSEDES `DocumentAllocationClassifierMatrixTest` (N-6 r2 / treasury gate
 * R2-I3), whose three NAMED cells are carried into
 * `test_the_cells_this_lane_closes()` with each verdict change stated.
 *
 * Pure unit test — the only collaborator is the opening-side reader, supplied
 * here as an explicit in-test map so `Document` can stay unsaved.
 */
final class PaymentApplicabilityMatrixTest extends TestCase
{
    private const HISTORICAL_REFERENCE = 'Opening Balance Batch: FY2025 open items';

    private const POS_REFERENCE = 'POS-ACCOUNT-CHARGE:0198f2a1-0000-7000-8000-000000000001';

    /**
     * NATIVE provenance — every `DocumentType` x every `DocumentStatus`.
     * `admit:<treatment>` or `refused:<reason>`, one literal per cell.
     *
     * @var array<string, string>
     */
    private const EXPECTED_NATIVE = [
        'quote|draft' => 'refused:document_not_live',
        'quote|confirmed' => 'refused:type_never_allocatable',
        'quote|posted' => 'refused:type_never_allocatable',
        'quote|paid' => 'refused:document_not_live',
        'quote|received' => 'refused:document_not_live',
        'quote|cancelled' => 'refused:document_not_live',
        'sales_order|draft' => 'refused:document_not_live',
        'sales_order|confirmed' => 'admit:prepayment',
        'sales_order|posted' => 'admit:prepayment',
        'sales_order|paid' => 'refused:document_not_live',
        'sales_order|received' => 'refused:document_not_live',
        'sales_order|cancelled' => 'refused:document_not_live',
        'purchase_order|draft' => 'refused:document_not_live',
        'purchase_order|confirmed' => 'refused:purchase_order_wrong_direction',
        'purchase_order|posted' => 'refused:purchase_order_wrong_direction',
        'purchase_order|paid' => 'refused:document_not_live',
        'purchase_order|received' => 'refused:document_not_live',
        'purchase_order|cancelled' => 'refused:document_not_live',
        'invoice|draft' => 'refused:document_not_live',
        'invoice|confirmed' => 'admit:prepayment',
        'invoice|posted' => 'admit:receivable_clearing',
        'invoice|paid' => 'refused:document_not_live',
        'invoice|received' => 'refused:document_not_live',
        'invoice|cancelled' => 'refused:document_not_live',
        'credit_note|draft' => 'refused:document_not_live',
        'credit_note|confirmed' => 'refused:outward_document_type',
        'credit_note|posted' => 'refused:outward_document_type',
        'credit_note|paid' => 'refused:document_not_live',
        'credit_note|received' => 'refused:document_not_live',
        'credit_note|cancelled' => 'refused:document_not_live',
        'delivery_note|draft' => 'refused:document_not_live',
        'delivery_note|confirmed' => 'refused:type_never_allocatable',
        'delivery_note|posted' => 'refused:type_never_allocatable',
        'delivery_note|paid' => 'refused:document_not_live',
        'delivery_note|received' => 'refused:document_not_live',
        'delivery_note|cancelled' => 'refused:document_not_live',
        'return_note|draft' => 'refused:document_not_live',
        'return_note|confirmed' => 'refused:type_never_allocatable',
        'return_note|posted' => 'refused:type_never_allocatable',
        'return_note|paid' => 'refused:document_not_live',
        'return_note|received' => 'refused:document_not_live',
        'return_note|cancelled' => 'refused:document_not_live',
        'expense|draft' => 'refused:document_not_live',
        'expense|confirmed' => 'refused:type_never_allocatable',
        'expense|posted' => 'refused:type_never_allocatable',
        'expense|paid' => 'refused:document_not_live',
        'expense|received' => 'refused:document_not_live',
        'expense|cancelled' => 'refused:document_not_live',
        'supplier_invoice|draft' => 'refused:document_not_live',
        'supplier_invoice|confirmed' => 'refused:status_not_allocatable_for_type',
        'supplier_invoice|posted' => 'admit:payable_settlement',
        'supplier_invoice|paid' => 'refused:document_not_live',
        'supplier_invoice|received' => 'refused:document_not_live',
        'supplier_invoice|cancelled' => 'refused:document_not_live',
        'supplier_credit_note|draft' => 'refused:document_not_live',
        'supplier_credit_note|confirmed' => 'refused:outward_document_type',
        'supplier_credit_note|posted' => 'refused:outward_document_type',
        'supplier_credit_note|paid' => 'refused:document_not_live',
        'supplier_credit_note|received' => 'refused:document_not_live',
        'supplier_credit_note|cancelled' => 'refused:document_not_live',
        'income|draft' => 'refused:document_not_live',
        'income|confirmed' => 'refused:type_never_allocatable',
        'income|posted' => 'refused:type_never_allocatable',
        'income|paid' => 'refused:document_not_live',
        'income|received' => 'refused:document_not_live',
        'income|cancelled' => 'refused:document_not_live',
        'purchase_rfq|draft' => 'refused:document_not_live',
        'purchase_rfq|confirmed' => 'refused:type_never_allocatable',
        'purchase_rfq|posted' => 'refused:type_never_allocatable',
        'purchase_rfq|paid' => 'refused:document_not_live',
        'purchase_rfq|received' => 'refused:document_not_live',
        'purchase_rfq|cancelled' => 'refused:document_not_live',
        'correcting_entry|draft' => 'refused:document_not_live',
        'correcting_entry|confirmed' => 'refused:type_never_allocatable',
        'correcting_entry|posted' => 'refused:type_never_allocatable',
        'correcting_entry|paid' => 'refused:document_not_live',
        'correcting_entry|received' => 'refused:document_not_live',
        'correcting_entry|cancelled' => 'refused:document_not_live',
    ];

    /**
     * NON-NATIVE provenance. Only Invoice and CreditNote are ever minted by the
     * opening importer or the POS account-charge bridge, so only they have a
     * provenance axis; `test_provenance_markers_do_not_change_any_other_type`
     * asserts exactly that for the other eleven types instead of restating 264
     * duplicate cells here.
     *
     * @var array<string, string>
     */
    private const EXPECTED_PROVENANCE = [
        'invoice|historical_ar|draft' => 'refused:document_not_live',
        'invoice|historical_ar|confirmed' => 'refused:status_not_allocatable_for_type',
        'invoice|historical_ar|posted' => 'admit:receivable_clearing',
        'invoice|historical_ar|paid' => 'refused:document_not_live',
        'invoice|historical_ar|received' => 'refused:document_not_live',
        'invoice|historical_ar|cancelled' => 'refused:document_not_live',
        'invoice|historical_ap|draft' => 'refused:document_not_live',
        'invoice|historical_ap|confirmed' => 'refused:historical_opening_provenance',
        'invoice|historical_ap|posted' => 'refused:historical_opening_provenance',
        'invoice|historical_ap|paid' => 'refused:document_not_live',
        'invoice|historical_ap|received' => 'refused:document_not_live',
        'invoice|historical_ap|cancelled' => 'refused:document_not_live',
        'invoice|historical_unknown|draft' => 'refused:document_not_live',
        'invoice|historical_unknown|confirmed' => 'refused:historical_opening_provenance',
        'invoice|historical_unknown|posted' => 'refused:historical_opening_provenance',
        'invoice|historical_unknown|paid' => 'refused:document_not_live',
        'invoice|historical_unknown|received' => 'refused:document_not_live',
        'invoice|historical_unknown|cancelled' => 'refused:document_not_live',
        'invoice|pos|draft' => 'refused:document_not_live',
        'invoice|pos|confirmed' => 'refused:pos_derived_provenance',
        'invoice|pos|posted' => 'refused:pos_derived_provenance',
        'invoice|pos|paid' => 'refused:document_not_live',
        'invoice|pos|received' => 'refused:document_not_live',
        'invoice|pos|cancelled' => 'refused:document_not_live',
        'credit_note|historical_ar|draft' => 'refused:document_not_live',
        'credit_note|historical_ar|confirmed' => 'refused:historical_opening_provenance',
        'credit_note|historical_ar|posted' => 'refused:historical_opening_provenance',
        'credit_note|historical_ar|paid' => 'refused:document_not_live',
        'credit_note|historical_ar|received' => 'refused:document_not_live',
        'credit_note|historical_ar|cancelled' => 'refused:document_not_live',
        'credit_note|historical_ap|draft' => 'refused:document_not_live',
        'credit_note|historical_ap|confirmed' => 'refused:historical_opening_provenance',
        'credit_note|historical_ap|posted' => 'refused:historical_opening_provenance',
        'credit_note|historical_ap|paid' => 'refused:document_not_live',
        'credit_note|historical_ap|received' => 'refused:document_not_live',
        'credit_note|historical_ap|cancelled' => 'refused:document_not_live',
        'credit_note|historical_unknown|draft' => 'refused:document_not_live',
        'credit_note|historical_unknown|confirmed' => 'refused:historical_opening_provenance',
        'credit_note|historical_unknown|posted' => 'refused:historical_opening_provenance',
        'credit_note|historical_unknown|paid' => 'refused:document_not_live',
        'credit_note|historical_unknown|received' => 'refused:document_not_live',
        'credit_note|historical_unknown|cancelled' => 'refused:document_not_live',
        'credit_note|pos|draft' => 'refused:document_not_live',
        'credit_note|pos|confirmed' => 'refused:pos_derived_provenance',
        'credit_note|pos|posted' => 'refused:pos_derived_provenance',
        'credit_note|pos|paid' => 'refused:document_not_live',
        'credit_note|pos|received' => 'refused:document_not_live',
        'credit_note|pos|cancelled' => 'refused:document_not_live',
    ];

    /**
     * @var list<string>
     */
    private const PROVENANCE_FAMILIES = ['historical_ar', 'historical_ap', 'historical_unknown', 'pos'];

    private DocumentAllocationClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new DocumentAllocationClassifier($this->openingSideReader());
    }

    /**
     * @return iterable<string, array{DocumentType, DocumentStatus, string}>
     */
    public static function everyCell(): iterable
    {
        foreach (DocumentType::cases() as $type) {
            foreach (DocumentStatus::cases() as $status) {
                yield "{$type->value} + {$status->value} + native" => [$type, $status, 'native'];
            }
        }

        foreach ([DocumentType::Invoice, DocumentType::CreditNote] as $type) {
            foreach (self::PROVENANCE_FAMILIES as $family) {
                foreach (DocumentStatus::cases() as $status) {
                    yield "{$type->value} + {$status->value} + {$family}" => [$type, $status, $family];
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
        $expected = $this->expectedFor($type, $status, $provenance);

        [$expectedReason, $expectedTreatment] = $this->parse($expected);

        $this->assertSame($expectedReason, $this->classifier->refusalReasonFor($document), "refusal reason for {$label}");
        $this->assertSame($expectedTreatment, $this->classifier->classifyOrNull($document), "treatment for {$label}");
        $this->assertSame($expectedReason === null, $this->classifier->isAllocatable($document), "allocatable for {$label}");

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
     * The tables must cover the whole space and nothing outside it — a missing
     * cell has to be a failure, not a silently skipped assertion.
     */
    public function test_the_tables_cover_exactly_the_whole_space(): void
    {
        $this->assertCount(
            count(DocumentType::cases()) * count(DocumentStatus::cases()),
            self::EXPECTED_NATIVE,
        );
        $this->assertCount(
            2 * count(self::PROVENANCE_FAMILIES) * count(DocumentStatus::cases()),
            self::EXPECTED_PROVENANCE,
        );

        foreach (DocumentType::cases() as $type) {
            foreach (DocumentStatus::cases() as $status) {
                $this->assertArrayHasKey("{$type->value}|{$status->value}", self::EXPECTED_NATIVE);
            }
        }
    }

    /**
     * Rules 2–5 are scoped to the two types the opening importer and the POS
     * bridge mint. A provenance marker on anything else is meaningless and must
     * not change its verdict — which is what stops the fail-closed detector from
     * quietly widening into types it was never meant to judge.
     */
    public function test_provenance_markers_do_not_change_any_other_type(): void
    {
        foreach (DocumentType::cases() as $type) {
            if ($type === DocumentType::Invoice || $type === DocumentType::CreditNote) {
                continue;
            }

            foreach (DocumentStatus::cases() as $status) {
                $native = $this->classifier->refusalReasonFor($this->document($type, $status, 'native'));

                foreach (self::PROVENANCE_FAMILIES as $family) {
                    $this->assertSame(
                        $native,
                        $this->classifier->refusalReasonFor($this->document($type, $status, $family)),
                        "{$type->value} + {$status->value} must be provenance-invariant ({$family})",
                    );
                }
            }
        }
    }

    /**
     * Rule 9 is a DEFAULT-REFUSE: the admitted set is tiny and CLOSED. Eight
     * literal strings, no shared control flow with anything — the gate named
     * this the primary guard, so it is stated in full.
     */
    public function test_the_admitted_set_is_closed_and_exactly_this(): void
    {
        $admitted = [];

        foreach (DocumentType::cases() as $type) {
            foreach (DocumentStatus::cases() as $status) {
                foreach (array_merge(['native'], self::PROVENANCE_FAMILIES) as $family) {
                    $treatment = $this->classifier->classifyOrNull($this->document($type, $status, $family));
                    if ($treatment !== null) {
                        $admitted[] = "{$type->value}/{$status->value}/{$family}={$treatment->value}";
                    }
                }
            }
        }

        sort($admitted);

        $this->assertSame([
            'invoice/confirmed/native=prepayment',
            'invoice/posted/historical_ar=receivable_clearing',
            'invoice/posted/native=receivable_clearing',
            'sales_order/confirmed/historical_ap=prepayment',
            'sales_order/confirmed/historical_ar=prepayment',
            'sales_order/confirmed/historical_unknown=prepayment',
            'sales_order/confirmed/native=prepayment',
            'sales_order/confirmed/pos=prepayment',
            'sales_order/posted/historical_ap=prepayment',
            'sales_order/posted/historical_ar=prepayment',
            'sales_order/posted/historical_unknown=prepayment',
            'sales_order/posted/native=prepayment',
            'sales_order/posted/pos=prepayment',
            'supplier_invoice/posted/historical_ap=payable_settlement',
            'supplier_invoice/posted/historical_ar=payable_settlement',
            'supplier_invoice/posted/historical_unknown=payable_settlement',
            'supplier_invoice/posted/native=payable_settlement',
            'supplier_invoice/posted/pos=payable_settlement',
        ], $admitted);
    }

    /**
     * The named cells this lane closes.
     */
    public function test_the_cells_this_lane_closes(): void
    {
        // F-153 / LEDGER OQ-3: a purchase order allocation booked a NEGATIVE
        // customer receivable against a supplier partner.
        $this->assertSame(
            AllocationRefusalReason::PurchaseOrderWrongDirection,
            $this->classifier->refusalReasonFor($this->document(DocumentType::PurchaseOrder, DocumentStatus::Confirmed, 'native')),
        );

        // F-107 / gate r1 F-2: the AP side of an opening is the cell that books
        // Dr bank / Cr 411 for a PAYABLE. It is refused; the AR side is not.
        $this->assertSame(
            AllocationRefusalReason::HistoricalOpeningProvenance,
            $this->classifier->refusalReasonFor($this->document(DocumentType::Invoice, DocumentStatus::Posted, 'historical_ap')),
        );
        $this->assertSame(
            AllocationTreatment::ReceivableClearing,
            $this->classifier->classifyOrNull($this->document(DocumentType::Invoice, DocumentStatus::Posted, 'historical_ar')),
        );
        // No provable side ⇒ refuse. Fail closed belongs where the evidence is
        // absent, not where nobody looked.
        $this->assertSame(
            AllocationRefusalReason::HistoricalOpeningProvenance,
            $this->classifier->refusalReasonFor($this->document(DocumentType::Invoice, DocumentStatus::Posted, 'historical_unknown')),
        );

        // F-89: a POS account-charge invoice already carries a 411 from the POS
        // fiscal event. Refused until C-0a1 can prove the adopted receivable.
        $this->assertSame(
            AllocationRefusalReason::PosDerivedProvenance,
            $this->classifier->refusalReasonFor($this->document(DocumentType::Invoice, DocumentStatus::Confirmed, 'pos')),
        );

        // Gate r1 F-9 — the N-6 gate's ruling restored: `Posted` IS reachable for
        // a sales order (`DocumentPostingService::cancelSalesOrder()` guards for
        // exactly that state), and narrowing below the pre-N-6 rule is a
        // regression, not a fix. Both live statuses take an ADVANCE.
        $this->assertSame(
            AllocationTreatment::Prepayment,
            $this->classifier->classifyOrNull($this->document(DocumentType::SalesOrder, DocumentStatus::Confirmed, 'native')),
        );
        $this->assertSame(
            AllocationTreatment::Prepayment,
            $this->classifier->classifyOrNull($this->document(DocumentType::SalesOrder, DocumentStatus::Posted, 'native')),
        );

        // Spec rule 8: the guarded supplier path, expressed IN the classifier
        // instead of bypassing it.
        $this->assertSame(
            AllocationTreatment::PayableSettlement,
            $this->classifier->classifyOrNull($this->document(DocumentType::SupplierInvoice, DocumentStatus::Posted, 'native')),
        );

        // Rule 1 wins over every later rule: a cancelled POS invoice is refused
        // as NOT LIVE, not as POS-derived (precedence, first match wins).
        $this->assertSame(
            AllocationRefusalReason::DocumentNotLive,
            $this->classifier->refusalReasonFor($this->document(DocumentType::Invoice, DocumentStatus::Cancelled, 'pos')),
        );

        // `paid` / `received` are retired lifecycle values (F-88). N-6 admitted
        // `Invoice + Paid` as a ReceivableClearing — a settled document taking
        // further money.
        $this->assertSame(
            AllocationRefusalReason::DocumentNotLive,
            $this->classifier->refusalReasonFor($this->document(DocumentType::Invoice, DocumentStatus::Paid, 'native')),
        );

        // Carried from the superseded N-6 matrix test, verdict UNCHANGED: the
        // N-6 edge itself — a confirmed invoice has no 411 to clear.
        $this->assertSame(
            AllocationTreatment::Prepayment,
            $this->classifier->classifyOrNull($this->document(DocumentType::Invoice, DocumentStatus::Confirmed, 'native')),
        );

        // Carried from the superseded N-6 matrix test, verdict UNCHANGED: a
        // draft has committed nothing to the customer.
        $this->assertNull(
            $this->classifier->classifyOrNull($this->document(DocumentType::Invoice, DocumentStatus::Draft, 'native')),
        );
    }

    /**
     * Gate r1 / F-3 — `classifyReceivableSide()` guards 8 of the 12 writers and
     * had no test at all. It is the ONLY thing standing between an AR-only
     * writer and a payable settlement once the older hand-written type guards
     * are retired (R-C0a0-5).
     */
    public function test_the_receivable_side_seam_refuses_a_payable_settlement(): void
    {
        $supplierInvoice = $this->document(DocumentType::SupplierInvoice, DocumentStatus::Posted, 'native');

        // It IS allocatable — just not here. The distinction is the whole point.
        $this->assertSame(
            AllocationTreatment::PayableSettlement,
            $this->classifier->classify($supplierInvoice),
        );

        try {
            $this->classifier->classifyReceivableSide($supplierInvoice);
            $this->fail('classifyReceivableSide() must refuse a payable settlement');
        } catch (DocumentNotAllocatableException $e) {
            // Gate r1 / F-4 — NOT `outward_document_type`: a payable is inward
            // money on the wrong path, and that reason's operator copy talks
            // about credit notes.
            $this->assertSame(AllocationRefusalReason::PayableNotSettleableHere, $e->reason);
        }
    }

    /**
     * @return iterable<string, array{DocumentType, DocumentStatus, AllocationTreatment}>
     */
    public static function receivableSideAdmissions(): iterable
    {
        yield 'posted invoice clears the receivable' => [DocumentType::Invoice, DocumentStatus::Posted, AllocationTreatment::ReceivableClearing];
        yield 'confirmed invoice takes an advance' => [DocumentType::Invoice, DocumentStatus::Confirmed, AllocationTreatment::Prepayment];
        yield 'confirmed sales order takes an advance' => [DocumentType::SalesOrder, DocumentStatus::Confirmed, AllocationTreatment::Prepayment];
        yield 'posted sales order takes an advance' => [DocumentType::SalesOrder, DocumentStatus::Posted, AllocationTreatment::Prepayment];
    }

    #[DataProvider('receivableSideAdmissions')]
    public function test_the_receivable_side_seam_admits_the_ar_documents(
        DocumentType $type,
        DocumentStatus $status,
        AllocationTreatment $expected,
    ): void {
        $this->assertSame(
            $expected,
            $this->classifier->classifyReceivableSide($this->document($type, $status, 'native')),
        );
    }

    public function test_the_receivable_side_seam_still_refuses_what_the_matrix_refuses(): void
    {
        try {
            $this->classifier->classifyReceivableSide(
                $this->document(DocumentType::PurchaseOrder, DocumentStatus::Confirmed, 'native'),
            );
            $this->fail('a purchase order must not pass the receivable-side seam');
        } catch (DocumentNotAllocatableException $e) {
            $this->assertSame(AllocationRefusalReason::PurchaseOrderWrongDirection, $e->reason);
        }
    }

    /**
     * A REVERSAL is the inverse operation: it unwinds money already recorded and
     * must never fail closed, or a refund strands cash on a document this lane
     * has just stopped admitting. Seam present, predicate deferred to C-0a1.
     */
    public function test_reversal_admission_is_total_so_refunds_can_always_unwind(): void
    {
        foreach (DocumentType::cases() as $type) {
            foreach (DocumentStatus::cases() as $status) {
                $this->classifier->assertReversalAdmitted("doc-{$type->value}-{$status->value}");
            }
        }

        $this->addToAssertionCount(1);
    }

    public function test_every_refusal_reason_has_a_namespaced_translation_key(): void
    {
        foreach (AllocationRefusalReason::cases() as $reason) {
            $this->assertSame('treasury.allocation_refused.'.$reason->value, $reason->translationKey());
        }
    }

    /**
     * Every reason is renderable in every locale the namespace exists in. A
     * refusal whose key resolves to the raw dotted path is the rule-11 failure
     * the enum was built to avoid, and it only shows up in production.
     *
     * The lang files are plain PHP arrays, so a pure unit test can read them
     * directly without booting the framework.
     *
     * @return iterable<string, array{string}>
     */
    public static function locales(): iterable
    {
        yield 'en' => ['en'];
        yield 'fr' => ['fr'];
        yield 'ar' => ['ar'];
    }

    #[DataProvider('locales')]
    public function test_every_refusal_reason_is_translated_in_every_locale(string $locale): void
    {
        $translations = require dirname(__DIR__, 3)."/lang/{$locale}/treasury.php";

        $this->assertIsArray($translations);
        $this->assertArrayHasKey('allocation_refused', $translations, $locale);
        $this->assertIsArray($translations['allocation_refused']);

        foreach (AllocationRefusalReason::cases() as $reason) {
            $this->assertArrayHasKey(
                $reason->value,
                $translations['allocation_refused'],
                "{$locale} is missing a message for {$reason->value}",
            );
            $this->assertNotSame(
                '',
                trim((string) $translations['allocation_refused'][$reason->value]),
                "{$locale} has an empty message for {$reason->value}",
            );
        }

        $this->assertSame(
            count(AllocationRefusalReason::cases()),
            count($translations['allocation_refused']),
            "{$locale} carries an allocation_refused key with no matching enum case",
        );
    }

    /**
     * Gate r2 / G-3 — the AP-opening refusal must not send an operator down a
     * route that does not exist.
     *
     * An AP opening is minted as `DocumentType::Invoice`
     * (`ArApOpeningService::postBatch()`), and the supplier branch of
     * `PaymentController::store()` is gated on
     * `$document->type === DocumentType::SupplierInvoice`. So "settle it through
     * the supplier payment flow" — which is what this string said after the r1
     * fix round — is an instruction that fails. Settling AP openings arrives with
     * the provenance lane (C-0a1); until then the copy has to say so.
     */
    public function test_the_ap_opening_refusal_does_not_name_a_route_that_does_not_exist(): void
    {
        $english = require dirname(__DIR__, 3).'/lang/en/treasury.php';
        $message = (string) $english['allocation_refused'][AllocationRefusalReason::HistoricalOpeningProvenance->value];

        $this->assertStringNotContainsStringIgnoringCase(
            'supplier payment flow',
            $message,
            'AP opening balances cannot be paid through the supplier payment flow until C-0a1 lands',
        );
        $this->assertStringContainsStringIgnoringCase('cannot be settled yet', $message);
    }

    /**
     * @return array{0: ?AllocationRefusalReason, 1: ?AllocationTreatment}
     */
    private function parse(string $expected): array
    {
        [$kind, $value] = explode(':', $expected, 2);

        return $kind === 'admit'
            ? [null, AllocationTreatment::from($value)]
            : [AllocationRefusalReason::from($value), null];
    }

    private function expectedFor(DocumentType $type, DocumentStatus $status, string $provenance): string
    {
        if ($provenance === 'native') {
            return self::EXPECTED_NATIVE["{$type->value}|{$status->value}"];
        }

        return self::EXPECTED_PROVENANCE["{$type->value}|{$provenance}|{$status->value}"];
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

        if (str_starts_with($provenance, 'historical')) {
            $document->is_historical = true;
            $document->reference = self::HISTORICAL_REFERENCE;
        }

        if ($provenance === 'pos') {
            $document->reference = self::POS_REFERENCE;
        }

        return $document;
    }

    /**
     * The opening-side evidence, as an explicit map keyed on the ids
     * `document()` mints. `historical_unknown` is deliberately absent — that is
     * the "no posted import row behind this document" case the production reader
     * answers with `null`.
     */
    private function openingSideReader(): HistoricalOpeningSideReaderInterface
    {
        return new class implements HistoricalOpeningSideReaderInterface
        {
            public function sideFor(string $documentId): ?HistoricalOpeningSide
            {
                if (str_ends_with($documentId, '-historical_ar')) {
                    return HistoricalOpeningSide::AccountsReceivable;
                }

                if (str_ends_with($documentId, '-historical_ap')) {
                    return HistoricalOpeningSide::AccountsPayable;
                }

                return null;
            }
        };
    }
}
