<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Services;

use App\Modules\Fiscal\Domain\Exceptions\ServerAuthoredChainPlacementException;
use App\Shared\Contracts\Fiscal\FiscalIntegrityProvider;
use Carbon\CarbonImmutable;
use stdClass;

/**
 * ES-09 defect (b) — derive the integrity verdict for a SERVER-AUTHORED fiscal
 * event instead of asserting it.
 *
 * The register row names the defect precisely: both server-authoring services
 * *"stamp `integrity_status=Verified` / `payload_parse_status=Parsed`
 * **unconditionally**, skipping `verifyLinkage`/`verifyClock`"*. This class is
 * those two checks, in `OutboxIngestor`'s own priority order (hash → linkage →
 * clock, spec §7.2), applied to the link the service just composed.
 *
 * It lives in Fiscal and is injected by both authoring services rather than
 * being copied into each. The duplicated-literal lesson from ES-16's review
 * (M3b round 1, F-5) applies here in advance: two copies of a chain-admissibility
 * rule drift, and the drift is silent.
 *
 * The `payload_parse_status = Parsed` half of the same defect is closed at the
 * call sites, which already run the same per-event payload gate the parse path
 * runs (`validateServerAuthoredPayload()` / `validatePayload()`) and throw
 * before persist — so `Parsed` is earned by an executed validation rather than
 * declared. What was missing, and is added here, is the INTEGRITY half.
 */
final class ServerAuthoredChainPlacementVerifier
{
    public function __construct(
        private readonly FiscalIntegrityProvider $integrity,
        private readonly ClockAnomalyDetector $clockAnomalyDetector,
    ) {}

    /**
     * Assert that a server-authored event's composed placement derives to
     * `Verified`, or refuse.
     *
     * @param  stdClass|null  $prior  the chain head this event links to, read under
     *                                the authoring terminal's row lock and scoped by
     *                                `(tenant_id, company_id, terminal_id, chain_context)`.
     *                                Must carry `sequence_number`, `current_hash` and
     *                                `event_time_device`.
     *
     * @throws ServerAuthoredChainPlacementException
     */
    public function assertAdmissible(
        ?stdClass $prior,
        string $genesisSeed,
        int $sequenceNumber,
        string $previousHash,
        string $canonicalBytes,
        string $currentHash,
        string $eventTimeDevice,
        CarbonImmutable $serverReceivedAt,
        string $eventType,
        string $chainContext,
    ): void {
        $reason = $this->deriveFailureReason(
            $prior,
            $genesisSeed,
            $sequenceNumber,
            $previousHash,
            $canonicalBytes,
            $currentHash,
            $eventTimeDevice,
            $serverReceivedAt,
        );

        if ($reason === null) {
            return;
        }

        throw new ServerAuthoredChainPlacementException(sprintf(
            'Server-authored %s on chain_context=%s is not admissible: %s. No fiscal_events row was written — '
            .'the integrity verdict for a server-authored event is DERIVED, never stamped (ES-09).',
            $eventType,
            $chainContext,
            $reason,
        ));
    }

    /**
     * §7.2 priority order: hash, then linkage, then clock. Returns `null` when
     * the verdict is `Verified`.
     */
    private function deriveFailureReason(
        ?stdClass $prior,
        string $genesisSeed,
        int $sequenceNumber,
        string $previousHash,
        string $canonicalBytes,
        string $currentHash,
        string $eventTimeDevice,
        CarbonImmutable $serverReceivedAt,
    ): ?string {
        if (! $this->integrity->verify($canonicalBytes, $currentHash)) {
            return 'canonical_hash_mismatch:current_hash_is_not_the_hash_of_canonical_bytes';
        }

        $linkage = $this->deriveLinkageFailure($prior, $genesisSeed, $sequenceNumber, $previousHash);
        if ($linkage !== null) {
            return $linkage;
        }

        $priorTime = $prior === null
            ? null
            : (is_string($prior->event_time_device)
                ? $prior->event_time_device
                : (string) $prior->event_time_device);

        if ($this->clockAnomalyDetector->isWithinTolerance(
            deviceTime: $eventTimeDevice,
            lastServerTimeSeen: $priorTime,
            serverReceivedAt: $serverReceivedAt,
        )) {
            return null;
        }

        return $this->clockAnomalyDetector->formatTimeAnomalyReason(
            deviceTime: $eventTimeDevice,
            lastServerTimeSeen: $priorTime,
            serverReceivedAt: $serverReceivedAt,
        );
    }

    /**
     * Mirrors `OutboxIngestor::verifyLinkage()` — including the T19-B3
     * first-event genesis-seed rule, which is the check that makes a
     * mis-scoped head resolution visible: a head read from the WRONG
     * `chain_context` makes this context's genuinely-first event look like a
     * continuation and vice versa.
     */
    private function deriveLinkageFailure(
        ?stdClass $prior,
        string $genesisSeed,
        int $sequenceNumber,
        string $previousHash,
    ): ?string {
        if ($prior === null) {
            if ($sequenceNumber !== 1) {
                return sprintf('sequence_gap:no_prior_row_but_sequence=%d_must_be_1', $sequenceNumber);
            }

            return hash_equals(strtolower($genesisSeed), strtolower($previousHash))
                ? null
                : sprintf(
                    'sequence_gap:genesis_seed_mismatch,expected_prefix=%s...,got_prefix=%s...',
                    substr($genesisSeed, 0, 8),
                    substr($previousHash, 0, 8),
                );
        }

        $priorSequence = is_int($prior->sequence_number)
            ? $prior->sequence_number
            : (int) $prior->sequence_number;
        $priorHash = is_string($prior->current_hash) ? $prior->current_hash : (string) $prior->current_hash;

        $errors = [];
        if ($sequenceNumber !== $priorSequence + 1) {
            $errors[] = sprintf('numeric_gap:expected=%d,got=%d', $priorSequence + 1, $sequenceNumber);
        }
        if ($previousHash !== $priorHash) {
            $errors[] = 'hash_linkage_broken:previous_hash_does_not_match_prior_current_hash';
        }

        return $errors === [] ? null : 'sequence_gap:'.implode('|', $errors);
    }
}
