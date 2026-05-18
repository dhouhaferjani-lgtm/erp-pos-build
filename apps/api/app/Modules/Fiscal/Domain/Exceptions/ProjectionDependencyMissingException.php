<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown by a `FiscalEventProjector::apply()` implementation when a
 * cross-projector dependency row that the projector needs to write its
 * effects is NOT YET visible (e.g., `TreasuryReceiptBridge` cannot find the
 * `pos_receipts` row that `PosCoreReceiptProjection` is responsible for
 * writing for the same fiscal event).
 *
 * **Closure of Task 23 round-2 — Codex T23-B2 BLOCKER.** Round-1's
 * `TreasuryReceiptBridge` returned cleanly (`Log::warning + return`) when the
 * dependency was missing; `ApplyFiscalEventProjectionJob` then marked the
 * projection row `applied` because the projector reported success. Under
 * multiple Horizon workers, Task 22's `priority()`-based dispatch order
 * only constrains the ENQUEUE order, not the EXECUTION order — so a
 * Treasury worker could reserve and run its job before the POS-core worker
 * committed `pos_receipts`. The bridge returned clean, the row flipped to
 * `applied`, and Treasury's Payment + GL writes never happened.
 *
 * Throwing this exception flips that failure mode to a retryable contract:
 * the job's fail-closed `catch (Throwable)` advances `attempts`
 * accounting + re-throws → Horizon retries with backoff → POS-core's job
 * lands first → the bridge's next attempt sees the receipt → succeeds.
 *
 * **Why `RuntimeException` and not a custom checked-exception base.** The
 * Task 23 job catches `Throwable` (line 278) and treats every projector
 * exception as a retryable failure: advance attempt accounting and
 * re-throw for Horizon retry. Extending `RuntimeException` makes the
 * exception cleanly catchable through the existing path without changing
 * the catch signature. The class name itself is the load-bearing signal
 * — review tools / log scrapers / dashboards can distinguish
 * "dependency not yet available" from "projector hard error" by class.
 *
 * **Not a hard misconfiguration.** A missing FiscalEvent or a missing
 * projector is a hard misconfiguration (operator restored a broken
 * registry between ingest and run) and goes through
 * `ApplyFiscalEventProjectionJob::recordHardFailure` + `Log::critical`.
 * A missing dependency row is RETRYABLE — the dependency will land in a
 * later attempt of THE SAME EVENT's sibling projection job. Do not log
 * `critical`; the job's normal failure-accounting path emits the right
 * structured log already.
 */
final class ProjectionDependencyMissingException extends RuntimeException
{
    public function __construct(
        public readonly string $projectorName,
        public readonly string $fiscalEventId,
        public readonly string $missingDependency,
    ) {
        parent::__construct(sprintf(
            'Projector "%s" cannot apply fiscal_event %s: dependency "%s" not yet visible. '.
            'This is a RETRYABLE condition — Horizon will retry per the job backoff schedule '.
            'until the sibling projector commits the dependency row or $tries is exhausted.',
            $projectorName,
            $fiscalEventId,
            $missingDependency,
        ));
    }
}
