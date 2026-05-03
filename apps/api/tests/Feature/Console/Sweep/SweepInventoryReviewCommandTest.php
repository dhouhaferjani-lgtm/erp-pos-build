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
}
