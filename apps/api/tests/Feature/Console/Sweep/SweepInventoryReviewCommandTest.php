<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Sweep;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Feature test for `php artisan sweep:inventory:review`.
 *
 * Master plan reference:
 *   - Section 4 — workflow state machine (review: under_review → fixed |
 *     in_progress | blocked, depending on verdict).
 *   - Section 5 — review_gate.reviewer_must_differ_from_owner is the
 *     security seam: the actor running the review MUST NOT be the callsite
 *     owner. NO --force override exists for this check.
 *
 * Surface: callsite-scoped only (--cluster refused).
 *
 * Required options:
 *   --callsite-id, --actor, --verdict ∈ {APPROVE, APPROVE-WITH-MINOR-EDITS-APPLIED,
 *   REQUEST-CHANGES, BLOCK}, --review-file=<path>.
 *
 * Optional:
 *   --review-commit=<sha> — pinned for verify_review_commit_linkage clusters.
 *
 * Verdict-to-state mapping:
 *   APPROVE / APPROVE-WITH-MINOR-EDITS-APPLIED → fixed
 *   REQUEST-CHANGES → in_progress (owner re-works)
 *   BLOCK → blocked
 *
 * The review file is the canonical artifact; the --verdict flag is a sanity
 * check. APPROVE-family verdicts also enforce file-parsed `Verdict:` matching
 * --verdict, and (when verify_review_commit_linkage=true) require a
 * `Commit reviewed: <SHA>` line where the SHA matches both --review-commit AND
 * the callsite's stored fix_commit.
 */
class SweepInventoryReviewCommandTest extends TestCase
{
    use SweepInventoryTestSeed;

