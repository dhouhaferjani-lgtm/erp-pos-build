<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Domain\Exceptions;

use App\Modules\Workshop\WorkOrder\Application\Services\WorkOrderTransitionService;
use App\Modules\Workshop\WorkOrder\Presentation\Controllers\WorkOrderTransitionController;
use DomainException;

/**
 * Raised when a caller attempts to transition a WorkOrder into `Invoiced`
 * while it has zero line items. Invoicing an empty WO would produce a
 * fiscally-posted Document with `total = 0.000` and zero DocumentLines —
 * a compliance hazard. The guard lives in
 * {@see WorkOrderTransitionService}
 * and is checked before any side-effect (invoice generation, hash-chain
 * write, event dispatch) runs.
 *
 * Maps to HTTP 422 at the API boundary with the envelope
 * `{ "error": { "code": "WORK_ORDER_NO_LINES", "message": "..." } }` —
 * see {@see WorkOrderTransitionController}.
 *
 * Audit finding 🔴-6a,
 * `docs/sessions/2026-04-21-autospecs-ops-audit.md`.
 */
final class WorkOrderNoLinesException extends DomainException
{
    public static function forWorkOrder(string $workOrderId): self
    {
        return new self(
            "WorkOrder {$workOrderId} cannot be invoiced because it has zero line items.",
        );
    }
}
