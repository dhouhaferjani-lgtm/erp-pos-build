<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Sweep;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Feature test for `php artisan sweep:inventory:claim`.
 *
 * Master plan reference:
 *   - Section 4 — workflow state machine (claim: pending → claimed).
 *   - Section 7 — Treasury HARD GATE: any non-Treasury claim refuses if
 *     Treasury cluster status != "fixed", or review file/verdict/commit
 *     linkage check fails.
 *
 * The command supports two surfaces:
 *   - per-callsite claim: --callsite-id=api.treasury.001 --actor=claude
 *   - per-cluster claim:  --cluster=api.treasury --actor=claude
 *     transitions cluster status pending → claimed AND every pending
 *     callsite in the cluster pending → claimed.
 *
 * Hard-gate enforcement happens in the per-cluster claim path. A per-callsite
 * claim of a non-Treasury cluster also enforces the hard gate (otherwise a
 * worker could bypass the gate by claiming callsites individually).
 *
 * Cross-agent enforcement: the cluster's `required_owner` must equal --actor,
 * unless --force --reason="..." --approved-by="..." is supplied.
 */
class SweepInventoryClaimCommandTest extends TestCase
{
    use SweepInventoryTestSeed;

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

    public function test_claim_callsite_transitions_pending_to_claimed_and_appends_history(): void
    {
        $exit = Artisan::call('sweep:inventory:claim', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--actor' => 'claude',
        ]);

        $this->assertSame(0, $exit, 'claim must succeed for a Treasury callsite by required_owner.');

        $callsite = $this->callsiteById('api.treasury.001');
        $this->assertSame('claimed', $callsite['status']);
        $this->assertSame('claude', $callsite['owner']);
        $this->assertNotNull($callsite['claimed_at']);

