<?php

declare(strict_types=1);

namespace Tests\Architecture\DocumentPerActionFixtures;

use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Inventory\Domain\StockMovement;
use App\Shared\Domain\Enums\StockMovementReferenceType;
use Illuminate\Support\Facades\DB;

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
    private ?string $pendingSourceId = null;

    /**
     * Assigns the nullable property so its declared type is honest — the
     * fixture's point is that the DECLARED nullability is what the guard reads.
     */
    public function rememberSourceId(string $sourceId): void
    {
        $this->pendingSourceId = $sourceId;
    }

    public function __construct(
        private readonly string $documentId = '',
    ) {}

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
     * A local variable assigned `null` in the same function is null-admitting,
     * however it is spelled at the call site (M1 gate round 2, finding 1).
     */
    public function journalEntryWithNullAssignedLocal(): void
    {
        $sourceType = null;
        $sourceId = null;

        JournalEntry::create([
            'entry_number' => 'JE-P3',
            'source_type' => $sourceType,
            'source_id' => $sourceId,
        ]);
    }

    /**
     * A nullable PROPERTY on $this is null-admitting.
     */
    public function journalEntryWithNullableProperty(string $sourceType): void
    {
        JournalEntry::create([
            'entry_number' => 'JE-P4',
            'source_type' => $sourceType,
            'source_id' => $this->pendingSourceId,
        ]);
    }

    /**
     * A nullable-returning method on $this is null-admitting.
     */
    public function movementWithNullableReturnLinkage(string $productId): void
    {
        StockMovement::create([
            'product_id' => $productId,
            'quantity' => '1.0000',
            'reference_type' => StockMovementReferenceType::Document,
            'reference_id' => $this->maybeReferenceId(),
        ]);
    }

    /**
     * An array element is never statically decidable.
     *
     * @param  array<string, string|null>  $context
     */
    public function movementWithArrayElementLinkage(string $productId, array $context): void
    {
        StockMovement::create([
            'product_id' => $productId,
            'quantity' => '1.0000',
            'reference_type' => StockMovementReferenceType::Document,
            'reference_id' => $context['document_id'],
        ]);
    }

    /**
     * A non-nullable promoted property IS credited.
     */
    public function journalEntryWithNonNullableProperty(string $sourceType): void
    {
        JournalEntry::create([
            'entry_number' => 'JE-P5',
            'source_type' => $sourceType,
            'source_id' => $this->documentId,
        ]);
    }

    // --- unreadable MUTATE payloads (round 2, finding 2) -------------------

    /**
     * `update($vars)` may set the linkage columns to anything, so it must not
     * be the way around the erasure rule.
     *
     * @param  array<string, mixed>  $payload
     */
    public function journalEntryUpdateWithUnreadablePayload(string $entryId, array $payload): void
    {
        JournalEntry::query()->whereKey($entryId)->update($payload);
    }

    /**
     * The array-union form demonstrably nulls linkage while reading as
     * unresolvable.
     *
     * @param  array<string, mixed>  $extra
     */
    public function journalEntryUpdateWithArrayUnionPayload(string $entryId, array $extra): void
    {
        JournalEntry::query()->whereKey($entryId)->update(['source_id' => null] + $extra);
    }

    /**
     * Erasure through a null-admitting expression rather than a literal null.
     */
    public function journalEntrySaveErasesViaNullableCall(string $entryId): void
    {
        $entry = JournalEntry::query()->findOrFail($entryId);
        $entry->source_id = $this->maybeReferenceId();
        $entry->save();
    }

    // --- alias laundering, refused (round 3, finding 1) --------------------

    /**
     * One alias assignment used to launder every refused shape back into
     * "linked". Null-admittance now propagates through local aliases.
     */
    public function journalEntryWithAliasedNullableCall(string $sourceType): void
    {
        $sourceId = $this->maybeReferenceId();

        JournalEntry::create([
            'entry_number' => 'JE-P6',
            'source_type' => $sourceType,
            'source_id' => $sourceId,
        ]);
    }

    /**
     * Two hops: `$a = null; $b = $a;`.
     */
    public function journalEntryWithDoubleAliasedNull(string $sourceType): void
    {
        $first = null;
        $second = $first;

        JournalEntry::create([
            'entry_number' => 'JE-P7',
            'source_type' => $sourceType,
            'source_id' => $second,
        ]);
    }

    /**
     * Aliased from a nullable PARAMETER — the round-1 refusal must survive the
     * hop too.
     */
    public function movementWithAliasedNullableParameter(string $productId, ?string $referenceId = null): void
    {
        $resolved = $referenceId;

        StockMovement::create([
            'product_id' => $productId,
            'quantity' => '1.0000',
            'reference_type' => StockMovementReferenceType::Document,
            'reference_id' => $resolved,
        ]);
    }

    // --- updateOrInsert is a CREATE (round 3, finding 2) --------------------

    /**
     * `updateOrInsert` INSERTS when nothing matches, so an unlinked payload
     * creates an unjustified row.
     */
    public function journalEntryUpdateOrInsertUnlinked(string $entryNumber): void
    {
        JournalEntry::query()->updateOrInsert(
            ['entry_number' => $entryNumber],
            ['status' => 'posted', 'entry_date' => '2026-01-01'],
        );
    }

    public function journalEntryUpdateOrInsertLinked(string $entryNumber, string $documentId): void
    {
        JournalEntry::query()->updateOrInsert(
            ['entry_number' => $entryNumber],
            ['source_type' => 'document', 'source_id' => $documentId],
        );
    }

    /**
     * The same reclassification through the query-builder mechanism.
     */
    public function builderUpdateOrInsertUnlinked(string $entryNumber): void
    {
        DB::table('journal_entries')->updateOrInsert(
            ['entry_number' => $entryNumber],
            ['status' => 'posted'],
        );
    }

    private function maybeReferenceId(): ?string
    {
        return $this->pendingSourceId;
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
