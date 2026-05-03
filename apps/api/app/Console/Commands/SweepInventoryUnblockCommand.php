<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Sweep\Domain\InventoryDocument;
use InvalidArgumentException;
use Throwable;

/**
 * `php artisan sweep:inventory:unblock`
 *
 * Transitions one callsite from `blocked` back to its previous status by
 * walking the callsite's history to find the most-recent `block` event and
 * reading its `from_status` field. Appends one `unblock` history event.
 *
 * Master plan reference:
 *   Section 4 — workflow state machine (unblock: blocked → previous state).
 *
 * Modes:
 *   --callsite-id  Unblock a single callsite (ONLY mode — cluster unblock is
 *                  OUT OF SCOPE per the master plan; --cluster is refused).
 *
 * State precondition: callsite.status MUST be `blocked`.
 * History precondition: there MUST be a `block` action in callsite.history
 *   (defensive guard against manually-malformed YAMLs).
 *
 * Optional: --reason (included in the history event note; not required).
 *
 * @phpstan-import-type Callsite from InventoryDocument
 * @phpstan-import-type HistoryEvent from InventoryDocument
 */
final class SweepInventoryUnblockCommand extends AbstractSweepInventoryCommand
{
    /** @var string */
    protected $signature = 'sweep:inventory:unblock
        {--inventory-path= : path to YAML (overrides default for tests)}
        {--schema-path= : path to JSON Schema (overrides default for tests)}
        {--callsite-id= : unblock a single callsite (ONLY mode)}
        {--cluster= : refused — cluster unblock is not supported; use --callsite-id}
        {--reason= : optional human-readable reason for the unblock}
        {--actor=human : claude|codex|ci|human (NOT generator — reserved)}';

    /** @var string */
    protected $description = 'Unblock a callsite, restoring its previous status (blocked → previous state).';

    protected function actionVerb(): string
    {
        return 'unblock';
    }

    protected function commandName(): string
    {
        return 'sweep:inventory:unblock';
    }

    public function handle(): int
    {
        // ── 1. Refuse --cluster; unblock is per-callsite only ─────────────────
        if ($this->stringOption('cluster') !== null) {
            $this->error(
                'sweep:inventory:unblock does not support --cluster. '.
                'Unblock is a per-callsite operation; use --callsite-id instead.',
            );

            return self::FAILURE;
        }

        // ── 2. Require --callsite-id ────────────────────────────────────────
        $callsiteId = $this->stringOption('callsite-id');
        if ($callsiteId === null) {
            $this->error('You must supply --callsite-id for sweep:inventory:unblock.');

            return self::FAILURE;
        }

        // ── 3. Resolve actor ────────────────────────────────────────────────
        try {
            $actor = $this->resolveActor();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // ── 4. Load the document ────────────────────────────────────────────
        $service = $this->makeInventoryService();

        try {
            $doc = $service->load();
        } catch (Throwable $t) {
            $this->error('Failed to load inventory: '.$t->getMessage());

            return self::FAILURE;
        }

        // ── 5. Resolve callsite ─────────────────────────────────────────────
        $callsite = $this->findCallsite($doc, $callsiteId);
        if ($callsite === null) {
            $this->error("Callsite not found: {$callsiteId}");

            return self::FAILURE;
        }

        // ── 6. State precondition: must be blocked ──────────────────────────
        if ($callsite['status'] !== 'blocked') {
            $this->error(
                "Callsite {$callsiteId} has status '{$callsite['status']}'; ".
                'only blocked callsites can be unblocked.',
            );

            return self::FAILURE;
        }

        // ── 7. Recover previous state from history ──────────────────────────
        $previousStatus = $this->recoverPreviousStatus($callsite['history']);
        if ($previousStatus === null) {
            $this->error(
                "Callsite {$callsiteId} is blocked but has no 'block' action in its history. ".
                'Cannot recover the previous status — the YAML appears to have been hand-edited. '.
                'Manually set the status to the correct value and re-run.',
            );

            return self::FAILURE;
        }

        // ── 8. Perform mutation ─────────────────────────────────────────────
        $reason = $this->stringOption('reason');
        $noteParts = ["unblocked by {$actor} via sweep:inventory:unblock; restored to {$previousStatus}"];
        if ($reason !== null && $reason !== '') {
            $noteParts[] = "reason: {$reason}";
        }
        $note = implode('; ', $noteParts);
        $context = $this->makeMutationContext($actor);

        try {
            $service->mutate(
                function (InventoryDocument $live) use ($callsiteId, $previousStatus, $note): InventoryDocument {
                    return $live->withCallsiteUpdate(
                        $callsiteId,
                        function (array $cs) use ($callsiteId, $previousStatus, $note): array {
                            $cs['status'] = $previousStatus;
                            $cs['blocked_reason'] = null;
                            $cs['history'][] = $this->makeHistoryEvent(
                                action: 'unblock',
                                fromStatus: 'blocked',
                                toStatus: $previousStatus,
                                targetIds: [$callsiteId],
                                note: $note,
                            );

                            return $cs;
                        },
                    );
                },
                $context,
            );
        } catch (Throwable $t) {
            $this->error('Failed to persist unblock: '.$t->getMessage());

            return self::FAILURE;
        }

        $this->line("Unblocked callsite {$callsiteId} (actor={$actor}); restored to '{$previousStatus}'.");

        // Re-load and warn about cluster/callsite state divergence — if the
        // parent cluster is still `blocked` after this individual unblock, the
        // callsite is now in an incoherent (callsite=<recovered>, cluster=blocked)
        // state. The operator should manually reconcile the cluster.
        try {
            $afterDoc = $service->load();
            $parentCluster = $this->findCluster($afterDoc, $callsite['cluster_id']);
            if ($parentCluster !== null && $parentCluster['status'] === 'blocked') {
                $this->warn(
                    "Cluster '{$parentCluster['id']}' is still blocked; callsite restored independently. ".
                    'If the block no longer applies cluster-wide, run sweep:inventory:unblock per affected '.
                    'callsite or update the cluster status manually.',
                );
            }
        } catch (Throwable) {
            // Best-effort warning only; do not turn unblock failure on a load problem.
        }

        return self::SUCCESS;
    }

    /**
     * Walk the callsite's history array backwards and return the `from_status`
     * of the most-recent event whose `to_status` is `blocked` — regardless of
     * which action transitioned the callsite into that state. Two paths reach
     * `blocked`:
     *   - `sweep:inventory:block` (action="block"), and
     *   - `sweep:inventory:review --verdict=BLOCK` (action="review", to_status="blocked").
     * Walking by `to_status` covers both, so a review-BLOCK can be undone here
     * the same way a block can. Returns null if no event ever transitioned the
     * callsite to `blocked` (malformed YAML defence).
     *
     * @param  list<HistoryEvent>  $history
     */
    private function recoverPreviousStatus(array $history): ?string
    {
        for ($i = count($history) - 1; $i >= 0; $i--) {
            $event = $history[$i];
            if ($event['to_status'] === 'blocked') {
                return $event['from_status'];
            }
        }

        return null;
    }
}