        /** @var list<array<string, mixed>> $history */
        $history = $callsite['history'];
        $latest = $history[count($history) - 1];
        $this->assertSame('claim', $latest['action']);
        $this->assertSame('claude', $latest['actor']);
        $this->assertSame('sweep:inventory:claim', $latest['command']);
        $this->assertSame('pending', $latest['from_status']);
        $this->assertSame('claimed', $latest['to_status']);
    }

    public function test_claim_cluster_transitions_cluster_and_every_pending_callsite_in_one_mutation(): void
    {
        $exit = Artisan::call('sweep:inventory:claim', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--cluster' => 'api.treasury',
            '--actor' => 'claude',
        ]);

        $this->assertSame(0, $exit, 'cluster claim must succeed for Treasury when claude is required_owner.');

        $cluster = $this->clusterById('api.treasury');
        $this->assertSame('claimed', $cluster['status']);
        $this->assertSame('claude', $cluster['owner']);

        $callsite = $this->callsiteById('api.treasury.001');
        $this->assertSame('claimed', $callsite['status']);
        $this->assertSame('claude', $callsite['owner']);
    }

    public function test_claim_refuses_when_actor_does_not_match_required_owner(): void
    {
        $exit = Artisan::call('sweep:inventory:claim', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--actor' => 'codex',
        ]);

        $this->assertNotSame(0, $exit, 'A codex claim of a claude-required cluster must refuse.');

        // YAML must be unchanged.
        $callsite = $this->callsiteById('api.treasury.001');
        $this->assertSame('pending', $callsite['status']);
        $this->assertNull($callsite['owner']);
    }

    public function test_claim_refuses_invalid_actor(): void
    {
        $exit = Artisan::call('sweep:inventory:claim', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--actor' => 'generator',
        ]);

        $this->assertNotSame(0, $exit, '--actor=generator must be refused (reserved for the generate command).');
    }

    public function test_claim_refuses_unknown_callsite(): void
    {
        $exit = Artisan::call('sweep:inventory:claim', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.999',
            '--actor' => 'claude',
        ]);

        $this->assertNotSame(0, $exit, 'Claiming a non-existent callsite must refuse with a clear error.');
    }

    public function test_claim_refuses_unknown_cluster(): void
    {
        $exit = Artisan::call('sweep:inventory:claim', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--cluster' => 'api.does-not-exist',
            '--actor' => 'claude',
        ]);

        $this->assertNotSame(0, $exit, 'Claiming a non-existent cluster must refuse.');
    }

    public function test_claim_refuses_when_callsite_already_claimed(): void
    {
        // Pre-claim Treasury via direct seed mutation.
        $this->reseedInventory(function (array $doc): array {
            /** @var list<array<string, mixed>> $callsites */
            $callsites = $doc['callsites'];
            $callsites[0]['status'] = 'claimed';
            $callsites[0]['owner'] = 'claude';
            $callsites[0]['claimed_at'] = '2026-05-03T00:00:00Z';
            $doc['callsites'] = $callsites;

            return $doc;
        });

        $exit = Artisan::call('sweep:inventory:claim', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--actor' => 'claude',
        ]);

        $this->assertNotSame(0, $exit, 'Claiming an already-claimed callsite must refuse.');
    }

    public function test_hard_gate_refuses_non_treasury_cluster_claim_when_treasury_not_fixed(): void
    {
        // Treasury status is "pending" by default in the seed. Codex tries to
        // claim api.document. Hard gate must refuse.
        $exit = Artisan::call('sweep:inventory:claim', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--cluster' => 'api.document',
            '--actor' => 'codex',
        ]);

        $this->assertNotSame(0, $exit, 'Hard gate must refuse non-Treasury claim while Treasury is not fixed.');

        // YAML must be unchanged.
        $cluster = $this->clusterById('api.document');
        $this->assertSame('pending', $cluster['status']);
    }

    public function test_hard_gate_refuses_non_treasury_per_callsite_claim_when_treasury_not_fixed(): void
    {
        // Closing the bypass: claiming a single non-Treasury CALLSITE while
        // Treasury is not fixed must also refuse. Otherwise a worker could
        // sneak past the gate by claiming callsites one at a time.
        $exit = Artisan::call('sweep:inventory:claim', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.document.001',
            '--actor' => 'codex',
        ]);

        $this->assertNotSame(0, $exit, 'Hard gate must refuse non-Treasury per-callsite claims too.');

        $callsite = $this->callsiteById('api.document.001');
        $this->assertSame('pending', $callsite['status']);
    }

    public function test_hard_gate_allows_non_treasury_claim_when_treasury_fixed_with_valid_review(): void
    {
        // Pre-seed: Treasury is "fixed" + a review file exists at the path
        // declared in review_gate.review_file with an APPROVE verdict line +
        // the SHA referenced in the review matches the cluster's fix_commit.
        $reviewSha = '1234567abcdef0123456789012345678901234567';
        $reviewPath = $this->tempDir.'/treasury-review.md';
        file_put_contents(
            $reviewPath,
            "# Treasury cluster Codex review\n\n"
                ."Verdict: APPROVE\n\n"
                ."Commit reviewed: {$reviewSha}\n",
        );

        $this->reseedInventory(function (array $doc) use ($reviewPath, $reviewSha): array {
            /** @var list<array<string, mixed>> $clusters */
            $clusters = $doc['clusters'];
            foreach ($clusters as $idx => $cluster) {
                if (($cluster['id'] ?? null) === 'api.treasury') {
                    $cluster['status'] = 'fixed';
                    $cluster['owner'] = 'claude';
                    /** @var array<string, mixed> $reviewGate */
                    $reviewGate = $cluster['review_gate'];
                    $reviewGate['review_file'] = $reviewPath;
                    // Disable commit linkage for this happy-path test —
                    // dedicated tests below cover the linkage check.
                    $reviewGate['verify_review_commit_linkage'] = false;
                    $cluster['review_gate'] = $reviewGate;
                    $clusters[$idx] = $cluster;
                }
            }
            $doc['clusters'] = $clusters;

            /** @var list<array<string, mixed>> $callsites */
            $callsites = $doc['callsites'];
            foreach ($callsites as $idx => $cs) {
                if (($cs['cluster_id'] ?? null) === 'api.treasury') {
                    $cs['status'] = 'fixed';
                    $cs['owner'] = 'claude';
                    $cs['fix_commit'] = $reviewSha;
                    $cs['regression_test'] = 'tests/Feature/Treasury/TreasuryTenantIsolationTest.php::test_baseline';
                    $callsites[$idx] = $cs;
                }
            }
            $doc['callsites'] = $callsites;

            return $doc;
        });

        $exit = Artisan::call('sweep:inventory:claim', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--cluster' => 'api.document',
            '--actor' => 'codex',
        ]);

        $this->assertSame(
            0,
            $exit,
            'Hard gate must let codex claim api.document once Treasury is fixed and review APPROVE.',
        );

        $cluster = $this->clusterById('api.document');
        $this->assertSame('claimed', $cluster['status']);
    }

    public function test_hard_gate_refuses_when_review_file_missing(): void
    {
        $this->reseedInventory(function (array $doc): array {
            /** @var list<array<string, mixed>> $clusters */
            $clusters = $doc['clusters'];
            foreach ($clusters as $idx => $cluster) {
                if (($cluster['id'] ?? null) === 'api.treasury') {
                    $cluster['status'] = 'fixed';
                    $cluster['owner'] = 'claude';
                    /** @var array<string, mixed> $rg */
                    $rg = $cluster['review_gate'];
                    $rg['review_file'] = $this->tempDir.'/this-file-does-not-exist.md';
                    $rg['verify_review_commit_linkage'] = false;
                    $cluster['review_gate'] = $rg;
                    $clusters[$idx] = $cluster;
                }
            }
            $doc['clusters'] = $clusters;

            return $doc;
        });

        $exit = Artisan::call('sweep:inventory:claim', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--cluster' => 'api.document',
            '--actor' => 'codex',
        ]);

        $this->assertNotSame(0, $exit, 'Hard gate must refuse when the review file does not exist on disk.');
    }

    public function test_hard_gate_refuses_when_verdict_not_in_accepted_verdicts(): void
    {
        $reviewPath = $this->tempDir.'/treasury-review.md';
        file_put_contents(
            $reviewPath,
            "# Treasury cluster Codex review\n\nVerdict: REQUEST-CHANGES\n",
        );

        $this->reseedInventory(function (array $doc) use ($reviewPath): array {
            /** @var list<array<string, mixed>> $clusters */
            $clusters = $doc['clusters'];
            foreach ($clusters as $idx => $cluster) {
                if (($cluster['id'] ?? null) === 'api.treasury') {
                    $cluster['status'] = 'fixed';
                    $cluster['owner'] = 'claude';
                    /** @var array<string, mixed> $rg */
                    $rg = $cluster['review_gate'];
                    $rg['review_file'] = $reviewPath;
                    $rg['verify_review_commit_linkage'] = false;
                    $cluster['review_gate'] = $rg;
                    $clusters[$idx] = $cluster;
                }
            }
            $doc['clusters'] = $clusters;

            return $doc;
        });

        $exit = Artisan::call('sweep:inventory:claim', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--cluster' => 'api.document',
            '--actor' => 'codex',
        ]);

        $this->assertNotSame(
            0,
            $exit,
            'Hard gate must refuse when the verdict is not in accepted_verdicts.',
        );
    }

    public function test_force_flag_bypasses_required_owner_check_with_reason_and_approved_by(): void
    {
        $exit = Artisan::call('sweep:inventory:claim', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--actor' => 'codex',
            '--force' => true,
            '--reason' => 'Treasury owner unavailable; codex picking up under emergency authorization.',
            '--approved-by' => 'human:houssamr@2026-05-03',
        ]);

        $this->assertSame(0, $exit, '--force --reason --approved-by must bypass required_owner.');

        $callsite = $this->callsiteById('api.treasury.001');
        $this->assertSame('claimed', $callsite['status']);
        $this->assertSame('codex', $callsite['owner']);
    }

    public function test_force_without_reason_is_refused(): void
    {
        $exit = Artisan::call('sweep:inventory:claim', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--actor' => 'codex',
            '--force' => true,
            '--approved-by' => 'human:houssamr@2026-05-03',
        ]);

        $this->assertNotSame(0, $exit, '--force must require both --reason and --approved-by.');
    }

    public function test_must_supply_either_callsite_id_or_cluster_not_both_not_neither(): void
    {
        $exitNeither = Artisan::call('sweep:inventory:claim', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--actor' => 'claude',
        ]);
        $this->assertNotSame(0, $exitNeither, 'Must supply --callsite-id or --cluster.');

        $exitBoth = Artisan::call('sweep:inventory:claim', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--cluster' => 'api.treasury',
            '--actor' => 'claude',
        ]);
        $this->assertNotSame(0, $exitBoth, 'Must NOT supply both --callsite-id and --cluster.');
    }
}
