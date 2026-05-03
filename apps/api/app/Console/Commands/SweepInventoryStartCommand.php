<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Sweep\Domain\InventoryDocument;
use App\Application\Sweep\InventoryService;
use InvalidArgumentException;
use Throwable;

/**
 * `php artisan sweep:inventory:start`
 *
 * Transitions one callsite or an entire cluster from `claimed` → `in_progress`
 * and appends a `start` history event per affected callsite.
 *
 * Master plan reference:
 *   Section 4 — workflow state machine (start: claimed → in_progress).
 *
 * Modes:
 *   --callsite-id  Start a single callsite (cluster status unchanged).
 *   --cluster      Start every claimed callsite in the cluster AND
 *                  the cluster itself.
 *
 * Cross-agent enforcement (start):
 *   --actor MUST equal the cluster's current `owner` (set by claim).
 *   NO --force override exists for start — wrong actor on a claimed cluster
 *   is a worker bug, not a hand-off case. Use claim --force to re-assign
 *   ownership instead.
 *
 * Audit-trail invariant: cluster mode with zero claimed callsites refuses,
 * mirroring the claim command's protection against history-less mutations.
 *
 * @phpstan-import-type Callsite from InventoryDocument
 * @phpstan-import-type Cluster from InventoryDocument
 * @phpstan-import-type HistoryEvent from InventoryDocument
 */
final class SweepInventoryStartCommand extends AbstractSweepInventoryCommand
{
    /** @var string */
    protected $signature = 'sweep:inventory:start
        {--inventory-path= : path to YAML (overrides default for tests)}
        {--schema-path= : path to JSON Schema (overrides default for tests)}
        {--callsite-id= : start a single claimed callsite}
        {--cluster= : start every claimed callsite in the cluster + the cluster itself}
        {--actor=human : claude|codex|ci|human (NOT generator — reserved)}';

    /** @var string */
    protected $description = 'Start a callsite or cluster, transitioning status claimed → in_progress.';

    protected function actionVerb(): string
    {
        return 'start';
    }

    protected function commandName(): string
    {
        return 'sweep:inventory:start';
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

        // ── 3. Load the document ────────────────────────────────────────────
        $service = $this->makeInventoryService();

        try {
            $doc = $service->load();
        } catch (Throwable $t) {
            $this->error('Failed to load inventory: '.$t->getMessage());

            return self::FAILURE;
        }

        // ── 4. Dispatch ─────────────────────────────────────────────────────
        if ($callsiteId !== null) {
            return $this->handleCallsiteStart($doc, $callsiteId, $actor, $service);
        }

        /** @var string $clusterId */
        return $this->handleClusterStart($doc, $clusterId, $actor, $service);
    }

