<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Sweep\Domain\InventoryDocument;
use InvalidArgumentException;
use Throwable;

/**
 * `php artisan sweep:inventory:review`
 *
 * Records a review verdict against a single under_review callsite, transitioning
 * its status per the verdict and writing a review block + history event.
 *
 * Master plan reference:
 *   Section 4 — workflow state machine (review verb).
 *   Section 5 — cluster.review_gate.reviewer_must_differ_from_owner is the
 *     SECURITY SEAM. The actor running this command MUST NOT be the callsite
 *     owner. NO --force override exists for this check.
 *
 * Surface: callsite-scoped only. --cluster is refused.
 *
 * Verdict-to-state mapping:
 *   APPROVE / APPROVE-WITH-MINOR-EDITS-APPLIED → fixed
 *   REQUEST-CHANGES → in_progress (owner re-works)
 *   BLOCK → blocked
 *
 * Required options:
 *   --callsite-id, --verdict ∈ {4 values}, --review-file=<path>,
 *   --actor=<claude|codex|ci|human> (review block requires claude|codex per the
 *   schema; ci/human will be rejected by JSON Schema validation if used).
 *
 * Optional:
 *   --review-commit=<sha>. Required when the callsite's cluster has
 *   review_gate.verify_review_commit_linkage=true AND the verdict is in the
 *   APPROVE family.
 *
 * Verdict reconciliation (ALL four verdicts):
 *   The file-parsed `Verdict: <X>` line MUST equal --verdict. The file is the
 *   canonical artifact; the flag is a sanity check. A file declaring
 *   `Verdict: APPROVE` cannot be paired with `--verdict=BLOCK` and vice-versa.
 *
 * Commit linkage (APPROVE family only, when cluster opts in):
 *   1. --review-commit must be supplied (non-empty), AND
 *   2. the file must contain a `Commit reviewed: <SHA>` line, AND
 *   3. --review-commit must identify the callsite's stored fix_commit (full
 *      40-char SHA OR a unique short >=7-char SHA — same matcher as the
 *      Treasury hard gate).
 *
 * Cluster roll-up to `fixed`:
 *   On APPROVE-family verdicts, after updating the reviewed callsite, the
 *   cluster is also rolled up to `fixed` IFF every callsite in that cluster
 *   is now `fixed`. The cluster id is appended to history.target_ids so the
 *   audit chain captures the cluster-level transition. Without this roll-up,
 *   the Treasury hard gate would never open through the artisan workflow.
 *
 * @phpstan-import-type Callsite from InventoryDocument
 * @phpstan-import-type Cluster from InventoryDocument
 * @phpstan-import-type ReviewBlock from InventoryDocument
 */
final class SweepInventoryReviewCommand extends AbstractSweepInventoryCommand
{
    /**
     * The four verdicts the schema accepts on a review block.
     *
     * @var list<string>
     */
    private const ALLOWED_VERDICTS = [
        'APPROVE',
        'APPROVE-WITH-MINOR-EDITS-APPLIED',
        'REQUEST-CHANGES',
        'BLOCK',
    ];

    /**
     * Verdicts whose file-parsed value must match the --verdict flag and which
     * trigger commit-linkage enforcement (when the cluster opts in).
     *
     * @var list<string>
     */
    private const APPROVE_FAMILY = [
        'APPROVE',
        'APPROVE-WITH-MINOR-EDITS-APPLIED',
    ];

    /** @var string */
    protected $signature = 'sweep:inventory:review
        {--inventory-path= : path to YAML (overrides default for tests)}
        {--schema-path= : path to JSON Schema (overrides default for tests)}
        {--callsite-id= : callsite under review}
        {--cluster= : NOT supported — review is callsite-scoped}
        {--actor=human : claude|codex|ci|human (NOT generator — reserved). MUST differ from callsite owner.}
        {--verdict= : APPROVE|APPROVE-WITH-MINOR-EDITS-APPLIED|REQUEST-CHANGES|BLOCK}
        {--review-file= : path to the review markdown (must exist on disk)}
        {--review-commit= : git SHA the verdict pins to (required for APPROVE-family when commit linkage is enabled)}';

    /** @var string */
    protected $description = 'Record a review verdict on a callsite, transitioning under_review → fixed/in_progress/blocked.';

    protected function actionVerb(): string
    {
        return 'review';
    }

    protected function commandName(): string
    {
        return 'sweep:inventory:review';
    }

