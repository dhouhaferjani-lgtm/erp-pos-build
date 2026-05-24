<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Exceptions;

/**
 * Thrown by `PosCoreReceiptProjection::apply()` when a REFUND or VOID
 * canonical SALE_RECEIPT carries an `original_receipt_reference` whose
 * `fiscal_event_id` does NOT resolve to a local `pos_receipts` row.
 *
 * **Closure of Pass 2A.PHP.2 R2 — Codex BLOCKER-2.** Round-1's
 * `resolveReceiptType()` silently fell through to `ReceiptType::Sale` when
 * the projector could not resolve the original receipt locally. `NF525`
 * export then treats the row as a sale through `Nf525DataProvider::mapSaleReceipt`
 * — a fiscal-compliance violation (NF525 §11.2-§11.4 + spec v7 §14 require
 * voided and return receipts to surface as the correct movement type).
 *
 * **Why it extends `ProjectionDependencyMissingException`.** The
 * unresolvable original is structurally a missing-dependency case: the
 * original receipt MUST exist in `pos_receipts` (synced from the device,
 * projected by `PosCoreReceiptProjection` for the original SALE_RECEIPT)
 * before the REFUND/VOID projection can apply. The retry contract is
 * identical: throw → `ApplyFiscalEventProjectionJob` catches `Throwable`
 * → advances attempts → Horizon retries with backoff → the original lands
 * → next attempt succeeds.
 *
 * **Forensic context.** The exception carries the failing canonical
 * `event_type`, the unresolvable upstream `fiscal_event_id`, the
 * `original_receipt_uuid` from the sealed payload, and the tenant scope.
 * Operators triaging via Horizon dashboards see the original receipt's
 * device-side identifier and can confirm whether the sync backlog is the
 * cause.
 *
 * **Standing pattern carry-forward.** Same standing pattern as Task 22 R2
 * `ProjectionDependencyMissingException` for the Treasury bridge → POS-core
 * dependency case. Subclass here lets log scrapers distinguish "missing
 * original receipt for refund/void" from the broader "missing sibling row"
 * surface while preserving retry semantics.
 */
final class OriginalReceiptUnresolvableException extends ProjectionDependencyMissingException
{
    public function __construct(
        string $projectorName,
        string $fiscalEventId,
        public readonly string $eventType,
        public readonly string $upstreamFiscalEventId,
        public readonly string $originalReceiptUuid,
        public readonly string $tenantId,
    ) {
        parent::__construct(
            projectorName: $projectorName,
            fiscalEventId: $fiscalEventId,
            missingDependency: sprintf(
                'original pos_receipts row for %s event '.
                '(upstream_fiscal_event_id=%s, original_receipt_uuid=%s, tenant_id=%s) '.
                '— device must sync the original SALE_RECEIPT before this %s can project. '.
                'Horizon will retry per the job backoff schedule.',
                $eventType,
                $upstreamFiscalEventId,
                $originalReceiptUuid,
                $tenantId,
                $eventType,
            ),
        );
    }
}
