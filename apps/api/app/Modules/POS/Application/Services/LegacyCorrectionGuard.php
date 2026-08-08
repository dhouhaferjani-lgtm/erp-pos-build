<?php

declare(strict_types=1);

namespace App\Modules\POS\Application\Services;

use App\Modules\POS\Domain\Exceptions\LegacyCorrectionRetiredException;
use App\Modules\POS\Domain\Terminal;

/**
 * v3-refund-chain-integration spec §9.1/§9.3/§9.4 — guard called by
 * {@see ReceiptReturnService} before authoring a legacy (non-fiscal-event)
 * return.
 *
 * DPA V9 (owner ruling D3): the second caller, `ReceiptVoidService`, was
 * SUNSET — the legacy void endpoint is now a 410 tombstone. The guard's
 * contract is unchanged; only its caller set shrank to the return path.
 *
 * **Conditioned on ACKNOWLEDGEMENT, not raw schema version (§9.3).**
 * `pos_terminals.fiscal_schema_version >= 3` alone is NOT the gate —
 * D1 defaults every terminal created post-merge to schema 3 at
 * provisioning time, long before that terminal's device has ever
 * pulled/acknowledged the v4 refund-authoring capability flag. Gating on
 * raw schema version would retire the legacy path for terminals whose
 * device has never even seen the new authoring path, reproducing §1's
 * original chain-corruption failure mode with no fallback. The guard
 * fires ONLY when `v4_refund_authoring_acknowledged_at IS NOT NULL` for
 * the terminal — Phase 2 of the two-phase enable/acknowledge protocol
 * (§9.3) has actually completed for THIS terminal.
 */
final class LegacyCorrectionGuard
{
    /**
     * @throws LegacyCorrectionRetiredException When the terminal has
     *                                          acknowledged v4 refund authoring — the legacy path is retired for it.
     */
    public function assertLegacyCorrectionAllowed(Terminal $terminal): void
    {
        if ($terminal->v4_refund_authoring_acknowledged_at !== null) {
            throw new LegacyCorrectionRetiredException((string) $terminal->id);
        }
    }
}
