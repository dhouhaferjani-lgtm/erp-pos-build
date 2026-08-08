<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\DTOs;

use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementReasonCode;
use App\Shared\Contracts\Treasury\RepositoryAdjustmentServiceInterface;
use Carbon\CarbonImmutable;

/**
 * Intent to post ONE repository (cash) adjustment: the justifying
 * `repository_adjustments` document + its 658/758 journal entry + the cash
 * movement, cross-linked, in one transaction.
 *
 * Consumed by {@see RepositoryAdjustmentServiceInterface::post()}.
 *
 * `$amount` is the RAW, un-normalized caller amount (positive magnitude). The
 * service rounds it ONCE, at its own boundary, to the target repository's
 * currency scale (CLAUDE.md rule 19) and feeds that single normalized string to
 * the document, the journal entry and the movement — never the caller.
 */
final readonly class RepositoryAdjustmentIntent
{
    /**
     * @param  string  $repositoryId  UUID of the target PaymentRepository (tenant+company scoped by the service)
     * @param  string  $amount  positive decimal magnitude; direction carries the sign
     * @param  string  $userId  UUID of the acting user — resolved to a User by the service so a
     *                          listener/queue caller need not carry a hydrated model
     * @param  string  $adjustmentId  The document UUID. Deterministic for replayable callers (the G3
     *                                shift-variance listener derives it from the shift id) so a replay
     *                                resolves to the SAME document instead of minting a second one.
     * @param  ?string  $posShiftId  Originating POS shift, when the adjustment justifies a shift-close
     *                               cash variance. Write-once at insert — never backfilled (V3 gate).
     * @param  string  $idempotencyLeg  Movement-port leg discriminator; combined with
     *                                  `adjustment:{adjustmentId}` to form the idempotency key.
     */
    public function __construct(
        public string $repositoryId,
        public string $tenantId,
        public string $companyId,
        public MovementDirection $direction,
        public string $amount,
        public MovementReasonCode $reasonCode,
        public string $reasonText,
        public string $userId,
        public string $adjustmentId,
        public ?string $posShiftId = null,
        public string $idempotencyLeg = 'main',
        public ?CarbonImmutable $occurredAt = null,
    ) {}
}
