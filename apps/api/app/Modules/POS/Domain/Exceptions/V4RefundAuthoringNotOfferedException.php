<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain\Exceptions;

use App\Modules\POS\Application\Services\LegacyCorrectionGuard;
use RuntimeException;

/**
 * v3-refund-chain-integration spec §9.3 — Phase 2 acknowledges Phase 1.
 *
 * A device cannot acknowledge a capability the server never offered:
 * acknowledgement is what activates {@see LegacyCorrectionGuard}
 * and therefore RETIRES the legacy correction path for the terminal. Letting
 * an unoffered terminal acknowledge would hand a client the ability to close
 * a server-owned rollout gate on itself — and, worse, to strand itself with
 * neither correction path usable if its own capability flag is false.
 */
final class V4RefundAuthoringNotOfferedException extends RuntimeException
{
    public function __construct(public readonly string $terminalId)
    {
        parent::__construct(
            "Terminal {$terminalId} has not been offered v4 refund authoring (pos_terminals.v4_refund_authoring_enabled is false). Phase 1 (server offer) must complete before Phase 2 (device acknowledgement)."
        );
    }

    public function code(): string
    {
        return 'V4_REFUND_AUTHORING_NOT_OFFERED';
    }
}
