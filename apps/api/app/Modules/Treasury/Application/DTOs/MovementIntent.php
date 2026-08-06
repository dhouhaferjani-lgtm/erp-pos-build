<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\DTOs;

use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementReasonCode;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use Carbon\CarbonImmutable;

/**
 * Intent to record one ledger-leg movement against a treasury repository.
 *
 * Consumed by the write port's `record()` method (Task 11).
 */
final readonly class MovementIntent
{
    /**
     * @param  string  $repositoryId  UUID of the target PaymentRepository
     * @param  string  $tenantId  UUID of the owning tenant
     * @param  string  $companyId  UUID of the owning company
     * @param  numeric-string  $amount  positive decimal at currency scale (never float)
     * @param  string  $sourceId  UUID (or natural id) of the source record identified by $sourceType
     * @param  string  $idempotencyLeg  Caller-assigned leg discriminator (e.g. "payment:0") — combined
     *                                  with $sourceType/$sourceId to form the idempotency key
     * @param  ?string  $journalEntryId  UUID of the GL journal entry this movement is tied to, if any
     * @param  ?string  $reversesMovementId  UUID of the movement this one reverses, if any
     * @param  ?string  $createdBy  UUID of the acting user, if any
     * @param  bool  $allowWhileFrozen  Explicit escape hatch to allow writing while the repository is
     *                                  frozen. NEVER inferred from $sourceType. Defaults to false. Set
     *                                  true ONLY by offline-device-replay projection bridges (Task 20
     *                                  receipt legs, Task 21 returns) replaying movements that were
     *                                  authored on the device before the freeze took effect on the
     *                                  server. Every interactive caller and every server-authored fiscal
     *                                  event (e.g. DEPOSIT_RECEIPT, which isServerOnly()) must leave this
     *                                  false.
     * @param  bool  $allowBehindCheckpoint  Explicit offline-projection classification. Interactive and
     *                                       server-only callers must leave this false.
     * @param  bool  $allowNegative  Explicit escape hatch (W-5b Option B) to record an OUTFLOW that
     *                               would take the repository below zero even though the repository
     *                               itself refuses it (`allow_negative = false`). Mirrors
     *                               $allowWhileFrozen exactly: NEVER inferred from $sourceType,
     *                               defaults to false. Set true ONLY by queued/replay/bridge writers
     *                               replaying a movement that already physically happened (offline
     *                               device replay, fiscal-projection bridges) — a hard block there
     *                               would throw inside a queue worker and land the fiscal projection
     *                               in failed_jobs. record() RECORDS + alerts instead of throwing when
     *                               this is set. Every interactive caller (repository transfer,
     *                               adjustment, expense payment, outbound instrument issuance, …) must
     *                               leave this false so a mistyped write is refused at the click.
     */
    public function __construct(
        public string $repositoryId,
        public string $tenantId,
        public string $companyId,
        public MovementDirection $direction,
        public string $amount,
        public string $currency,
        public MovementSourceType $sourceType,
        public string $sourceId,
        public string $idempotencyLeg,
        public ?string $journalEntryId,
        public ?CarbonImmutable $occurredAt,
        public ?MovementReasonCode $reasonCode,
        public ?string $reversesMovementId,
        public ?string $createdBy,
        public ?string $notes,
        public bool $allowWhileFrozen = false,
        public bool $allowBehindCheckpoint = false,
        public bool $allowNegative = false,
    ) {}

    public function idempotencyKey(): string
    {
        return "{$this->sourceType->value}:{$this->sourceId}:{$this->idempotencyLeg}";
    }
}
