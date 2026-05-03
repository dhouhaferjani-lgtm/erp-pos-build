<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Sweep\Domain\InventoryDocument;
use App\Application\Sweep\InventoryService;
use InvalidArgumentException;
use Throwable;

/**
 * `php artisan sweep:inventory:claim`
 *
 * Transitions one callsite or an entire cluster from `pending` → `claimed`
 * and appends a `claim` history event per affected callsite.
 *
 * Master plan reference:
 *   Section 4 — workflow state machine (claim: pending → claimed).
 *   Section 7 — Treasury HARD GATE: any non-reference-cluster claim
 *     refuses while the reference cluster (is_reference=true) isn't
 *     in `fixed` status with a passing review-gate check.
 *
 * Modes:
 *   --callsite-id  Claim a single callsite (cluster status unchanged).
 *   --cluster      Claim every pending callsite in the cluster AND
 *                  the cluster itself.
 *
 * Cross-agent enforcement:
 *   cluster.required_owner must match --actor unless --force
 *   --reason --approved-by is supplied.
 *
 * Hard gate (non-reference-cluster targets only):
 *   1. Reference cluster (is_reference=true) must have status=fixed.
 *   2. review_gate.review_file must exist on disk.
 *   3. File must contain a `Verdict: <X>` line where X is in accepted_verdicts.
 *   4. If verify_review_commit_linkage=true, a `Commit reviewed: <SHA>` line
 *      must be present and the SHA must match at least one reference-cluster
 *      callsite's fix_commit.
 *
 * @phpstan-import-type Callsite from InventoryDocument
 * @phpstan-import-type Cluster from InventoryDocument
 * @phpstan-import-type HistoryEvent from InventoryDocument
 * @phpstan-import-type ReviewGate from InventoryDocument
 */
final class SweepInventoryClaimCommand extends AbstractSweepInventoryCommand
{
    /** @var string */
    protected $signature = 'sweep:inventory:claim
        {--inventory-path= : path to YAML (overrides default for tests)}
        {--schema-path= : path to JSON Schema (overrides default for tests)}
        {--callsite-id= : claim a single callsite}
        {--cluster= : claim every pending callsite in the cluster + the cluster itself}
        {--actor=human : claude|codex|ci|human (NOT generator — reserved)}
        {--force : bypass required_owner check; requires --reason and --approved-by}
        {--reason= : human-readable reason; required when --force is set}
        {--approved-by= : human approver identifier; required when --force is set}';

    /** @var string */
    protected $description = 'Claim a callsite or cluster, transitioning status pending → claimed.';

    protected function actionVerb(): string
    {
        return 'claim';
    }

