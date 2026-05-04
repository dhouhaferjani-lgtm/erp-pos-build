<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Sweep\Domain\InventoryDocument;
use App\Application\Sweep\InventoryService;
use InvalidArgumentException;
use Throwable;

/**
 * `php artisan sweep:inventory:block`
 *
 * Transitions one callsite or an entire cluster from any pre-fixed status
 * to `blocked`, recording a block history event per affected callsite.
 *
 * Master plan reference:
 *   Section 4 — workflow state machine (block: any pre-fixed → blocked).
 *
 * Pre-fixed statuses: pending, claimed, in_progress, under_review (single
 * source of truth: {@see self::PRE_FIXED_STATUSES}).
 * `fixed` (regression guard), `blocked` (idempotency), `deferred` /
 * `needs_recheck` / `stale_orphan` (require explicit reactivation) are all
 * refused.
 *
 * Modes:
 *   --callsite-id  Block a single callsite.
 *   --cluster      Block the cluster AND every eligible (non-fixed, non-blocked)
 *                  callsite within it. Refuses when there are no eligible callsites
 *                  (audit-trail invariant: a cluster-status flip without callsite
 *                  history events is invisible to verify-history).
 *
 * Required: --reason (non-empty string).
 *
 * @phpstan-import-type Callsite from InventoryDocument
 * @phpstan-import-type Cluster from InventoryDocument
 * @phpstan-import-type HistoryEvent from InventoryDocument
 */
final class SweepInventoryBlockCommand extends AbstractSweepInventoryCommand
{
    /**
     * The pre-fixed states a callsite or cluster can transition from to
     * `blocked`. Per master plan Section 4 (line 231): "blocked, deferred,
     * needs_recheck, stale_orphan can branch from any pre-fixed state with a
     * reason." Pre-fixed = the four linear-pipeline statuses; everything else
     * (fixed, blocked, deferred, needs_recheck, stale_orphan) is refused.
     */
    private const PRE_FIXED_STATUSES = ['pending', 'claimed', 'in_progress', 'under_review'];

    /** @var string */
    protected $signature = 'sweep:inventory:block
        {--inventory-path= : path to YAML (overrides default for tests)}
        {--schema-path= : path to JSON Schema (overrides default for tests)}
        {--callsite-id= : block a single callsite}
        {--cluster= : block every eligible callsite in the cluster + the cluster itself}
        {--reason= : human-readable reason for the block (required)}
        {--actor=human : claude|codex|ci|human (NOT generator — reserved)}';

    /** @var string */
    protected $description = 'Block a callsite or cluster (any pre-fixed → blocked).';

    protected function actionVerb(): string
    {
        return 'block';
    }

    protected function commandName(): string
    {
        return 'sweep:inventory:block';
    }

