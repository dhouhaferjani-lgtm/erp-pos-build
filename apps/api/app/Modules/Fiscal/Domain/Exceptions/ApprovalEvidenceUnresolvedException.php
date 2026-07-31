<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown when a projector cannot resolve/verify the operator-approval
 * evidence a signed event references (§4.2's seven-field evidence
 * verification, unchanged from Revision 3 — Codex r3 confirmed it closes
 * the round-2 recovery-key concern).
 *
 * **NonRetryableProjectionException (§4.2 closure, §17).** Revision 3
 * declared this "non-retryable" in prose without changing
 * `ApplyFiscalEventProjectionJob` to actually honor it — its only
 * `catch (Throwable $e)` block advanced failure accounting and re-threw
 * regardless of exception type, so Horizon retried up to `$tries = 5`
 * before dead-lettering. Implementing this marker routes the exception
 * through the job's dedicated `catch (NonRetryableProjectionException $e)`
 * branch instead: unresolved/tampered approval evidence can never resolve
 * itself on a later attempt, so it dead-letters immediately.
 */
final class ApprovalEvidenceUnresolvedException extends RuntimeException implements NonRetryableProjectionException
{
    public function __construct(
        public readonly string $fiscalEventId,
        public readonly string $approvalEventId,
        string $reason,
    ) {
        parent::__construct(sprintf(
            'ApprovalEvidenceUnresolved: fiscal_event=%s approval_event=%s reason=%s',
            $fiscalEventId,
            $approvalEventId,
            $reason,
        ));
    }
}