    protected function commandName(): string
    {
        return 'sweep:inventory:claim';
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

        // ── 2. Resolve actor (throws InvalidArgumentException on bad value) ─────
        try {
            $actor = $this->resolveActor();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // ── 3. Validate --force constraints ───────────────────────────────────
        $force = $this->boolFlag('force');
        $reason = $this->stringOption('reason');
        $approvedBy = $this->stringOption('approved-by');

        if ($force) {
            if ($reason === null || $reason === '') {
                $this->error('--force requires --reason to be set (non-empty).');

                return self::FAILURE;
            }
            if ($approvedBy === null || $approvedBy === '') {
                $this->error('--force requires --approved-by to be set (non-empty).');

                return self::FAILURE;
            }
        }

        // ── 4. Load the document (non-locking read for precondition checks) ──
        $service = $this->makeInventoryService();

        try {
            $doc = $service->load();
        } catch (Throwable $t) {
            $this->error('Failed to load inventory: '.$t->getMessage());

            return self::FAILURE;
        }

        // ── 5. Dispatch to the appropriate mode ───────────────────────────────
        if ($callsiteId !== null) {
            return $this->handleCallsiteClaim($doc, $callsiteId, $actor, $force, $reason, $approvedBy, $service);
        }

        /** @var string $clusterId */
        return $this->handleClusterClaim($doc, $clusterId, $actor, $force, $reason, $approvedBy, $service);
    }

    /**
     * Claim a single callsite (status pending → claimed). The cluster's status
     * is NOT touched in single-callsite mode.
     *
     * @param  'claude'|'codex'|'ci'|'human'  $actor
     */
    private function handleCallsiteClaim(
        InventoryDocument $doc,
        string $callsiteId,
        string $actor,
        bool $force,
        ?string $reason,
        ?string $approvedBy,
        InventoryService $service,
    ): int {
        // Resolve target callsite.
        $callsite = $this->findCallsite($doc, $callsiteId);
        if ($callsite === null) {
            $this->error("Callsite not found: {$callsiteId}");

            return self::FAILURE;
        }

        // State-transition precondition: must be pending.
        if ($callsite['status'] !== 'pending') {
            $this->error(
                "Callsite {$callsiteId} has status '{$callsite['status']}'; ".
                'only pending callsites can be claimed.',
            );

            return self::FAILURE;
        }

        // Resolve the owning cluster for cross-agent + hard-gate checks.
        $cluster = $this->findCluster($doc, $callsite['cluster_id']);
        if ($cluster === null) {
            $this->error("Cluster not found for callsite: {$callsite['cluster_id']}");

            return self::FAILURE;
        }

        // Cross-agent enforcement.
        $overrideNote = null;
        if ($cluster['required_owner'] !== null && $cluster['required_owner'] !== $actor) {
            if (! $force) {
                $this->error(
                    "Cluster '{$cluster['id']}' requires owner '{$cluster['required_owner']}' ".
                    "but --actor is '{$actor}'. Use --force --reason=... --approved-by=... to override.",
                );

                return self::FAILURE;
            }
            $overrideNote = "force-claim by actor={$actor}; approved-by={$approvedBy}; reason={$reason}";
        }

        // Hard gate: if the target is NOT a reference cluster, validate Treasury.
        if (! $cluster['is_reference']) {
            $gateResult = $this->enforceHardGate($doc, $callsite['cluster_id']);
            if ($gateResult !== null) {
                $this->error($gateResult);

                return self::FAILURE;
            }
        }

        // ── Perform mutation ──────────────────────────────────────────────────
        $claimNote = $overrideNote ?? "claimed by {$actor} via sweep:inventory:claim";
        $claimedAt = gmdate('Y-m-d\TH:i:s\Z');
        $context = $this->makeMutationContext($actor);

        try {
            $service->mutate(
                function (InventoryDocument $live) use ($callsiteId, $actor, $claimNote, $claimedAt): InventoryDocument {
                    return $live->withCallsiteUpdate(
                        $callsiteId,
                        function (array $cs) use ($actor, $claimNote, $claimedAt, $callsiteId): array {
                            $cs['status'] = 'claimed';
                            $cs['owner'] = $actor;
                            $cs['claimed_at'] = $claimedAt;
                            $cs['history'][] = $this->makeHistoryEvent(
                                action: 'claim',
                                fromStatus: 'pending',
                                toStatus: 'claimed',
                                targetIds: [$callsiteId],
                                note: $claimNote,
                            );

                            return $cs;
                        },
                    );
                },
                $context,
            );
        } catch (Throwable $t) {
            $this->error('Failed to persist claim: '.$t->getMessage());

            return self::FAILURE;
        }

        $this->line("Claimed callsite {$callsiteId} (actor={$actor}).");

        return self::SUCCESS;
    }

    /**
     * Claim every pending callsite in a cluster and the cluster itself.
     *
     * @param  'claude'|'codex'|'ci'|'human'  $actor
     */
    private function handleClusterClaim(
        InventoryDocument $doc,
        string $clusterId,
        string $actor,
        bool $force,
        ?string $reason,
        ?string $approvedBy,
        InventoryService $service,
    ): int {
        // Resolve target cluster.
        $cluster = $this->findCluster($doc, $clusterId);
        if ($cluster === null) {
            $this->error("Cluster not found: {$clusterId}");

            return self::FAILURE;
        }

        // State-transition precondition: cluster must be pending.
        if ($cluster['status'] !== 'pending') {
            $this->error(
                "Cluster {$clusterId} has status '{$cluster['status']}'; ".
                'only pending clusters can be claimed.',
            );

            return self::FAILURE;
        }

        // Cross-agent enforcement.
        $overrideNote = null;
        if ($cluster['required_owner'] !== null && $cluster['required_owner'] !== $actor) {
            if (! $force) {
                $this->error(
                    "Cluster '{$clusterId}' requires owner '{$cluster['required_owner']}' ".
                    "but --actor is '{$actor}'. Use --force --reason=... --approved-by=... to override.",
                );

                return self::FAILURE;
            }
            $overrideNote = "force-claim by actor={$actor}; approved-by={$approvedBy}; reason={$reason}";
        }

        // Hard gate: if the target cluster is NOT a reference cluster, validate.
        if (! $cluster['is_reference']) {
            $gateResult = $this->enforceHardGate($doc, $clusterId);
            if ($gateResult !== null) {
                $this->error($gateResult);

                return self::FAILURE;
            }
        }

        // Collect pending callsites in this cluster.
        $pendingCallsites = array_filter(
            $this->callsitesInCluster($doc, $clusterId),
            static fn (array $cs): bool => $cs['status'] === 'pending',
        );

        // ── Perform mutation ──────────────────────────────────────────────────
        $claimNote = $overrideNote ?? "cluster claimed by {$actor} via sweep:inventory:claim";
        $claimedAt = gmdate('Y-m-d\TH:i:s\Z');
        $context = $this->makeMutationContext($actor);
        $pendingCallsiteIds = array_values(array_map(
            static fn (array $cs): string => $cs['id'],
            $pendingCallsites,
        ));

        try {
            $service->mutate(
                function (InventoryDocument $live) use (
                    $clusterId, $actor, $claimNote, $claimedAt, $pendingCallsiteIds
                ): InventoryDocument {
                    // Update cluster status + owner.
                    $live = $live->withClusterUpdate(
                        $clusterId,
                        static function (array $c) use ($actor): array {
                            $c['status'] = 'claimed';
                            $c['owner'] = $actor;

                            return $c;
                        },
                    );

                    // Update each pending callsite.
                    foreach ($pendingCallsiteIds as $csId) {
                        $live = $live->withCallsiteUpdate(
                            $csId,
                            function (array $cs) use ($actor, $claimNote, $claimedAt, $csId): array {
                                $cs['status'] = 'claimed';
                                $cs['owner'] = $actor;
                                $cs['claimed_at'] = $claimedAt;
                                $cs['history'][] = $this->makeHistoryEvent(
                                    action: 'claim',
                                    fromStatus: 'pending',
                                    toStatus: 'claimed',
                                    targetIds: [$csId],
                                    note: $claimNote,
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
            $this->error('Failed to persist cluster claim: '.$t->getMessage());

            return self::FAILURE;
        }

        $this->line(
            "Claimed cluster {$clusterId} (actor={$actor}); ".
            count($pendingCallsiteIds).' pending callsite(s) transitioned.',
        );

        return self::SUCCESS;
    }

    /**
     * Enforce the Treasury hard gate for non-reference-cluster targets.
     *
     * Returns a non-null error string if the gate fails (caller surfaces it
     * via $this->error()), or null on success.
     *
     * Gate algorithm (master plan Section 7):
     *   1. Find every reference cluster (is_reference=true). If none, fail.
     *   2. For each reference cluster: status must be 'fixed'.
     *   3. review_gate.review_file must exist on disk.
     *   4. File must contain `Verdict: <X>` with X in accepted_verdicts.
     *   5. If verify_review_commit_linkage=true, file must contain
     *      `Commit reviewed: <SHA>` matching at least one callsite fix_commit
     *      in the reference cluster.
     */
    private function enforceHardGate(InventoryDocument $doc, string $targetClusterId): ?string
    {
        $referenceClusters = array_values(array_filter(
            $doc->clusters(),
            static fn (array $c): bool => $c['is_reference'],
        ));

        if ($referenceClusters === []) {
            return 'Hard gate: no reference cluster (is_reference=true) found in inventory. Cannot proceed.';
        }

        foreach ($referenceClusters as $refCluster) {
            $refId = $refCluster['id'];

            // (2) Reference cluster must be fixed.
            if ($refCluster['status'] !== 'fixed') {
                return "Hard gate: reference cluster '{$refId}' has status '{$refCluster['status']}'; ".
                    "it must be 'fixed' before non-reference clusters can be claimed.";
            }

            // (3) review_file must exist.
            /** @var ReviewGate $reviewGate */
            $reviewGate = $refCluster['review_gate'];
            $reviewFile = $reviewGate['review_file'];

            if (! is_file($reviewFile)) {
                return "Hard gate: reference cluster '{$refId}' review file does not exist on disk: {$reviewFile}";
            }

            // (4) Parse Verdict line.
            $contents = (string) file_get_contents($reviewFile);
            $verdict = $this->parseVerdict($contents);

            if ($verdict === null) {
                return "Hard gate: reference cluster '{$refId}' review file contains no 'Verdict: <X>' line: {$reviewFile}";
            }

            $acceptedVerdicts = $reviewGate['accepted_verdicts'];
            if (! in_array($verdict, $acceptedVerdicts, true)) {
                return "Hard gate: reference cluster '{$refId}' review verdict '{$verdict}' is not in ".
                    'accepted_verdicts ['.implode(', ', $acceptedVerdicts).']. '.
                    "Review file: {$reviewFile}";
            }

            // (5) Optional commit linkage check.
            if ($reviewGate['verify_review_commit_linkage']) {
                $linkageError = $this->verifyCommitLinkage($doc, $refId, $contents, $reviewFile);
                if ($linkageError !== null) {
                    return $linkageError;
                }
            }
        }

        return null;
    }

    /**
     * Parse the first `Verdict: <X>` line from the review file contents.
     * Returns null if no such line is found.
     */
    private function parseVerdict(string $contents): ?string
    {
        foreach (explode("\n", $contents) as $line) {
            if (str_starts_with($line, 'Verdict: ')) {
                $value = trim(substr($line, strlen('Verdict: ')));

                return $value !== '' ? $value : null;
            }
        }

        return null;
    }

    /**
     * Verify that the review file contains a `Commit reviewed: <SHA>` line
     * whose SHA matches at least one fix_commit in the reference cluster's
     * callsites.
     *
     * Returns a non-null error string on failure.
     */
    private function verifyCommitLinkage(
        InventoryDocument $doc,
        string $refClusterId,
        string $reviewFileContents,
        string $reviewFilePath,
    ): ?string {
        // Parse the commit SHA from the review file.
        $reviewedCommit = null;
        foreach (explode("\n", $reviewFileContents) as $line) {
            if (str_starts_with($line, 'Commit reviewed: ')) {
                $reviewedCommit = trim(substr($line, strlen('Commit reviewed: ')));
                break;
            }
        }

        if ($reviewedCommit === null || $reviewedCommit === '') {
            return 'Hard gate: commit linkage check enabled but review file has no '.
                "'Commit reviewed: <SHA>' line: {$reviewFilePath}";
        }

        // Collect fix_commit values from reference cluster callsites.
        $fixCommits = [];
        foreach ($this->callsitesInCluster($doc, $refClusterId) as $cs) {
            if ($cs['fix_commit'] !== null) {
                $fixCommits[] = $cs['fix_commit'];
            }
        }

        if (! in_array($reviewedCommit, $fixCommits, true)) {
            return 'Hard gate: commit linkage check failed — review file references commit '.
                "'{$reviewedCommit}' but no callsite in reference cluster '{$refClusterId}' ".
                "has that fix_commit. Review file: {$reviewFilePath}";
        }

        return null;
    }
}