    public function handle(): int
    {
        // ── 1. Refuse --cluster mode ────────────────────────────────────────
        if ($this->stringOption('cluster') !== null) {
            $this->error('review is callsite-scoped; supply --callsite-id, not --cluster.');

            return self::FAILURE;
        }

        // ── 2. Required options ─────────────────────────────────────────────
        $callsiteId = $this->stringOption('callsite-id');
        if ($callsiteId === null) {
            $this->error('You must supply --callsite-id.');

            return self::FAILURE;
        }

        $verdict = $this->stringOption('verdict');
        if ($verdict === null) {
            $this->error(
                'You must supply --verdict, one of: '.implode(', ', self::ALLOWED_VERDICTS).'.',
            );

            return self::FAILURE;
        }
        if (! in_array($verdict, self::ALLOWED_VERDICTS, true)) {
            $this->error(
                "Invalid --verdict '{$verdict}'. Allowed values: ".implode(', ', self::ALLOWED_VERDICTS).'.',
            );

            return self::FAILURE;
        }

        $reviewFile = $this->stringOption('review-file');
        if ($reviewFile === null) {
            $this->error('You must supply --review-file (path to the review markdown).');

            return self::FAILURE;
        }
        if (! is_file($reviewFile)) {
            $this->error("Review file does not exist on disk: {$reviewFile}");

            return self::FAILURE;
        }

        $reviewCommit = $this->stringOption('review-commit'); // optional

        // ── 3. Resolve actor ────────────────────────────────────────────────
        try {
            $actor = $this->resolveActor();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // The schema's review.reviewer field accepts only `claude` or `codex`
        // (not `ci`/`human`). Refuse here so the failure surfaces with a clear
        // message instead of an opaque JSON Schema validation error from
        // InventoryService::mutate() at write time.
        if ($actor !== 'claude' && $actor !== 'codex') {
            $this->error(
                "Review refused: --actor '{$actor}' is not a recognized review agent. ".
                'Only claude or codex may write a review (schema review.reviewer enum).',
            );

            return self::FAILURE;
        }

        // ── 4. Load + look up callsite + cluster ────────────────────────────
        $service = $this->makeInventoryService();

        try {
            $doc = $service->load();
        } catch (Throwable $t) {
            $this->error('Failed to load inventory: '.$t->getMessage());

            return self::FAILURE;
        }

        $callsite = $this->findCallsite($doc, $callsiteId);
        if ($callsite === null) {
            $this->error("Callsite not found: {$callsiteId}");

            return self::FAILURE;
        }

        if ($callsite['status'] !== 'under_review') {
            $this->error(
                "Callsite {$callsiteId} has status '{$callsite['status']}'; ".
                'only under_review callsites can be reviewed.',
            );

            return self::FAILURE;
        }

        $cluster = $this->findCluster($doc, $callsite['cluster_id']);
        if ($cluster === null) {
            $this->error("Cluster not found for callsite: {$callsite['cluster_id']}");

            return self::FAILURE;
        }

        // ── 5. SECURITY INVARIANT: reviewer MUST differ from owner ──────────
        $owner = $callsite['owner'];
        if ($owner !== null && $owner === $actor) {
            $this->error(
                "Review refused: --actor '{$actor}' equals callsite owner '{$owner}'. ".
                "Cluster '{$cluster['id']}' has review_gate.reviewer_must_differ_from_owner=true; ".
                'a different agent must run the review. There is NO --force override for this check.',
            );

            return self::FAILURE;
        }

        // ── 6a. Verdict reconciliation: ALL four verdicts must match the file ─
        // The file's `Verdict: <X>` line is the canonical artifact; --verdict
        // is a sanity check. Mismatch → refuse, regardless of verdict family.
        // Closes the Codex BLOCK gap: previously this was bypassed for
        // REQUEST-CHANGES and BLOCK, allowing a hostile reviewer to flag any
        // verdict against any review file.
        $reviewFileContents = (string) file_get_contents($reviewFile);
        $verdictReconciliationError = $this->enforceVerdictReconciliation(
            flagVerdict: $verdict,
            reviewFilePath: $reviewFile,
            reviewFileContents: $reviewFileContents,
        );
        if ($verdictReconciliationError !== null) {
            $this->error($verdictReconciliationError);

            return self::FAILURE;
        }

        // ── 6b. APPROVE-family commit-linkage check (cluster opt-in) ──────────
        if (in_array($verdict, self::APPROVE_FAMILY, true)) {
            $linkageError = $this->enforceApproveFamilyLinkage(
                cluster: $cluster,
                callsite: $callsite,
                reviewCommitFlag: $reviewCommit,
                reviewFilePath: $reviewFile,
                reviewFileContents: $reviewFileContents,
            );
            if ($linkageError !== null) {
                $this->error($linkageError);

                return self::FAILURE;
            }
        }

        // ── 7. Determine the target status from the verdict ─────────────────
        // Verdict was validated against ALLOWED_VERDICTS above, so all four
        // arms cover the entire input domain — no default branch needed.
        $toStatus = match ($verdict) {
            'APPROVE', 'APPROVE-WITH-MINOR-EDITS-APPLIED' => 'fixed',
            'REQUEST-CHANGES' => 'in_progress',
            'BLOCK' => 'blocked',
        };

        // ── 8. Mutation ─────────────────────────────────────────────────────
        $reviewedAt = gmdate('Y-m-d\TH:i:s\Z');
        $clusterId = $cluster['id'];
        $isApproveFamily = in_array($verdict, self::APPROVE_FAMILY, true);
        $context = $this->makeMutationContext($actor);

        try {
            $service->mutate(
                function (InventoryDocument $live) use (
                    $callsiteId, $clusterId, $actor, $verdict, $reviewedAt, $reviewFile, $reviewCommit, $toStatus, $isApproveFamily
                ): InventoryDocument {
                    // (a) Update the reviewed callsite first.
                    $live = $live->withCallsiteUpdate(
                        $callsiteId,
                        function (array $cs) use (
                            $callsiteId, $clusterId, $actor, $verdict, $reviewedAt, $reviewFile, $reviewCommit, $toStatus, $isApproveFamily
                        ): array {
                            $cs['status'] = $toStatus;
                            $cs['review'] = [
                                'reviewer' => $actor,
                                'verdict' => $verdict,
                                'reviewed_at' => $reviewedAt,
                                'review_file' => $reviewFile,
                                'review_commit' => $reviewCommit,
                            ];

                            // Cluster roll-up (Codex BLOCK finding #1): on
                            // APPROVE-family verdicts the cluster id is
                            // included in target_ids so the audit trail
                            // captures the cluster-level transition when the
                            // post-mutation siblings check below promotes it.
                            $targetIds = $isApproveFamily
                                ? [$callsiteId, $clusterId]
                                : [$callsiteId];

                            $note = "review verdict={$verdict} by {$actor} via sweep:inventory:review";
                            $cs['history'][] = $this->makeHistoryEvent(
                                action: 'review',
                                fromStatus: 'under_review',
                                toStatus: $toStatus,
                                targetIds: $targetIds,
                                note: $note,
                                reviewFile: $reviewFile,
                                reviewCommit: $reviewCommit,
                            );

                            return $cs;
                        },
                    );

                    // (b) Cluster roll-up: on APPROVE-family verdicts, if every
                    //     callsite in the cluster is now `fixed`, also
                    //     transition the cluster to `fixed`. The cluster's
                    //     status update goes into the SAME mutate() call so the
                    //     audit chain stays anchored on the callsite history
                    //     event recorded in (a). Without this, the Treasury
                    //     hard gate would never open via the workflow.
                    if (! $isApproveFamily || $toStatus !== 'fixed') {
                        return $live;
                    }

                    $allCallsitesFixed = true;
                    foreach ($live->callsites() as $sibling) {
                        if ($sibling['cluster_id'] === $clusterId
                            && $sibling['status'] !== 'fixed'
                        ) {
                            $allCallsitesFixed = false;
                            break;
                        }
                    }

                    if (! $allCallsitesFixed) {
                        return $live;
                    }

                    return $live->withClusterUpdate(
                        $clusterId,
                        static function (array $c): array {
                            $c['status'] = 'fixed';

                            return $c;
                        },
                    );
                },
                $context,
            );
        } catch (Throwable $t) {
            $this->error('Failed to persist review: '.$t->getMessage());

            return self::FAILURE;
        }

        $this->line(
            "Reviewed callsite {$callsiteId} (verdict={$verdict}, actor={$actor}, status→{$toStatus}).",
        );

        return self::SUCCESS;
    }

    /**
     * Verdict reconciliation: the file-parsed `Verdict: <X>` line MUST equal
     * the --verdict flag. Applies uniformly to all four verdicts. Returns null
     * on success, an error string on failure.
     */
    private function enforceVerdictReconciliation(
        string $flagVerdict,
        string $reviewFilePath,
        string $reviewFileContents,
    ): ?string {
        $parsedVerdict = $this->parseVerdictLine($reviewFileContents);
        if ($parsedVerdict === null) {
            return "Review file contains no 'Verdict: <X>' line: {$reviewFilePath}";
        }
        if ($parsedVerdict !== $flagVerdict) {
            return "Review file Verdict line is '{$parsedVerdict}' but --verdict is ".
                "'{$flagVerdict}'. The file is canonical; either correct the file or ".
                "the flag. Review file: {$reviewFilePath}";
        }

        return null;
    }

    /**
     * APPROVE-family commit-linkage check (cluster opt-in via
     * review_gate.verify_review_commit_linkage). Verifies:
     *   1. --review-commit is supplied,
     *   2. the file has a `Commit reviewed: <SHA>` line that identifies the
     *      same stored fix_commit as --review-commit (full or unique short SHA),
     *   3. --review-commit identifies the callsite's stored fix_commit.
     *
     * Reuses {@see self::commitIdentifierMatchesStored()} from the base class
     * so the matching contract is shared with the Treasury hard gate.
     *
     * @param  Cluster  $cluster
     * @param  Callsite  $callsite
     */
    private function enforceApproveFamilyLinkage(
        array $cluster,
        array $callsite,
        ?string $reviewCommitFlag,
        string $reviewFilePath,
        string $reviewFileContents,
    ): ?string {
        if (! $cluster['review_gate']['verify_review_commit_linkage']) {
            return null;
        }

        if ($reviewCommitFlag === null) {
            return "Cluster '{$cluster['id']}' requires verify_review_commit_linkage; ".
                'you must supply --review-commit=<sha> on APPROVE-family verdicts.';
        }

        $parsedReviewedCommit = $this->parseReviewedCommitLine($reviewFileContents);
        if ($parsedReviewedCommit === null) {
            return "Cluster '{$cluster['id']}' requires verify_review_commit_linkage but the ".
                "review file has no 'Commit reviewed: <SHA>' line: {$reviewFilePath}";
        }

        $callsiteFixCommit = $callsite['fix_commit'];
        if ($callsiteFixCommit === null) {
            return "Cluster '{$cluster['id']}' requires verify_review_commit_linkage but ".
                "callsite '{$callsite['id']}' has no fix_commit recorded. Run sweep:inventory:submit first.";
        }

        $storedFixCommits = [$callsiteFixCommit];

        if (! $this->commitIdentifierMatchesStored($reviewCommitFlag, $storedFixCommits)) {
            return "Commit linkage check failed — --review-commit '{$reviewCommitFlag}' does ".
                "not identify callsite fix_commit '{$callsiteFixCommit}' (full 40-char or unique ".
                'short >=7-char SHA accepted). The reviewer must pin the SHA the worker actually committed.';
        }

        if (! $this->commitIdentifierMatchesStored($parsedReviewedCommit, $storedFixCommits)) {
            return "Review file 'Commit reviewed' line is '{$parsedReviewedCommit}' but it does ".
                "not identify callsite fix_commit '{$callsiteFixCommit}' (full 40-char or unique ".
                "short >=7-char SHA accepted). Review file: {$reviewFilePath}";
        }

        return null;
    }

    /**
     * Parse the first `Verdict: <X>` line from the review file contents.
     * Tolerant of whitespace variants; rejects indented/quoted lines so a
     * `> Verdict: …` block-quote of a prior round's sub-review doesn't
     * accidentally match. Returns null when no such line exists.
     *
     * (Mirrors the parser in SweepInventoryClaimCommand. Kept as a private
     * helper here rather than extracted to the base class — see kickoff brief
     * for the deliberate choice to defer extraction until a third caller
     * appears.)
     */
    private function parseVerdictLine(string $contents): ?string
    {
        foreach (explode("\n", $contents) as $line) {
            if (preg_match('/^Verdict:\s+(\S.*)$/', $line, $matches) === 1) {
                return trim($matches[1]);
            }
        }

        return null;
    }

    /**
     * Parse the first `Commit reviewed: <SHA>` line. Same tolerance + same
     * intentional rejection of indented/quoted lines.
     */
    private function parseReviewedCommitLine(string $contents): ?string
    {
        foreach (explode("\n", $contents) as $line) {
            if (preg_match('/^Commit reviewed:\s+(\S.*)$/', $line, $matches) === 1) {
                $value = trim($matches[1]);

                return $value === '' ? null : $value;
            }
        }

        return null;
    }
}
