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
 * APPROVE-family checks (in order, all must pass):
 *   1. The file-parsed `Verdict: <X>` line must equal --verdict (file is canonical;
 *      flag is sanity).
 *   2. If verify_review_commit_linkage=true on the owning cluster:
 *      a) --review-commit must be supplied (non-empty), AND
 *      b) the file must contain a `Commit reviewed: <SHA>` line, AND
 *      c) that SHA must equal --review-commit, AND
 *      d) it must equal the callsite's stored fix_commit.
 *
 * REQUEST-CHANGES and BLOCK do not enforce file-verdict matching beyond the
 * --verdict flag, since their authoritative content is the prose of the file
 * itself rather than a structured verdict marker.
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

        // ── 6. APPROVE-family file checks ───────────────────────────────────
        $reviewFileContents = (string) file_get_contents($reviewFile);

        if (in_array($verdict, self::APPROVE_FAMILY, true)) {
            $approveError = $this->enforceApproveFamilyChecks(
                cluster: $cluster,
                callsite: $callsite,
                flagVerdict: $verdict,
                reviewCommitFlag: $reviewCommit,
                reviewFilePath: $reviewFile,
                reviewFileContents: $reviewFileContents,
            );
            if ($approveError !== null) {
                $this->error($approveError);

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
        $note = "review verdict={$verdict} by {$actor} via sweep:inventory:review";
        $context = $this->makeMutationContext($actor);

        try {
            $service->mutate(
                function (InventoryDocument $live) use (
                    $callsiteId, $actor, $verdict, $reviewedAt, $reviewFile, $reviewCommit, $toStatus, $note
                ): InventoryDocument {
                    return $live->withCallsiteUpdate(
                        $callsiteId,
                        function (array $cs) use (
                            $callsiteId, $actor, $verdict, $reviewedAt, $reviewFile, $reviewCommit, $toStatus, $note
                        ): array {
                            $cs['status'] = $toStatus;
                            $cs['review'] = [
                                'reviewer' => $actor,
                                'verdict' => $verdict,
                                'reviewed_at' => $reviewedAt,
                                'review_file' => $reviewFile,
                                'review_commit' => $reviewCommit,
                            ];
                            $cs['history'][] = $this->makeHistoryEvent(
                                action: 'review',
                                fromStatus: 'under_review',
                                toStatus: $toStatus,
                                targetIds: [$callsiteId],
                                note: $note,
                                reviewFile: $reviewFile,
                                reviewCommit: $reviewCommit,
                            );

                            return $cs;
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
     * Enforce the APPROVE-family checks:
     *   - file `Verdict:` line matches --verdict
     *   - if cluster requires linkage: --review-commit supplied + file
     *     `Commit reviewed:` line matches it + matches callsite fix_commit
     *
     * Returns null on success, an error string on failure.
     *
     * @param  Cluster  $cluster
     * @param  Callsite  $callsite
     */
    private function enforceApproveFamilyChecks(
        array $cluster,
        array $callsite,
        string $flagVerdict,
        ?string $reviewCommitFlag,
        string $reviewFilePath,
        string $reviewFileContents,
    ): ?string {
        // (a) File-parsed verdict must match --verdict.
        $parsedVerdict = $this->parseVerdictLine($reviewFileContents);
        if ($parsedVerdict === null) {
            return "Review file contains no 'Verdict: <X>' line: {$reviewFilePath}";
        }
        if ($parsedVerdict !== $flagVerdict) {
            return "Review file Verdict line is '{$parsedVerdict}' but --verdict is ".
                "'{$flagVerdict}'. The file is canonical; either correct the file or ".
                "the flag. Review file: {$reviewFilePath}";
        }

        // (b) Commit linkage enforcement (cluster opt-in).
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

        if ($parsedReviewedCommit !== $reviewCommitFlag) {
            return "Review file 'Commit reviewed' line is '{$parsedReviewedCommit}' but ".
                "--review-commit is '{$reviewCommitFlag}'. They must match. ".
                "Review file: {$reviewFilePath}";
        }

        $callsiteFixCommit = $callsite['fix_commit'];
        if ($callsiteFixCommit === null) {
            return "Cluster '{$cluster['id']}' requires verify_review_commit_linkage but ".
                "callsite '{$callsite['id']}' has no fix_commit recorded. Run sweep:inventory:submit first.";
        }

        if ($reviewCommitFlag !== $callsiteFixCommit) {
            return "Commit linkage check failed — --review-commit '{$reviewCommitFlag}' does ".
                "not match callsite fix_commit '{$callsiteFixCommit}'. The reviewer must ".
                'pin the SHA the worker actually committed.';
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
