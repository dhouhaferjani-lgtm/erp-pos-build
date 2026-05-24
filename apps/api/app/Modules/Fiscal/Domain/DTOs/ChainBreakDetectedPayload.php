<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\DTOs;

/**
 * CHAIN_BREAK_DETECTED payload.
 *
 * Authored by the device (or, in §9 chain-recovery flows, by the server's
 * recovery surface) when an integrity violation is detected against the
 * `(tenant_id, terminal_id)` chain. The event records the last anchor that
 * was still verifiable and the reference of the offending record so the
 * recovery flow can re-emit a `CHAIN_RESTART` with a documented provenance
 * link.
 *
 * Per spec v7 §9: chain-recovery is a deliberate, operator-authorized act;
 * the device does not silently splice a new chain — it records the break
 * and waits for an authoritative restart.
 */
final readonly class ChainBreakDetectedPayload
{
    /**
     * @param  array<string, mixed>  $offendingRecordReference  identifying the offending row, typically
     *                                                          `{terminal_id, sequence_number, observed_previous_hash}`
     */
    public function __construct(
        public string $reason,
        public int $lastGoodSequence,
        public string $lastGoodHash,
        public array $offendingRecordReference,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            reason: FiscalPayloadArrayGuards::requireString($data, 'reason'),
            lastGoodSequence: FiscalPayloadArrayGuards::requireInt($data, 'last_good_sequence'),
            lastGoodHash: FiscalPayloadArrayGuards::requireString($data, 'last_good_hash'),
            // @phpstan-ignore-next-line argument.type
            offendingRecordReference: FiscalPayloadArrayGuards::requireArray($data, 'offending_record_reference'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'reason' => $this->reason,
            'last_good_sequence' => $this->lastGoodSequence,
            'last_good_hash' => $this->lastGoodHash,
            'offending_record_reference' => $this->offendingRecordReference,
        ];
    }
}
