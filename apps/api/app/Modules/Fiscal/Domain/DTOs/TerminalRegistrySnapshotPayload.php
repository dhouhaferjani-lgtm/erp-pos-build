<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\DTOs;

/**
 * TERMINAL_REGISTRY_SNAPSHOT payload.
 *
 * Records the authoritative terminal list at a point in time, with a hash
 * over the canonical-serialized list and a link to the prior snapshot.
 * The snapshots form a sub-chain that the company-level integrity check
 * can verify independently of the per-terminal event chains.
 *
 * Per spec v7 §11: company-level integrity record types extend the
 * device-authority pattern to multi-terminal facts. `TERMINAL_REGISTRY_SNAPSHOT`
 * is implemented in Phase 1; `COMPANY_DAY_CLOSURE_MANIFEST` ships as a
 * reserved schema-only DTO (Phase 2+).
 */
final readonly class TerminalRegistrySnapshotPayload
{
    /**
     * @param  list<array<string, mixed>>  $terminals  each `{terminal_id, terminal_code, is_active, ...}`
     */
    public function __construct(
        public array $terminals,
        public string $snapshotHash,
        public ?string $priorSnapshotLink,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            // @phpstan-ignore-next-line argument.type
            terminals: FiscalPayloadArrayGuards::requireArray($data, 'terminals'),
            snapshotHash: FiscalPayloadArrayGuards::requireString($data, 'snapshot_hash'),
            priorSnapshotLink: FiscalPayloadArrayGuards::optionalString($data, 'prior_snapshot_link'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'terminals' => $this->terminals,
            'snapshot_hash' => $this->snapshotHash,
            'prior_snapshot_link' => $this->priorSnapshotLink,
        ];
    }
}
