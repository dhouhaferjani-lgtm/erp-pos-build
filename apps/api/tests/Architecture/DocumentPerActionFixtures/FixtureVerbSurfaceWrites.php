<?php

declare(strict_types=1);

namespace Tests\Architecture\DocumentPerActionFixtures;

use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Inventory\Domain\StockMovement;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * EXACT per-verb pins for the write vocabulary (final gate round 10 / Codex #3).
 *
 * The mechanism × table matrix pins BUCKETS. Codex's point was that a bucket
 * can be fully covered while an individual API in it is unrecognised — the
 * seven `*OrFail` / `*Quietly` / `forceDestroy` verbs were absent from the map
 * entirely, and four already-recognised verbs (`upsert`, `updateQuietly`,
 * `insertOrIgnore`, `forceDelete`) had no fixture of their own. These pin the
 * APIs, not the buckets.
 *
 * Receivers are MODEL INSTANCES, not builder chains: `updateOrFail`,
 * `deleteOrFail`, `forceDeleteQuietly`, `restoreQuietly` and `updateQuietly`
 * are Eloquent MODEL methods and do not exist on the query builder — PHPStan
 * caught the first draft of this fixture asserting otherwise, which would have
 * pinned a shape that cannot occur.
 *
 * PARSED, never executed.
 */
final class FixtureVerbSurfaceWrites
{
    // --- verbs that were MISSING from the map entirely ---------------------

    public function saveOrFailOnFreshEntry(string $companyId): void
    {
        $entry = new JournalEntry;
        $entry->company_id = $companyId;
        $entry->saveOrFail();
    }

    public function updateOrFailErasesLinkage(string $entryId): void
    {
        $entry = JournalEntry::query()->findOrFail($entryId);
        $entry->updateOrFail(['source_id' => null]);
    }

    public function deleteOrFailMovement(string $movementId): void
    {
        $movement = StockMovement::query()->findOrFail($movementId);
        $movement->deleteOrFail();
    }

    public function pushQuietlyOnFreshEntry(string $companyId): void
    {
        $entry = new JournalEntry;
        $entry->company_id = $companyId;
        $entry->pushQuietly();
    }

    /**
     * `forceDeleteQuietly()` and `restoreQuietly()` exist only on a model using
     * SoftDeletes, and NONE of the four contract models does. Pinning them on
     * `StockMovement` directly would assert a shape that cannot compile — PHPStan
     * caught exactly that. They are pinned on a soft-deletable SUBCLASS instead,
     * which is both type-correct and the realistic way the verbs could ever
     * appear against a contract table (the scanner resolves it through the
     * `extends` chain).
     */
    public function forceDeleteQuietlyMovement(FixtureSoftDeletableMovement $movement): void
    {
        $movement->forceDeleteQuietly();
    }

    public function forceDestroyMovement(string $movementId): void
    {
        StockMovement::forceDestroy($movementId);
    }

    public function restoreQuietlyMovement(FixtureSoftDeletableMovement $movement): void
    {
        $movement->restoreQuietly();
    }

    // --- verbs recognised but never pinned by an exact fixture -------------

    public function upsertMovements(string $productId): void
    {
        StockMovement::upsert(
            [['product_id' => $productId, 'quantity' => '1.0000']],
            ['product_id'],
        );
    }

    public function updateQuietlyErasesLinkage(string $entryId): void
    {
        $entry = JournalEntry::query()->findOrFail($entryId);
        $entry->updateQuietly(['source_type' => null]);
    }

    public function insertOrIgnoreUnlinkedMovement(string $productId): void
    {
        StockMovement::insertOrIgnore([
            'product_id' => $productId,
            'quantity' => '1.0000',
        ]);
    }

    public function forceDeleteMovement(string $movementId): void
    {
        $movement = StockMovement::query()->findOrFail($movementId);
        $movement->forceDelete();
    }
}

/**
 * A soft-deletable subclass of a contract model — the only way
 * `forceDeleteQuietly()` / `restoreQuietly()` can legally be called against
 * `stock_movements`. The scanner resolves it to that table through `extends`.
 */
final class FixtureSoftDeletableMovement extends StockMovement
{
    use SoftDeletes;
}
