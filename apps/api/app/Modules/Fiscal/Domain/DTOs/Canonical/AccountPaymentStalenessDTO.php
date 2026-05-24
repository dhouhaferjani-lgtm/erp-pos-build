<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\DTOs\Canonical;

use App\Modules\Fiscal\Domain\DTOs\FiscalPayloadArrayGuards;

final readonly class AccountPaymentStalenessDTO
{
    public function __construct(
        public bool $customerSnapshotStale,
        public bool $balanceSnapshotStale,
        public ?string $mirrorLastSyncedAt,
        public ?string $stalenessReason,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            customerSnapshotStale: FiscalPayloadArrayGuards::requireBool($data, 'customer_snapshot_stale'),
            balanceSnapshotStale: FiscalPayloadArrayGuards::requireBool($data, 'balance_snapshot_stale'),
            mirrorLastSyncedAt: FiscalPayloadArrayGuards::optionalString($data, 'mirror_last_synced_at'),
            stalenessReason: FiscalPayloadArrayGuards::optionalString($data, 'staleness_reason'),
        );
    }
}