    private const FIX_COMMIT = 'abcdef0123456789abcdef0123456789abcdef01';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootSweepSeed();
    }

    protected function tearDown(): void
    {
        $this->destroySweepSeed();
        parent::tearDown();
    }

    /**
     * Write a temp review file with the given verdict + (optional) reviewed
     * commit line. Returns its absolute path. Each test gets a unique filename
     * to avoid cross-test bleed when the same temp dir is reused.
     */
    private function writeReviewFile(string $verdict, ?string $reviewedCommit, string $stem = 'review'): string
    {
        $path = $this->tempDir.'/'.$stem.'.md';
        $body = "# Review file for {$stem}\n\n"
            ."Verdict: {$verdict}\n";
        if ($reviewedCommit !== null) {
            $body .= "\nCommit reviewed: {$reviewedCommit}\n";
        }
        file_put_contents($path, $body);

        return $path;
    }

    /**
     * Disable verify_review_commit_linkage on the callsite's owning cluster
     * for tests that focus on verdict-to-state behaviour without the linkage
     * dance. Returns nothing (mutates seed via reseedInventory).
     */
    private function disableLinkageOnTreasury(): void
    {
        $this->reseedInventory(function (array $doc): array {
            /** @var list<array<string, mixed>> $clusters */
            $clusters = $doc['clusters'];
            foreach ($clusters as $idx => $cluster) {
                if (($cluster['id'] ?? null) === 'api.treasury') {
                    /** @var array<string, mixed> $rg */
                    $rg = $cluster['review_gate'];
                    $rg['verify_review_commit_linkage'] = false;
                    $cluster['review_gate'] = $rg;
                    $clusters[$idx] = $cluster;
                }
            }
            $doc['clusters'] = $clusters;

            return $doc;
        });
    }

    public function test_review_approve_transitions_callsite_to_fixed_and_records_review_block(): void
    {
        $this->submitCallsiteForReview('api.treasury.001', 'claude', self::FIX_COMMIT);
        $this->disableLinkageOnTreasury();

        $reviewFile = $this->writeReviewFile('APPROVE', null, 'approve-no-link');

        $exit = Artisan::call('sweep:inventory:review', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--actor' => 'codex',
            '--verdict' => 'APPROVE',
            '--review-file' => $reviewFile,
        ]);

        $this->assertSame(0, $exit, 'review APPROVE must succeed when reviewer differs from owner.');

        $callsite = $this->callsiteById('api.treasury.001');
        $this->assertSame('fixed', $callsite['status']);

        /** @var array<string, mixed> $review */
        $review = $callsite['review'];
        $this->assertSame('codex', $review['reviewer']);
        $this->assertSame('APPROVE', $review['verdict']);
        $this->assertNotNull($review['reviewed_at']);
        $this->assertSame($reviewFile, $review['review_file']);
        $this->assertNull($review['review_commit']);

        /** @var list<array<string, mixed>> $history */
        $history = $callsite['history'];
        $latest = $history[count($history) - 1];
        $this->assertSame('review', $latest['action']);
        $this->assertSame('codex', $latest['actor']);
        $this->assertSame('sweep:inventory:review', $latest['command']);
        $this->assertSame('under_review', $latest['from_status']);
        $this->assertSame('fixed', $latest['to_status']);
        $this->assertSame($reviewFile, $latest['review_file']);
        $this->assertNull($latest['review_commit']);
    }

    public function test_review_approve_with_minor_edits_applied_also_transitions_to_fixed(): void
    {
        $this->submitCallsiteForReview('api.treasury.001', 'claude', self::FIX_COMMIT);
        $this->disableLinkageOnTreasury();

        $reviewFile = $this->writeReviewFile('APPROVE-WITH-MINOR-EDITS-APPLIED', null, 'approve-edits');

        $exit = Artisan::call('sweep:inventory:review', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--actor' => 'codex',
            '--verdict' => 'APPROVE-WITH-MINOR-EDITS-APPLIED',
            '--review-file' => $reviewFile,
        ]);

        $this->assertSame(0, $exit, 'APPROVE-WITH-MINOR-EDITS-APPLIED must transition callsite to fixed.');

        $callsite = $this->callsiteById('api.treasury.001');
        $this->assertSame('fixed', $callsite['status']);
        /** @var array<string, mixed> $review */
        $review = $callsite['review'];
        $this->assertSame('APPROVE-WITH-MINOR-EDITS-APPLIED', $review['verdict']);
    }

    public function test_review_request_changes_transitions_callsite_to_in_progress(): void
    {
        $this->submitCallsiteForReview('api.treasury.001', 'claude', self::FIX_COMMIT);
        // No need to disable linkage — REQUEST-CHANGES does not enforce it.

        $reviewFile = $this->writeReviewFile('REQUEST-CHANGES', null, 'request-changes');

        $exit = Artisan::call('sweep:inventory:review', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--actor' => 'codex',
            '--verdict' => 'REQUEST-CHANGES',
            '--review-file' => $reviewFile,
        ]);

        $this->assertSame(0, $exit, 'REQUEST-CHANGES must succeed and transition to in_progress.');

        $callsite = $this->callsiteById('api.treasury.001');
        $this->assertSame('in_progress', $callsite['status']);
        /** @var array<string, mixed> $review */
        $review = $callsite['review'];
        $this->assertSame('REQUEST-CHANGES', $review['verdict']);
        $this->assertSame('codex', $review['reviewer']);

        /** @var list<array<string, mixed>> $history */
        $history = $callsite['history'];
        $latest = $history[count($history) - 1];
        $this->assertSame('review', $latest['action']);
        $this->assertSame('under_review', $latest['from_status']);
        $this->assertSame('in_progress', $latest['to_status']);
    }

    public function test_review_block_transitions_callsite_to_blocked(): void
    {
        $this->submitCallsiteForReview('api.treasury.001', 'claude', self::FIX_COMMIT);

        $reviewFile = $this->writeReviewFile('BLOCK', null, 'block');

        $exit = Artisan::call('sweep:inventory:review', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--actor' => 'codex',
            '--verdict' => 'BLOCK',
            '--review-file' => $reviewFile,
        ]);

        $this->assertSame(0, $exit, 'BLOCK verdict must succeed and transition to blocked.');

        $callsite = $this->callsiteById('api.treasury.001');
        $this->assertSame('blocked', $callsite['status']);
        /** @var array<string, mixed> $review */
        $review = $callsite['review'];
        $this->assertSame('BLOCK', $review['verdict']);
    }

    public function test_review_refuses_when_reviewer_equals_owner(): void
    {
        // SECURITY INVARIANT: cluster.review_gate.reviewer_must_differ_from_owner=true.
        // Owner is "claude"; reviewer-as-claude must be refused.
        $this->submitCallsiteForReview('api.treasury.001', 'claude', self::FIX_COMMIT);
        $this->disableLinkageOnTreasury();

        $reviewFile = $this->writeReviewFile('APPROVE', null, 'self-review');

        $exit = Artisan::call('sweep:inventory:review', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--actor' => 'claude', // SAME AS OWNER
            '--verdict' => 'APPROVE',
            '--review-file' => $reviewFile,
        ]);

        $this->assertNotSame(
            0,
            $exit,
            'review must REFUSE when --actor equals the callsite owner — security invariant.',
        );

        // YAML must be unchanged.
        $callsite = $this->callsiteById('api.treasury.001');
        $this->assertSame('under_review', $callsite['status']);
        /** @var array<string, mixed> $review */
        $review = $callsite['review'];
        $this->assertNull($review['reviewer']);
        $this->assertNull($review['verdict']);
    }

    public function test_review_refuses_when_callsite_not_under_review(): void
    {
        // Default seed: callsite is pending.
        $reviewFile = $this->writeReviewFile('APPROVE', null);

        $exit = Artisan::call('sweep:inventory:review', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--actor' => 'codex',
            '--verdict' => 'APPROVE',
            '--review-file' => $reviewFile,
        ]);

        $this->assertNotSame(0, $exit, 'review must refuse when callsite is not under_review.');

        $callsite = $this->callsiteById('api.treasury.001');
        $this->assertSame('pending', $callsite['status']);
    }

    public function test_review_refuses_when_review_file_does_not_exist(): void
    {
        $this->submitCallsiteForReview('api.treasury.001', 'claude', self::FIX_COMMIT);

        $exit = Artisan::call('sweep:inventory:review', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--actor' => 'codex',
            '--verdict' => 'APPROVE',
            '--review-file' => $this->tempDir.'/no-such-review.md',
        ]);

        $this->assertNotSame(0, $exit, 'review must refuse when --review-file does not exist on disk.');

        $callsite = $this->callsiteById('api.treasury.001');
        $this->assertSame('under_review', $callsite['status']);
    }

    public function test_review_refuses_when_verdict_in_file_does_not_match_flag(): void
    {
        $this->submitCallsiteForReview('api.treasury.001', 'claude', self::FIX_COMMIT);
        $this->disableLinkageOnTreasury();

        // File says REQUEST-CHANGES; flag says APPROVE. The file is canonical;
        // refuse with a clear mismatch error.
        $reviewFile = $this->writeReviewFile('REQUEST-CHANGES', null, 'mismatch');

        $exit = Artisan::call('sweep:inventory:review', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--actor' => 'codex',
            '--verdict' => 'APPROVE',
            '--review-file' => $reviewFile,
        ]);

        $this->assertNotSame(
            0,
            $exit,
            'review must refuse when the file-parsed Verdict line does not match --verdict.',
        );

        $callsite = $this->callsiteById('api.treasury.001');
        $this->assertSame('under_review', $callsite['status']);
    }

    public function test_review_refuses_when_commit_linkage_required_and_review_commit_missing(): void
    {
        // Treasury seed has verify_review_commit_linkage=true. Callsite has a
        // fix_commit. Reviewer omits --review-commit AND the file lacks a
        // `Commit reviewed:` line. Refuse.
        $this->submitCallsiteForReview('api.treasury.001', 'claude', self::FIX_COMMIT);

        $reviewFile = $this->writeReviewFile('APPROVE', null, 'no-commit-line');

        $exit = Artisan::call('sweep:inventory:review', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--actor' => 'codex',
            '--verdict' => 'APPROVE',
            '--review-file' => $reviewFile,
        ]);

        $this->assertNotSame(
            0,
            $exit,
            'review APPROVE must refuse when commit linkage is required and --review-commit is missing.',
        );

        $callsite = $this->callsiteById('api.treasury.001');
        $this->assertSame('under_review', $callsite['status']);
    }

    public function test_review_refuses_when_commit_linkage_required_and_sha_does_not_match_callsite_fix_commit(): void
    {
        $this->submitCallsiteForReview('api.treasury.001', 'claude', self::FIX_COMMIT);

        // File pins a different SHA than the callsite's fix_commit.
        $wrongSha = 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeef';
        $reviewFile = $this->writeReviewFile('APPROVE', $wrongSha, 'wrong-sha');

        $exit = Artisan::call('sweep:inventory:review', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--actor' => 'codex',
            '--verdict' => 'APPROVE',
            '--review-file' => $reviewFile,
            '--review-commit' => $wrongSha,
        ]);

        $this->assertNotSame(
            0,
            $exit,
            'review APPROVE must refuse when reviewed SHA does not match callsite fix_commit.',
        );

        $callsite = $this->callsiteById('api.treasury.001');
        $this->assertSame('under_review', $callsite['status']);
    }

    public function test_review_refuses_invalid_verdict(): void
    {
        $this->submitCallsiteForReview('api.treasury.001', 'claude', self::FIX_COMMIT);
        $this->disableLinkageOnTreasury();

        $reviewFile = $this->writeReviewFile('APPROVE', null, 'invalid-flag');

        $exit = Artisan::call('sweep:inventory:review', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--actor' => 'codex',
            '--verdict' => 'LGTM',
            '--review-file' => $reviewFile,
        ]);

        $this->assertNotSame(0, $exit, '--verdict must be one of the four allowed values.');
    }

    public function test_review_refuses_cluster_mode(): void
    {
        $this->submitCallsiteForReview('api.treasury.001', 'claude', self::FIX_COMMIT);
        $reviewFile = $this->writeReviewFile('APPROVE', null, 'cluster-mode');

        $exit = Artisan::call('sweep:inventory:review', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--cluster' => 'api.treasury',
            '--actor' => 'codex',
            '--verdict' => 'APPROVE',
            '--review-file' => $reviewFile,
        ]);

        $this->assertNotSame(0, $exit, 'review is callsite-scoped; --cluster must be refused.');
    }

    /**
     * The schema's review.reviewer enum is `claude|codex` only. Without an
     * explicit pre-validation the command would write `--actor=human` into the
     * review block and fail at JSON Schema validation in
     * InventoryService::mutate() with an opaque error. Surface clearly here.
     */
    public function test_review_refuses_when_actor_is_human_or_ci(): void
    {
        $this->submitCallsiteForReview('api.treasury.001', 'claude', self::FIX_COMMIT);
        $reviewFile = $this->writeReviewFile('APPROVE', null, 'actor-human');

        $exitHuman = Artisan::call('sweep:inventory:review', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--actor' => 'human',
            '--verdict' => 'APPROVE',
            '--review-file' => $reviewFile,
        ]);
        $this->assertNotSame(0, $exitHuman, '--actor=human must be refused for review (schema review.reviewer accepts claude|codex only).');

        $exitCi = Artisan::call('sweep:inventory:review', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--actor' => 'ci',
            '--verdict' => 'APPROVE',
            '--review-file' => $reviewFile,
        ]);
        $this->assertNotSame(0, $exitCi, '--actor=ci must be refused for review (schema review.reviewer accepts claude|codex only).');

        // YAML must remain unchanged after both refusals.
        $callsite = $this->callsiteById('api.treasury.001');
        $this->assertSame('under_review', $callsite['status']);
        /** @var array<string, mixed> $review */
        $review = $callsite['review'];
        $this->assertNull($review['reviewer'], 'review.reviewer must remain unset after refusal.');
    }

    public function test_review_approve_with_full_linkage_succeeds_when_review_commit_matches_fix_commit(): void
    {
        $this->submitCallsiteForReview('api.treasury.001', 'claude', self::FIX_COMMIT);

        $reviewFile = $this->writeReviewFile('APPROVE', self::FIX_COMMIT, 'full-linkage');

        $exit = Artisan::call('sweep:inventory:review', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--actor' => 'codex',
            '--verdict' => 'APPROVE',
            '--review-file' => $reviewFile,
            '--review-commit' => self::FIX_COMMIT,
        ]);

        $this->assertSame(0, $exit, 'review APPROVE must succeed when commit linkage matches fix_commit.');

        $callsite = $this->callsiteById('api.treasury.001');
        $this->assertSame('fixed', $callsite['status']);
        /** @var array<string, mixed> $review */
        $review = $callsite['review'];
        $this->assertSame(self::FIX_COMMIT, $review['review_commit']);

        /** @var list<array<string, mixed>> $history */
        $history = $callsite['history'];
        $latest = $history[count($history) - 1];
        $this->assertSame(self::FIX_COMMIT, $latest['review_commit']);
    }

    /**
     * Codex BLOCK finding #1: cluster roll-up to `fixed`. When the LAST callsite
     * in a cluster is reviewed APPROVE-family, the cluster status must also
     * transition to `fixed` in the same mutate() call. Without this roll-up the
     * Treasury hard gate would never open via the artisan workflow. The cluster
     * id is included in history.target_ids so the audit chain captures it.
     */
    public function test_review_approve_rolls_up_cluster_to_fixed_when_last_callsite_approved(): void
    {
        // Treasury seed has exactly one callsite (api.treasury.001). After it
        // is reviewed APPROVE the cluster's only callsite is fixed → cluster
        // must roll up to fixed.
        $this->submitCallsiteForReview('api.treasury.001', 'claude', self::FIX_COMMIT);
        $reviewFile = $this->writeReviewFile('APPROVE', self::FIX_COMMIT, 'cluster-rollup');

        $exit = Artisan::call('sweep:inventory:review', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--actor' => 'codex',
            '--verdict' => 'APPROVE',
            '--review-file' => $reviewFile,
            '--review-commit' => self::FIX_COMMIT,
        ]);

        $this->assertSame(0, $exit);

        $callsite = $this->callsiteById('api.treasury.001');
        $this->assertSame('fixed', $callsite['status']);

        $cluster = $this->clusterById('api.treasury');
        $this->assertSame(
            'fixed',
            $cluster['status'],
            'cluster must roll up to `fixed` once every callsite in it is fixed (Codex BLOCK finding #1).',
        );

        /** @var list<array<string, mixed>> $history */
        $history = $callsite['history'];
        $latest = $history[count($history) - 1];
        $this->assertContains(
            'api.treasury',
            $latest['target_ids'],
            'history.target_ids must include the cluster id when roll-up fires (audit chain captures cluster transition).',
        );
        $this->assertContains('api.treasury.001', $latest['target_ids']);
    }

    /**
     * Codex BLOCK finding #1: end-to-end Treasury → non-Treasury workflow.
     * Drives the FULL nine-command workflow against api.treasury (no direct
     * YAML reseeding for the cluster status), then verifies that the Treasury
     * hard gate opens for a subsequent api.document claim. This is the test
     * the prior implementation could not pass because the cluster never rolled
     * up to fixed via the workflow.
     */
    public function test_end_to_end_treasury_workflow_opens_hard_gate_for_non_treasury_claim(): void
    {
        // Pre-seed: re-point Treasury's review_gate.review_file to a temp path
        // the test controls. The seed's default value is a relative path that
        // doesn't exist on disk, which the hard gate would refuse. The
        // workflow does NOT mutate review_gate.review_file (it's a per-cluster
        // SPECIFICATION of where the review is expected); operators set it
        // when defining the cluster, and the review writer drops the file at
        // that path. Mirror that in the test.
        $reviewFile = $this->tempDir.'/treasury-e2e-review.md';
        $this->reseedInventory(function (array $doc) use ($reviewFile): array {
            /** @var list<array<string, mixed>> $clusters */
            $clusters = $doc['clusters'];
            foreach ($clusters as $idx => $c) {
                if (($c['id'] ?? null) === 'api.treasury') {
                    /** @var array<string, mixed> $rg */
                    $rg = $c['review_gate'];
                    $rg['review_file'] = $reviewFile;
                    $c['review_gate'] = $rg;
                    $clusters[$idx] = $c;
                }
            }
            $doc['clusters'] = $clusters;

            return $doc;
        });

        // 1. claim Treasury (cluster mode → cluster owner=claude + callsite owner=claude).
        $this->assertSame(0, Artisan::call('sweep:inventory:claim', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--cluster' => 'api.treasury',
            '--actor' => 'claude',
        ]));

        // 2. start Treasury (cluster mode).
        $this->assertSame(0, Artisan::call('sweep:inventory:start', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--cluster' => 'api.treasury',
            '--actor' => 'claude',
        ]));

        // 3. submit the only Treasury callsite (callsite mode).
        $this->assertSame(0, Artisan::call('sweep:inventory:submit', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--actor' => 'claude',
            '--commit' => self::FIX_COMMIT,
            '--test' => 'tests/Feature/Treasury/TreasuryTenantIsolationTest.php::test_baseline',
        ]));

        // 4. Reviewer (codex — different agent than the owner claude) writes
        //    the review file at the path the cluster's review_gate declares,
        //    then runs the review command pointing at that same path.
        file_put_contents(
            $reviewFile,
            "# Treasury cluster review (e2e)\n\n"
                ."Verdict: APPROVE\n\n"
                .'Commit reviewed: '.self::FIX_COMMIT."\n",
        );
        $this->assertSame(0, Artisan::call('sweep:inventory:review', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--actor' => 'codex',
            '--verdict' => 'APPROVE',
            '--review-file' => $reviewFile,
            '--review-commit' => self::FIX_COMMIT,
        ]));

        // Pre-condition for the hard gate: Treasury must be `fixed` now (cluster roll-up).
        $treasuryCluster = $this->clusterById('api.treasury');
        $this->assertSame('fixed', $treasuryCluster['status'], 'Treasury cluster must be fixed after the workflow.');

        // 5. claim api.document (non-Treasury) → Treasury hard gate must now open.
        $exit = Artisan::call('sweep:inventory:claim', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--cluster' => 'api.document',
            '--actor' => 'codex',
        ]);

        $this->assertSame(
            0,
            $exit,
            'Treasury hard gate must open for non-Treasury claims after the full workflow drives Treasury to fixed.',
        );

        $documentCluster = $this->clusterById('api.document');
        $this->assertSame('claimed', $documentCluster['status']);
    }

    /**
     * Codex BLOCK finding #3: verdict reconciliation must apply to ALL four
     * verdicts, not only APPROVE-family. A hostile reviewer must not be able
     * to flag --verdict=BLOCK against a review file that says
     * `Verdict: APPROVE`, or vice-versa.
     */
    public function test_review_refuses_when_request_changes_flag_does_not_match_file_verdict(): void
    {
        $this->submitCallsiteForReview('api.treasury.001', 'claude', self::FIX_COMMIT);
        // File says APPROVE; flag says REQUEST-CHANGES → mismatch must refuse.
        $reviewFile = $this->writeReviewFile('APPROVE', null, 'rc-mismatch');

        $exit = Artisan::call('sweep:inventory:review', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--actor' => 'codex',
            '--verdict' => 'REQUEST-CHANGES',
            '--review-file' => $reviewFile,
        ]);

        $this->assertNotSame(
            0,
            $exit,
            '--verdict=REQUEST-CHANGES with a file `Verdict: APPROVE` must be refused.',
        );

        // Status must remain under_review.
        $callsite = $this->callsiteById('api.treasury.001');
        $this->assertSame('under_review', $callsite['status']);
    }

    public function test_review_refuses_when_block_flag_does_not_match_file_verdict(): void
    {
        $this->submitCallsiteForReview('api.treasury.001', 'claude', self::FIX_COMMIT);
        $reviewFile = $this->writeReviewFile('APPROVE', null, 'block-mismatch');

        $exit = Artisan::call('sweep:inventory:review', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--actor' => 'codex',
            '--verdict' => 'BLOCK',
            '--review-file' => $reviewFile,
        ]);

        $this->assertNotSame(
            0,
            $exit,
            '--verdict=BLOCK with a file `Verdict: APPROVE` must be refused.',
        );

        $callsite = $this->callsiteById('api.treasury.001');
        $this->assertSame('under_review', $callsite['status']);
    }

    /**
     * Codex BLOCK finding #4: review commit linkage must accept the same
     * short-SHA contract as the Treasury hard gate (>=7 char unique prefix).
     * The previously-implemented exact-equality check rejected normal git
     * abbreviations.
     */
    public function test_review_approve_accepts_unique_short_sha_review_commit(): void
    {
        $this->submitCallsiteForReview('api.treasury.001', 'claude', self::FIX_COMMIT);
        $shortSha = substr(self::FIX_COMMIT, 0, 7);
        $reviewFile = $this->writeReviewFile('APPROVE', $shortSha, 'short-sha');

        $exit = Artisan::call('sweep:inventory:review', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--actor' => 'codex',
            '--verdict' => 'APPROVE',
            '--review-file' => $reviewFile,
            '--review-commit' => $shortSha,
        ]);

        $this->assertSame(
            0,
            $exit,
            'review APPROVE must accept a unique 7-char short SHA that prefix-matches the callsite fix_commit (Codex BLOCK finding #4).',
        );

        $callsite = $this->callsiteById('api.treasury.001');
        $this->assertSame('fixed', $callsite['status']);
    }
}
