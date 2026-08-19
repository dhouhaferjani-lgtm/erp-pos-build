<?php

declare(strict_types=1);

namespace Tests\Architecture\DocumentPerActionFixtures;

use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Inventory\Domain\StockMovement;
use App\Shared\Domain\Enums\StockMovementReferenceType;

/**
 * Fixtures for what actually counts as PROVEN linkage (M1 gate finding 4).
 *
 * Writing `'source_id' => $id` proves nothing when `$id` is `?string $id = null`
 * — the row can be written with a null reference on every call. This is not
 * academic: it is the exact shape of the S0 chokepoint
 * (`StockAdjustmentService::recordMovement(?StockMovementReferenceType
 * $referenceType = null, ?string $referenceId = null)` writing
 * `'reference_type' => $referenceType?->value`), and `assertReferenceLinkagePaired`
 * explicitly permits null/null. Crediting that as linkage would make the guard
 * near-vacuous on the path almost every movement takes.
 *
 * PARSED, never executed.
 */
final class FixtureLinkageProofWrites
{
    /**
     * Non-nullable parameters: linkage is proven at every call site.
     */
    public function journalEntryWithProvenLinkage(string $sourceType, string $sourceId): void
    {
        JournalEntry::create([
            'entry_number' => 'JE-P1',
            'source_type' => $sourceType,
            'source_id' => $sourceId,
        ]);
    }

    /**
     * Nullable parameters: the keys are present but the values may be null on
     * any call — NOT proven linkage.
     */
    public function journalEntryWithNullableLinkage(?string $sourceType = null, ?string $sourceId = null): void
    {
        JournalEntry::create([
            'entry_number' => 'JE-P2',
            'source_type' => $sourceType,
            'source_id' => $sourceId,
        ]);
    }

    /**
     * The chokepoint's own shape: a nullsafe read off a nullable enum.
     */
    public function movementWithNullsafeLinkage(
        string $productId,
        ?StockMovementReferenceType $referenceType = null,
        ?string $referenceId = null,
    ): void {
        StockMovement::create([
            'product_id' => $productId,
            'quantity' => '1.0000',
            'reference_type' => $referenceType?->value,
            'reference_id' => $referenceId,
        ]);
    }

    /**
     * A null-coalesced fallback to a non-nullable value IS proven.
     */
    public function movementWithCoalescedLinkage(string $productId, ?string $referenceId, string $fallbackId): void
    {
        StockMovement::create([
            'product_id' => $productId,
            'quantity' => '1.0000',
            'reference_type' => StockMovementReferenceType::Document,
            'reference_id' => $referenceId ?? $fallbackId,
        ]);
    }
}
