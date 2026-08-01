<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown by `PosCoreReceiptProjection::assertOriginalNotTraining()`
 * (§3.7) when a v4 REFUND targets an original sale whose OWN signed
 * `training_flag` is `true` — the device-side refusal (§3.7) should have
 * blocked this before authoring; this is defense-in-depth for an event
 * that somehow bypassed it.
 *
 * **NonRetryableProjectionException (review round-2 IMPORTANT 16).** A
 * training original's `training_flag` is permanently fixed at signing
 * time — no amount of Horizon retrying (up to `$tries = 5` with backoff,
 * burning roughly 21 minutes before this codebase's round-2 review found
 * it) makes the same signed refund event's target stop being a training
 * receipt. Dead-letters immediately via
 * `ApplyFiscalEventProjectionJob`'s `catch (NonRetryableProjectionException $e)`
 * branch (§4.2) instead.
 */
final class TrainingOriginalRefundRefusedException extends RuntimeException implements NonRetryableProjectionException
{
    public function __construct(
        public readonly string $fiscalEventId,
        public readonly string $originalFiscalEventId,
    ) {
        parent::__construct(sprintf(
            'PosCoreReceiptProjection: refund fiscal_event=%s targets a TRAINING original '.
            '(original fiscal_event=%s) — the device-side refusal (spec §3.7) should have '.
            'blocked this before authoring; refusing server-side as defense-in-depth.',
            $fiscalEventId,
            $originalFiscalEventId,
        ));
    }
}
