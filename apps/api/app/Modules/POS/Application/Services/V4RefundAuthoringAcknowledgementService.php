<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Modules\POS\Domain\Exceptions\V4RefundAuthoringNotOfferedException;
use App\Modules\POS\Domain\Terminal;
use Illuminate\Support\Facades\Log;

/**
 * v3-refund-chain-integration spec §9.3 Phase 2 — records the device's
 * acknowledgement of the server's v4-refund-authoring offer.
 *
 * Stamping `pos_terminals.v4_refund_authoring_acknowledged_at` is the SOLE
 * trigger that activates {@see LegacyCorrectionGuard} for a terminal, i.e.
 * that retires the legacy `/return` + `/void` correction path for it. It is
 * therefore treated as a one-way, once-only rollout event:
 *
 *  - it REQUIRES Phase 1 to have happened (`v4_refund_authoring_enabled`);
 *  - it is IDEMPOTENT and never moves an existing timestamp — the device
 *    re-acknowledges on every successful pull while `enabled` stays true,
 *    and the rollout audit trail must record when the terminal FIRST
 *    acknowledged, not when it last synced.
 */
final class V4RefundAuthoringAcknowledgementService
{
    /**
     * @throws V4RefundAuthoringNotOfferedException When Phase 1 has not happened for this terminal.
     */
    public function acknowledge(Terminal $terminal): void
    {
        if ($terminal->v4_refund_authoring_enabled !== true) {
            throw new V4RefundAuthoringNotOfferedException((string) $terminal->id);
        }

        if ($terminal->v4_refund_authoring_acknowledged_at !== null) {
            return;
        }

        $terminal->forceFill(['v4_refund_authoring_acknowledged_at' => now()])->save();

        // §9.5 step 4 of the rollout is marked complete off this signal —
        // log it at info so the launch runbook has a server-side record of
        // the exact moment the legacy correction path was retired for this
        // terminal.
        Log::info('[fiscal] v4 refund authoring ACKNOWLEDGED — legacy correction path retired for terminal', [
            'terminal_id' => $terminal->id,
            'company_id' => $terminal->company_id,
            'acknowledged_at' => $terminal->v4_refund_authoring_acknowledged_at?->toISOString(),
        ]);
    }
}
