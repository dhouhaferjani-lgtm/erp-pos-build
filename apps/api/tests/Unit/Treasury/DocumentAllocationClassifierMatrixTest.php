<?php

declare(strict_types=1);

namespace Tests\Unit\Treasury;

use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Treasury\Domain\Enums\AllocationTreatment;
use App\Modules\Treasury\Domain\Exceptions\DocumentNotAllocatableException;
use App\Modules\Treasury\Domain\Services\DocumentAllocationClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * N-6 fix round r2 / treasury gate R2-I3 — the WHOLE policy table.
 *
 * `DocumentAllocationClassifier` is the single policy object for the entire AR
 * allocation surface: every payment path (smart, direct, multi-line, excess,
 * split, deposit, close-with-tolerance) asks it, and the r2 rewrite changed the
 * outcome of roughly fifty (type, status) pairs from `ReceivableClearing` to a
 * refusal. Until now it had no direct test at all — the only refusal coverage
 * was two cases in a feature suite.
 *
 * A policy table nobody asserts drifts on the next edit, and the whole point of
 * I-5 was that an unruled pair must not be silently decided. So this asserts
 * EVERY pair, generated from the enums rather than hand-listed, and the expected
 * value is derived from the four stated rules — not from the implementation.
 *
 * A pure unit test: the classifier touches no database, so `Document` is used
 * unsaved.
 */
final class DocumentAllocationClassifierMatrixTest extends TestCase
{
    private DocumentAllocationClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new DocumentAllocationClassifier;
    }

    /**
     * Every (type, status) pair there is.
     *
     * @return iterable<string, array{DocumentType, DocumentStatus}>
     */
    public static function everyPair(): iterable
    {
        foreach (DocumentType::cases() as $type) {
            foreach (DocumentStatus::cases() as $status) {
                yield "{$type->value} + {$status->value}" => [$type, $status];
            }
        }
    }

    #[DataProvider('everyPair')]
    public function test_the_whole_matrix(DocumentType $type, DocumentStatus $status): void
    {
        $document = $this->document($type, $status);
        $expected = self::expectedTreatment($type, $status);

        $this->assertSame(
            $expected,
            $this->classifier->classifyOrNull($document),
            "{$type->value} + {$status->value}",
        );
        $this->assertSame($expected !== null, $this->classifier->isAllocatable($document));

        if ($expected === null) {
            $this->expectException(DocumentNotAllocatableException::class);
            $this->classifier->classify($document);

            return;
        }

        $this->assertSame($expected, $this->classifier->classify($document));
    }

    /**
     * The four rules, restated independently of the implementation.
     */
    private static function expectedTreatment(DocumentType $type, DocumentStatus $status): ?AllocationTreatment
    {
        // Refusals first — unconditional, nothing may shadow them.
        if (in_array($status, [DocumentStatus::Draft, DocumentStatus::Cancelled], true)) {
            return null;
        }

        if ($type === DocumentType::CreditNote || $type === DocumentType::SupplierInvoice) {
            return null;
        }

        if ($type === DocumentType::Invoice) {
            return match ($status) {
                DocumentStatus::Posted, DocumentStatus::Paid => AllocationTreatment::ReceivableClearing,
                DocumentStatus::Confirmed => AllocationTreatment::Prepayment,
                default => null,
            };
        }

        // A sales order is ALWAYS an advance, at every live status — the pre-N-6
        // rule was a pure type test, and anything narrower is the I-5 regression.
        if ($type === DocumentType::SalesOrder) {
            return AllocationTreatment::Prepayment;
        }

        // The explicitly-wrong legacy row (handback R-1, purchases lane).
        if ($type === DocumentType::PurchaseOrder) {
            return AllocationTreatment::ReceivableClearing;
        }

        return null;
    }

    /**
     * The three pairs the gate singled out, asserted by name so a future edit
     * has to change a named test rather than a generated one.
     */
    public function test_the_pairs_that_cost_a_finding(): void
    {
        // I-5: the fall-through used to flip this to Cr 411 — N-6 on a new pair.
        $this->assertSame(
            AllocationTreatment::Prepayment,
            $this->classifier->classifyOrNull($this->document(DocumentType::SalesOrder, DocumentStatus::Posted)),
        );

        // THE N-6 EDGE itself.
        $this->assertSame(
            AllocationTreatment::Prepayment,
            $this->classifier->classifyOrNull($this->document(DocumentType::Invoice, DocumentStatus::Confirmed)),
        );

        // A draft has committed nothing to the customer.
        $this->assertNull(
            $this->classifier->classifyOrNull($this->document(DocumentType::Invoice, DocumentStatus::Draft)),
        );
    }

    public function test_no_pair_is_left_undecided_by_accident(): void
    {
        $decided = 0;
        $refused = 0;

        foreach (DocumentType::cases() as $type) {
            foreach (DocumentStatus::cases() as $status) {
                $this->classifier->classifyOrNull($this->document($type, $status)) === null
                    ? $refused++
                    : $decided++;
            }
        }

        // Both buckets must be non-empty: an all-refusing classifier would break
        // every payment path, and an all-deciding one is the fall-through I-5
        // removed.
        $this->assertGreaterThan(0, $decided);
        $this->assertGreaterThan(0, $refused);
        $this->assertSame(
            count(DocumentType::cases()) * count(DocumentStatus::cases()),
            $decided + $refused,
        );
    }

    private function document(DocumentType $type, DocumentStatus $status): Document
    {
        $document = new Document;
        $document->id = 'doc-'.$type->value.'-'.$status->value;
        $document->document_number = strtoupper($type->value).'-1';
        $document->type = $type;
        $document->status = $status;

        return $document;
    }
}
