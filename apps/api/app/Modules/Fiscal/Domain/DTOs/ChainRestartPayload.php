<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\DTOs;

/**
 * CHAIN_RESTART payload.
 *
 * Emitted after a `CHAIN_BREAK_DETECTED` event when an authorized operator
 * documents a new genesis anchor. The event carries:
 *
 * - `new_genesis_reference`: the 64-char lowercase hex genesis seed the
 *   restarted chain will use as `previous_hash` for its first event.
 * - `last_good_anchor`: the (sequence_number, hash) pair from the broken
 *   chain that the restart references — the chain's verifiable history.
 * - `operator_authorization_evidence`: how the restart was authorized
 *   (operator id, role, reason code, manager PIN attestation).
 * - `provenance_link`: pointer to the `CHAIN_BREAK_DETECTED` event id this
 *   restart resolves; allows the verifier to follow the recovery
 *   provenance from the broken chain to the restarted one.
 *
 * Per spec v7 §9: a restart is deliberate, evidence-bearing, and chained
 * back to its break event — never an implicit splice.
 */
final readonly class ChainRestartPayload
{
    /**
     * @param  array<string, mixed>  $lastGoodAnchor  `{sequence_number, hash}` from the broken chain
     * @param  array<string, mixed>  $operatorAuthorizationEvidence  `{user_id, role, reason_code, manager_pin_attestation, ...}`
     * @param  array<string, mixed>  $provenanceLink  `{chain_break_event_id, ...}`
     */
    public function __construct(
        public string $newGenesisReference,
        public array $lastGoodAnchor,
        public array $operatorAuthorizationEvidence,
        public array $provenanceLink,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            newGenesisReference: (string) $data['new_genesis_reference'],
            lastGoodAnchor: $data['last_good_anchor'],
            operatorAuthorizationEvidence: $data['operator_authorization_evidence'],
            provenanceLink: $data['provenance_link'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'new_genesis_reference' => $this->newGenesisReference,
            'last_good_anchor' => $this->lastGoodAnchor,
            'operator_authorization_evidence' => $this->operatorAuthorizationEvidence,
            'provenance_link' => $this->provenanceLink,
        ];
    }
}