    public function handle(): int
    {
        // ── 1. Validate mutual-exclusion of --callsite-id / --cluster ──────────
        $callsiteId = $this->stringOption('callsite-id');
        $clusterId = $this->stringOption('cluster');

        if ($callsiteId === null && $clusterId === null) {
            $this->error('You must supply either --callsite-id or --cluster (not neither).');

            return self::FAILURE;
        }
        if ($callsiteId !== null && $clusterId !== null) {
            $this->error('You must supply --callsite-id OR --cluster, not both.');

            return self::FAILURE;
        }

        // ── 2. Resolve actor ────────────────────────────────────────────────
        try {
            $actor = $this->resolveActor();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // ── 3. Validate --reason ────────────────────────────────────────────
        $reason = $this->stringOption('reason');
        if ($reason === null || $reason === '') {
            $this->error('--reason is required and must be non-empty.');

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

        // ── 5. Dispatch ─────────────────────────────────────────────────────
        if ($callsiteId !== null) {
            return $this->handleCallsiteBlock($doc, $callsiteId, $reason, $actor, $service);
        }

        /** @var string $clusterId */
        return $this->handleClusterBlock($doc, $clusterId, $reason, $actor, $service);
    }

    /**
     * Block a single callsite (any pre-fixed status → blocked). The cluster's
     * status is NOT touched in single-callsite mode.
     *
     * @param  'claude'|'codex'|'ci'|'human'  $actor
     */
    private function handleCallsiteBlock(
        InventoryDocument $doc,
        string $callsiteId,
        string $reason,
        string $actor,
        InventoryService $service,
    ): int {
        $callsite = $this->findCallsite($doc, $callsiteId);
        if ($callsite === null) {
            $this->error("Callsite not found: {$callsiteId}");

            return self::FAILURE;
        }

        $currentStatus = $callsite['status'];

        if ($currentStatus === 'fixed') {
            $this->error(
                "Callsite {$callsiteId} has status 'fixed'; blocking a fixed callsite would be a regression. ".
                'Open a new issue or revert the fix commit instead.',
            );

            return self::FAILURE;
        }

        if ($currentStatus === 'blocked') {
            $this->error(
                "Callsite {$callsiteId} is already 'blocked'. ".
                'Use sweep:inventory:unblock first if you need to re-block with a new reason.',
            );

            return self::FAILURE;
        }

        if (! in_array($currentStatus, self::PRE_FIXED_STATUSES, true)) {
            $this->error(
                "Callsite {$callsiteId} has status '{$currentStatus}'; only pre-fixed callsites ".
                '('.implode(', ', self::PRE_FIXED_STATUSES).') can be blocked.',
            );

            return self::FAILURE;
        }

        $note = "blocked by {$actor} via sweep:inventory:block: {$reason}";
        $context = $this->makeMutationContext($actor);

        try {
            $service->mutate(
                function (InventoryDocument $live) use ($callsiteId, $currentStatus, $reason, $note): InventoryDocument {
                    return $live->withCallsiteUpdate(
                        $callsiteId,
                        function (array $cs) use ($callsiteId, $currentStatus, $reason, $note): array {
                            $cs['status'] = 'blocked';
                            $cs['blocked_reason'] = $reason;
                            $cs['history'][] = $this->makeHistoryEvent(
                                action: 'block',
                                fromStatus: $currentStatus,
                                toStatus: 'blocked',
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
            $this->error('Failed to persist block: '.$t->getMessage());

            return self::FAILURE;
        }

        $this->line("Blocked callsite {$callsiteId} (actor={$actor}).");

        return self::SUCCESS;
    }

    /**
     * Block the cluster and every eligible callsite within it.
     * Eligible = not `fixed` and not already `blocked`.
     * Refuses when there are no eligible callsites (audit-trail invariant).
     *
     * @param  'claude'|'codex'|'ci'|'human'  $actor
     */
    private function handleClusterBlock(
        InventoryDocument $doc,
        string $clusterId,
        string $reason,
        string $actor,
        InventoryService $service,
    ): int {
        $cluster = $this->findCluster($doc, $clusterId);
        if ($cluster === null) {
            $this->error("Cluster not found: {$clusterId}");

            return self::FAILURE;
        }

        // Codex BLOCK finding #5: the cluster's OWN status must be in a
        // pre-fixed state before it can be blocked. Without this an
        // inconsistent YAML (e.g. cluster=fixed with one pending callsite)
        // could be moved to blocked, violating the state precondition.
        $clusterStatus = $cluster['status'];
        if (! in_array($clusterStatus, self::PRE_FIXED_STATUSES, true)) {
            $this->error(
                "Cluster {$clusterId} has status '{$clusterStatus}'; only pre-fixed clusters ".
                '('.implode(', ', self::PRE_FIXED_STATUSES).') can be blocked.',
            );

            return self::FAILURE;
        }

        // Collect eligible callsites: pre-fixed states only (pending, claimed,
        // in_progress, under_review). Excludes fixed (regression guard),
        // blocked (idempotency), and the audit-only states (deferred,
        // needs_recheck, stale_orphan) that need an explicit unblock-then-block
        // round-trip if the operator wants to forcibly block them.
        $eligibleCallsites = array_values(array_filter(
            $this->callsitesInCluster($doc, $clusterId),
            static fn (array $cs): bool => in_array($cs['status'], self::PRE_FIXED_STATUSES, true),
        ));

        // Audit-trail invariant: every cluster-status mutation must be anchored
        // in at least one callsite history event so verify-history can trace it.
        if ($eligibleCallsites === []) {
            $this->error(
                "Cluster {$clusterId} has no eligible callsites to block (none are in a pre-fixed state: ".
                implode(', ', self::PRE_FIXED_STATUSES).'). '.
                'Nothing to do — the cluster status has not been changed.',
            );

            return self::FAILURE;
        }

        /** @var list<array{id: string, status: string}> $eligibleCallsites */
        $eligibleData = array_map(
            static fn (array $cs): array => ['id' => $cs['id'], 'status' => $cs['status']],
            $eligibleCallsites,
        );

        $note = "cluster blocked by {$actor} via sweep:inventory:block: {$reason}";
        $context = $this->makeMutationContext($actor);

        try {
            $service->mutate(
                function (InventoryDocument $live) use (
                    $clusterId, $reason, $note, $eligibleData
                ): InventoryDocument {
                    // Update cluster status + blocked_reason.
                    $live = $live->withClusterUpdate(
                        $clusterId,
                        static function (array $c) use ($reason): array {
                            $c['status'] = 'blocked';
                            $c['blocked_reason'] = $reason;

                            return $c;
                        },
                    );

                    // Update each eligible callsite.
                    foreach ($eligibleData as $entry) {
                        $csId = $entry['id'];
                        $prevStatus = $entry['status'];
                        $live = $live->withCallsiteUpdate(
                            $csId,
                            function (array $cs) use ($csId, $prevStatus, $reason, $note): array {
                                $cs['status'] = 'blocked';
                                $cs['blocked_reason'] = $reason;
                                $cs['history'][] = $this->makeHistoryEvent(
                                    action: 'block',
                                    fromStatus: $prevStatus,
                                    toStatus: 'blocked',
                                    targetIds: [$csId],
                                    note: $note,
                                );

                                return $cs;
                            },
                        );
                    }

                    return $live;
                },
                $context,
            );
        } catch (Throwable $t) {
            $this->error('Failed to persist cluster block: '.$t->getMessage());

            return self::FAILURE;
        }

        $this->line(
            "Blocked cluster {$clusterId} (actor={$actor}); ".
            count($eligibleData).' eligible callsite(s) transitioned.',
        );

        return self::SUCCESS;
    }
}
