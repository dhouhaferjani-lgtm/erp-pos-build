<?php

declare(strict_types=1);

namespace App\Modules\BatchExpiry\Application\DTOs;

use App\Modules\Inventory\Domain\Enums\MovementReason;

/**
 * Input for an atomic, all-or-nothing, idempotent multi-lot write-off at a
 * single location.
 *
 * The B3b HTTP layer (route/controller/FormRequest) maps a validated request
 * into this DTO; the service signature is intentionally array-free so B3b can
 * wrap it without leaking loose payloads into the domain.
 */
final readonly class GroupedWriteOffData
{
    /**
     * @param  list<GroupedWriteOffLine>  $lines  One entry per lot to write off.
     * @param  MovementReason  $reason  MUST be a write-off reason (Expiry|Damage|WriteOff).
     * @param  string  $idempotencyKey  Client-supplied key; replaying the same key is a no-op.
     */
    public function __construct(
        public string $locationId,
        public array $lines,
        public MovementReason $reason,
        public string $idempotencyKey,
    ) {}
}
