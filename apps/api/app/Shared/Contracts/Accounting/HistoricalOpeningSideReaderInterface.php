<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Accounting;

/**
 * Resolve the AR/AP side of a historical opening-balance document (C-0a0 / F-2).
 *
 * Module boundaries (rule 6): Treasury's `DocumentAllocationClassifier` depends
 * on THIS contract, never on `OpeningBalanceImportRow`, `OpeningBalanceBatch` or
 * any other Accounting model.
 *
 * The evidence is durable and predates this lane:
 * `OpeningBalanceBatchService::addImportRows()` stamps every row's `row_type`
 * from `OpeningBatchType::rowType()` (`AR` / `AP`) at insert, and
 * `markRowsPosted()` links the posted row to the document it minted through
 * `mapped_entity_id`. Neither is rewritten afterwards.
 */
interface HistoricalOpeningSideReaderInterface
{
    /**
     * @return HistoricalOpeningSide|null `null` when the document has no posted
     *                                    AR/AP opening row behind it — the side
     *                                    is UNPROVEN, and the caller must fail
     *                                    closed rather than assume a direction.
     */
    public function sideFor(string $documentId): ?HistoricalOpeningSide;
}