    /**
     * Start a single callsite (status claimed → in_progress). The cluster's
     * status is NOT touched in single-callsite mode.
     *
     * @param  'claude'|'codex'|'ci'|'human'  $actor
     */
    private function handleCallsiteStart(
        InventoryDocument $doc,
        string $callsiteId,
        string $actor,
        InventoryService $service,
    ): int {
        $callsite = $this->findCallsite($doc, $callsiteId);
        if ($callsite === null) {
            $this->error("Callsite not found: {$callsiteId}");

            return self::FAILURE;
        }

        // State-transition precondition: callsite must be claimed.
        if ($callsite['status'] !== 'claimed') {
            $this->error(
                "Callsite {$callsiteId} has status '{$callsite['status']}'; ".
                'only claimed callsites can be started.',
            );

            return self::FAILURE;
        }

        // Cross-agent enforcement: per-callsite mode reads the CALLSITE's owner
        // (set by either single-callsite or cluster-mode claim). Cluster-mode
        // start checks cluster.owner separately; the two modes intentionally
        // diverge because per-callsite claim does not set cluster.owner.
        $ownerError = $this->enforceCallsiteOwnerMatch($callsite, $actor);
        if ($ownerError !== null) {
            $this->error($ownerError);

            return self::FAILURE;
        }

        // ── Perform mutation ────────────────────────────────────────────────
        $note = "started by {$actor} via sweep:inventory:start";
        $context = $this->makeMutationContext($actor);

        try {
            $service->mutate(
                function (InventoryDocument $live) use ($callsiteId, $note): InventoryDocument {
                    return $live->withCallsiteUpdate(
                        $callsiteId,
                        function (array $cs) use ($note, $callsiteId): array {
                            $cs['status'] = 'in_progress';
                            $cs['history'][] = $this->makeHistoryEvent(
                                action: 'start',
                                fromStatus: 'claimed',
                                toStatus: 'in_progress',
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
            $this->error('Failed to persist start: '.$t->getMessage());

            return self::FAILURE;
        }

        $this->line("Started callsite {$callsiteId} (actor={$actor}).");

        return self::SUCCESS;
    }

    /**
     * Start every claimed callsite in a cluster + the cluster itself.
     *
     * @param  'claude'|'codex'|'ci'|'human'  $actor
     */
    private function handleClusterStart(
        InventoryDocument $doc,
        string $clusterId,
        string $actor,
        InventoryService $service,
    ): int {
        $cluster = $this->findCluster($doc, $clusterId);
        if ($cluster === null) {
            $this->error("Cluster not found: {$clusterId}");

            return self::FAILURE;
        }

        // State-transition precondition: cluster must be claimed.
        if ($cluster['status'] !== 'claimed') {
            $this->error(
                "Cluster {$clusterId} has status '{$cluster['status']}'; ".
                'only claimed clusters can be started.',
            );

            return self::FAILURE;
        }

        // Cross-agent enforcement: actor MUST equal the cluster's current owner.
        $ownerError = $this->enforceClusterOwnerMatch($cluster, $actor);
        if ($ownerError !== null) {
            $this->error($ownerError);

            return self::FAILURE;
        }

        // Collect claimed callsites in this cluster.
        $claimedCallsiteIds = array_values(array_map(
            static fn (array $cs): string => $cs['id'],
            array_filter(
                $this->callsitesInCluster($doc, $clusterId),
                static fn (array $cs): bool => $cs['status'] === 'claimed',
            ),
        ));

        // Audit-trail invariant: cluster mutation requires at least one
        // callsite history event. Refuse explicitly when there are none —
        // covers both "callsites already in_progress" (work has begun
        // piecemeal) and "callsites in non-claimable states" (out-of-sync).
        if ($claimedCallsiteIds === []) {
            $this->error(
                "Cluster {$clusterId} has no claimed callsites remaining ".
                '(callsites may already be in_progress, fixed, or otherwise advanced). '.
                'Use --callsite-id on individual callsites if work is partially complete, '.
                'or investigate why the cluster and its callsites are out of sync.',
            );

            return self::FAILURE;
        }

        // ── Perform mutation ────────────────────────────────────────────────
        $note = "cluster started by {$actor} via sweep:inventory:start";
        $context = $this->makeMutationContext($actor);

        try {
            $service->mutate(
                function (InventoryDocument $live) use ($clusterId, $claimedCallsiteIds, $note): InventoryDocument {
                    $live = $live->withClusterUpdate(
                        $clusterId,
                        static function (array $c): array {
                            $c['status'] = 'in_progress';

                            return $c;
                        },
                    );

                    foreach ($claimedCallsiteIds as $csId) {
                        $live = $live->withCallsiteUpdate(
                            $csId,
                            function (array $cs) use ($note, $csId): array {
                                $cs['status'] = 'in_progress';
                                $cs['history'][] = $this->makeHistoryEvent(
                                    action: 'start',
                                    fromStatus: 'claimed',
                                    toStatus: 'in_progress',
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
            $this->error('Failed to persist cluster start: '.$t->getMessage());

            return self::FAILURE;
        }

        $this->line(
            "Started cluster {$clusterId} (actor={$actor}); ".
            count($claimedCallsiteIds).' claimed callsite(s) transitioned.',
        );

        return self::SUCCESS;
    }

    /**
     * Refuse the mutation when --actor differs from the cluster's current owner.
     * Returns null on success, a human-readable error string on mismatch.
     * Used by cluster-mode start (which mutates cluster.status); single-callsite
     * mode uses {@see self::enforceCallsiteOwnerMatch} instead because
     * sweep:inventory:claim --callsite-id does not populate cluster.owner.
     *
     * @param  Cluster  $cluster
     * @param  'claude'|'codex'|'ci'|'human'  $actor
     */
    private function enforceClusterOwnerMatch(array $cluster, string $actor): ?string
    {
        $owner = $cluster['owner'];
        if ($owner === null) {
            return "Cluster '{$cluster['id']}' has no owner set; cannot start. ".
                'Run sweep:inventory:claim --cluster first.';
        }

        if ($owner !== $actor) {
            return "Cluster '{$cluster['id']}' is owned by '{$owner}' but --actor is '{$actor}'. ".
                'Start must be invoked by the cluster owner; use sweep:inventory:claim --force '.
                'to re-assign ownership if the original owner cannot proceed.';
        }

        return null;
    }

    /**
     * Refuse the mutation when --actor differs from the callsite's current owner.
     * Used by single-callsite-mode start, which is reachable after either
     * single-callsite claim (sets only callsite.owner) or cluster-mode claim
     * (sets both cluster.owner and every callsite.owner).
     *
     * @param  Callsite  $callsite
     * @param  'claude'|'codex'|'ci'|'human'  $actor
     */
    private function enforceCallsiteOwnerMatch(array $callsite, string $actor): ?string
    {
        $owner = $callsite['owner'];
        if ($owner === null) {
            return "Callsite '{$callsite['id']}' has no owner set; cannot start. ".
                'Run sweep:inventory:claim --callsite-id first.';
        }

        if ($owner !== $actor) {
            return "Callsite '{$callsite['id']}' is owned by '{$owner}' but --actor is '{$actor}'. ".
                'Start must be invoked by the callsite owner.';
        }

        return null;
    }
}
