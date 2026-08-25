<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Services;

use App\Modules\Fiscal\Application\DTOs\FiscalEventEnvelope;
use App\Modules\Fiscal\Domain\Enums\FiscalEventType;
use Illuminate\Database\ConnectionInterface;

/**
 * D-1 forward-only version gate (owner ruling 2026-08-25).
 *
 * ## The rule
 *
 * The cutover to the post-remise VAT base is FORWARD-ONLY. A SALE_RECEIPT
 * authored at `event_version <= 3` seals the taxable base on the PRE-discount
 * gross — wrong money on an immutable document — but those receipts must keep
 * being accepted, because a device still on an older build has no other shape
 * to author and its sales are real. What must NEVER be accepted is the OLD
 * shape from a device that already HAS the new build.
 *
 * ## Why the terminal watermark, and not an app-version string
 *
 * The canonical SALE_RECEIPT payload carries no application version, and adding
 * one would change the sealed bytes of every receipt (rule 8 — a versioned
 * change, not an edit). `devices.app_version` exists but is user-scoped and set
 * at login; it has no reliable link to the terminal that authored a chain, and
 * a device that never re-logs-in never refreshes it.
 *
 * The chain itself already records what we actually need: the version this
 * terminal has proven it can author. Once a terminal has sealed ANY SALE_RECEIPT
 * at `event_version >= 5`, that terminal is on the new build, and every later
 * SALE_RECEIPT from it at `<= 3` is a DOWNGRADE — a rolled-back build, a
 * replayed spoof, or a bug. The watermark is monotonic per terminal chain, it
 * is derived from data the device signed rather than a header it asserts, and
 * it needs no new field in the canonical bytes.
 *
 * v4 (REFUND) is exempt: it is a sibling fan-out of v3, not a predecessor of
 * v5, and the refund path is gated separately.
 *
 * ## Disposition
 *
 * A refusal is returned as a parse-failure reason, so the envelope follows the
 * SAME path every other payload-contract violation follows: the event is
 * STORED with its canonical bytes intact and NOT projected (quarantined in
 * table). Nothing is silently dropped and nothing is rewritten.
 */
final class SaleReceiptForwardVersionGate
{
    public function __construct(
        private readonly ConnectionInterface $db,
    ) {}

    /**
     * @return string|null a parse-failure reason, or null when the envelope is admissible
     */
    public function verdict(FiscalEventEnvelope $envelope): ?string
    {
        if ($envelope->eventType !== FiscalEventType::SALE_RECEIPT) {
            return null;
        }

        $version = $envelope->eventVersion;
        $threshold = FiscalPayloadConstraintValidator::SALE_RECEIPT_POST_DISCOUNT_BASE_VERSION;
        // v4 is the REFUND fan-out off v3, not a pre-D-1 SALE shape.
        if ($version >= $threshold || $version === 4) {
            return null;
        }

        // Gate r1 finding 3 — scoped to the CHAIN, not just the terminal.
        //
        // The device drains its outbox `ORDER BY chain_context ASC,
        // sequence_number ASC` (`fiscalEventRepository.ts:117-118`), and
        // `'operational'` sorts before `'training_operational'`. On a terminal
        // that takes the D-1 build with unsynced training sales still queued,
        // the post-upgrade operational v5 receipts drain FIRST and set the
        // watermark; the pre-upgrade training v3 receipts drain next and would
        // be refused as downgrades — stored, never projected, so no
        // `pos_receipts` row, no GL entry and (stock is authored only in
        // `PosCoreReceiptProjection`) no stock movement, for real sales.
        //
        // Within one chain the ordering is already safe: v3 stragglers carry
        // lower sequence numbers and drain first. Per-chain is therefore the
        // grain the whole watermark argument is made on, and scoping to it
        // does not weaken the gate — each chain is its own monotone sequence.
        //
        // Gate r1 finding 5 — an EXISTENCE probe, not `MAX()`. The gate only
        // ever compares against the threshold, so aggregating the whole chain
        // was work thrown away; `exists()` short-circuits on the first hit and
        // is served by the partial index added in
        // `2026_08_25_090200_index_sale_receipt_v5_watermark_d1`. This runs on
        // the ingest hot path for EVERY pre-v5 receipt from every
        // not-yet-upgraded terminal — i.e. all of them until the build ships.
        $watermarked = $this->db->table('fiscal_events')
            ->where('tenant_id', $envelope->tenantId)
            ->where('company_id', $envelope->companyId)
            ->where('terminal_id', $envelope->terminalId)
            ->where('chain_context', $envelope->chainContext)
            ->where('event_type', FiscalEventType::SALE_RECEIPT->value)
            ->where('event_version', '>=', $threshold)
            ->exists();

        if (! $watermarked) {
            return null;
        }

        return sprintf(
            'sale_receipt_version_downgrade:terminal=%s:chain_context=%s:event_version=%d:'
            .'a chain that has authored the post-remise VAT base (v%d) may never author the '
            .'pre-discount base again',
            $envelope->terminalId,
            $envelope->chainContext,
            $version,
            $threshold,
        );
    }
}
