<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Exceptions;

/**
 * Marker interface — v3-refund-chain-integration spec §4.2/§17.
 *
 * `ApplyFiscalEventProjectionJob`'s generic `catch (Throwable $e)` block
 * treats every projection failure as retryable (advances `attempts`,
 * re-throws, Horizon retries with backoff up to `$tries = 5`). Some
 * failures are NEVER retryable — the underlying condition cannot resolve
 * itself on a later attempt (the event is intrinsically invalid, not
 * transiently blocked on a missing dependency). Implementing this marker
 * routes the exception through the job's dedicated
 * `catch (NonRetryableProjectionException $e)` block instead, which
 * dead-letters immediately via `$this->fail($e)` rather than exhausting
 * all 5 retry attempts first.
 *
 * Implemented by {@see RefundQuantityExceededException} (§12) and
 * {@see ApprovalEvidenceUnresolvedException} (§4.2).
 */
interface NonRetryableProjectionException {}
