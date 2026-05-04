<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Sweep;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Feature test for `php artisan sweep:inventory:block`.
 *
 * Master plan reference:
 *   - Section 4 — workflow state machine (block: any pre-fixed → blocked).
 *
 * Pre-fixed statuses: pending, claimed, in_progress, under_review
 * (and technically deferred/needs_recheck/stale_orphan, but blocked from those
 * is refused for safety unless the spec explicitly allows).
 *
 * Modes:
 *   --callsite-id  Block a single callsite.
 *   --cluster      Block every eligible callsite in the cluster + the cluster.
 *
 * Required: --reason (non-empty).
 *
 * Audit-trail invariant: cluster mode with zero eligible callsites refuses.
 */
class SweepInventoryBlockCommandTest extends TestCase
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

    public function test_block_callsite_transitions_pending_to_blocked_with_reason(): void
    {
        $exit = Artisan::call('sweep:inventory:block', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--reason' => 'Blocked pending upstream fix',
            '--actor' => 'claude',
        ]);

        $this->assertSame(0, $exit, 'block must succeed for a pending callsite.');

        $callsite = $this->callsiteById('api.treasury.001');
        $this->assertSame('blocked', $callsite['status']);
        $this->assertSame('Blocked pending upstream fix', $callsite['blocked_reason']);

        /** @var list<array<string, mixed>> $history */
        $history = $callsite['history'];
        $latest = $history[count($history) - 1];
        $this->assertSame('block', $latest['action']);
        $this->assertSame('claude', $latest['actor']);
        $this->assertSame('sweep:inventory:block', $latest['command']);
        $this->assertSame('pending', $latest['from_status']);
        $this->assertSame('blocked', $latest['to_status']);
        $this->assertSame(['api.treasury.001'], $latest['target_ids']);
        $this->assertNotEmpty($latest['note']);

        // Single-callsite mode must NOT touch the cluster status.
        $cluster = $this->clusterById('api.treasury');
        $this->assertSame('pending', $cluster['status']);
    }

    public function test_block_callsite_transitions_in_progress_to_blocked_with_reason(): void
    {
        // Advance callsite to in_progress.
        $this->reseedInventory(function (array $doc): array {
            /** @var list<array<string, mixed>> $callsites */
            $callsites = $doc['callsites'];
            foreach ($callsites as $idx => $cs) {
                if (($cs['id'] ?? null) === 'api.treasury.001') {
                    $cs['status'] = 'in_progress';
                    $cs['owner'] = 'claude';
                    $callsites[$idx] = $cs;
                }
            }
            $doc['callsites'] = $callsites;

            return $doc;
        });

        $exit = Artisan::call('sweep:inventory:block', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--reason' => 'Waiting on dependency resolution',
            '--actor' => 'claude',
        ]);

        $this->assertSame(0, $exit, 'block must succeed for an in_progress callsite.');

        $callsite = $this->callsiteById('api.treasury.001');
        $this->assertSame('blocked', $callsite['status']);
        $this->assertSame('Waiting on dependency resolution', $callsite['blocked_reason']);

        /** @var list<array<string, mixed>> $history */
        $history = $callsite['history'];
        $latest = $history[count($history) - 1];
        $this->assertSame('block', $latest['action']);
        $this->assertSame('in_progress', $latest['from_status']);
        $this->assertSame('blocked', $latest['to_status']);
    }

    public function test_block_cluster_transitions_cluster_and_every_eligible_callsite_in_one_mutation(): void
    {
        // Add a second callsite in in_progress state in the treasury cluster.
        $this->reseedInventory(function (array $doc): array {
            /** @var list<array<string, mixed>> $callsites */
            $callsites = $doc['callsites'];
            $callsites[] = $this->makeCallsite('api.treasury.002', 'api.treasury', 'c');
            // Advance treasury.001 to claimed.
            foreach ($callsites as $idx => $cs) {
                if (($cs['id'] ?? null) === 'api.treasury.001') {
                    $cs['status'] = 'claimed';
                    $callsites[$idx] = $cs;
                }
            }
            $doc['callsites'] = $callsites;

            return $doc;
        });

        $exit = Artisan::call('sweep:inventory:block', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--cluster' => 'api.treasury',
            '--reason' => 'External blocker from infrastructure team',
            '--actor' => 'claude',
        ]);

        $this->assertSame(0, $exit, 'cluster block must succeed when there are eligible callsites.');

        $cluster = $this->clusterById('api.treasury');
        $this->assertSame('blocked', $cluster['status']);
        $this->assertSame('External blocker from infrastructure team', $cluster['blocked_reason']);

        $callsite1 = $this->callsiteById('api.treasury.001');
        $this->assertSame('blocked', $callsite1['status']);
        $this->assertSame('External blocker from infrastructure team', $callsite1['blocked_reason']);

        /** @var list<array<string, mixed>> $history1 */
        $history1 = $callsite1['history'];
        $latest1 = $history1[count($history1) - 1];
        $this->assertSame('block', $latest1['action']);
        $this->assertSame('blocked', $latest1['to_status']);

        $callsite2 = $this->callsiteById('api.treasury.002');
        $this->assertSame('blocked', $callsite2['status']);

        /** @var list<array<string, mixed>> $history2 */
        $history2 = $callsite2['history'];
        $latest2 = $history2[count($history2) - 1];
        $this->assertSame('block', $latest2['action']);
        $this->assertSame('blocked', $latest2['to_status']);
    }

    public function test_block_refuses_already_fixed_callsite(): void
    {
        $this->reseedInventory(function (array $doc): array {
            /** @var list<array<string, mixed>> $callsites */
            $callsites = $doc['callsites'];
            foreach ($callsites as $idx => $cs) {
                if (($cs['id'] ?? null) === 'api.treasury.001') {
                    $cs['status'] = 'fixed';
                    $callsites[$idx] = $cs;
                }
            }
            $doc['callsites'] = $callsites;

            return $doc;
        });

        $exit = Artisan::call('sweep:inventory:block', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--reason' => 'Should not be allowed',
            '--actor' => 'claude',
        ]);

        $this->assertNotSame(0, $exit, 'block must refuse for a fixed callsite (would be a regression).');

        $callsite = $this->callsiteById('api.treasury.001');
        $this->assertSame('fixed', $callsite['status'], 'callsite must remain fixed after refusal.');
    }

    public function test_block_refuses_already_blocked_callsite(): void
    {
        $this->reseedInventory(function (array $doc): array {
            /** @var list<array<string, mixed>> $callsites */
            $callsites = $doc['callsites'];
            foreach ($callsites as $idx => $cs) {
                if (($cs['id'] ?? null) === 'api.treasury.001') {
                    $cs['status'] = 'blocked';
                    $cs['blocked_reason'] = 'Already blocked';
                    $callsites[$idx] = $cs;
                }
            }
            $doc['callsites'] = $callsites;

            return $doc;
        });

        $exit = Artisan::call('sweep:inventory:block', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--reason' => 'Trying to double-block',
            '--actor' => 'claude',
        ]);

        $this->assertNotSame(0, $exit, 'block must refuse for an already-blocked callsite.');

        $callsite = $this->callsiteById('api.treasury.001');
        $this->assertSame('blocked', $callsite['status']);
        $this->assertSame('Already blocked', $callsite['blocked_reason'], 'blocked_reason must not change.');
    }

    public function test_block_refuses_when_reason_missing_or_empty(): void
    {
        $exitMissing = Artisan::call('sweep:inventory:block', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--actor' => 'claude',
        ]);

        $this->assertNotSame(0, $exitMissing, '--reason is required; must refuse when absent.');

        $exitEmpty = Artisan::call('sweep:inventory:block', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--reason' => '',
            '--actor' => 'claude',
        ]);

        $this->assertNotSame(0, $exitEmpty, '--reason is required; must refuse when empty.');

        // Callsite must be unchanged after both failures.
        $callsite = $this->callsiteById('api.treasury.001');
        $this->assertSame('pending', $callsite['status']);
    }

    public function test_block_refuses_unknown_callsite(): void
    {
        $exit = Artisan::call('sweep:inventory:block', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.does-not-exist.999',
            '--reason' => 'Blocked',
            '--actor' => 'claude',
        ]);

        $this->assertNotSame(0, $exit, 'block must refuse for an unknown callsite.');
    }

    public function test_block_refuses_unknown_cluster(): void
    {
        $exit = Artisan::call('sweep:inventory:block', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--cluster' => 'api.does-not-exist',
            '--reason' => 'Blocked',
            '--actor' => 'claude',
        ]);

        $this->assertNotSame(0, $exit, 'block must refuse for an unknown cluster.');
    }

    public function test_block_refuses_invalid_actor(): void
    {
        $exit = Artisan::call('sweep:inventory:block', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--reason' => 'Blocked',
            '--actor' => 'generator',
        ]);

        $this->assertNotSame(0, $exit, '--actor=generator is reserved and must be refused.');

        $callsite = $this->callsiteById('api.treasury.001');
        $this->assertSame('pending', $callsite['status']);
    }

    public function test_block_refuses_when_cluster_has_no_eligible_callsites(): void
    {
        // Seed: both treasury callsites are fixed — no eligible targets.
        $this->reseedInventory(function (array $doc): array {
            /** @var list<array<string, mixed>> $callsites */
            $callsites = $doc['callsites'];
            foreach ($callsites as $idx => $cs) {
                if (($cs['cluster_id'] ?? null) === 'api.treasury') {
                    $cs['status'] = 'fixed';
                    $callsites[$idx] = $cs;
                }
            }
            $doc['callsites'] = $callsites;

            return $doc;
        });

        $exit = Artisan::call('sweep:inventory:block', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--cluster' => 'api.treasury',
            '--reason' => 'All fixed but trying to block',
            '--actor' => 'claude',
        ]);

        $this->assertNotSame(
            0,
            $exit,
            'cluster block must refuse when there are no eligible callsites (audit-trail invariant).',
        );
    }

    public function test_must_supply_either_callsite_id_or_cluster_not_both_not_neither(): void
    {
        $exitNeither = Artisan::call('sweep:inventory:block', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--reason' => 'Blocked',
            '--actor' => 'claude',
        ]);
        $this->assertNotSame(0, $exitNeither, 'Must supply --callsite-id or --cluster.');

        $exitBoth = Artisan::call('sweep:inventory:block', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--cluster' => 'api.treasury',
            '--reason' => 'Blocked',
            '--actor' => 'claude',
        ]);
        $this->assertNotSame(0, $exitBoth, 'Must NOT supply both --callsite-id and --cluster.');
    }

    /**
     * Master plan Section 4: blocked branches from "any pre-fixed state" =
     * pending|claimed|in_progress|under_review only. The audit-only states
     * (deferred, needs_recheck, stale_orphan) require explicit reactivation
     * before they can be blocked. Refuse here with a clear pre-fixed-only error.
     */
    public function test_block_refuses_callsite_in_audit_only_state(): void
    {
        foreach (['deferred', 'needs_recheck', 'stale_orphan'] as $auditOnlyStatus) {
            // Re-seed each iteration so we start from a clean known state.
            $this->reseedInventory(function (array $doc) use ($auditOnlyStatus): array {
                /** @var list<array<string, mixed>> $callsites */
                $callsites = $doc['callsites'];
                foreach ($callsites as $idx => $cs) {
                    if (($cs['id'] ?? null) === 'api.treasury.001') {
                        $cs['status'] = $auditOnlyStatus;
                        $callsites[$idx] = $cs;
                    }
                }
                $doc['callsites'] = $callsites;

                return $doc;
            });

            $exit = Artisan::call('sweep:inventory:block', [
                '--inventory-path' => $this->inventoryPath,
                '--schema-path' => $this->schemaPath,
                '--callsite-id' => 'api.treasury.001',
                '--reason' => "Cannot block from {$auditOnlyStatus}",
                '--actor' => 'claude',
            ]);

            $this->assertNotSame(
                0,
                $exit,
                "block must refuse callsite in '{$auditOnlyStatus}' (only pre-fixed states are blockable per master plan Section 4).",
            );

            $callsite = $this->callsiteById('api.treasury.001');
            $this->assertSame(
                $auditOnlyStatus,
                $callsite['status'],
                "callsite must remain in '{$auditOnlyStatus}' after refusal.",
            );
        }
    }

    /**
     * Codex BLOCK finding #5: cluster mode must check the CLUSTER's own
     * status, not just the callsites'. An inconsistent YAML where the cluster
     * is e.g. `fixed` but a child callsite is still `pending` must not be
     * coerced into `blocked` by virtue of the eligible-callsite filter.
     */
    public function test_block_cluster_refuses_when_cluster_status_is_outside_pre_fixed(): void
    {
        foreach (['fixed', 'blocked', 'deferred', 'needs_recheck', 'stale_orphan'] as $disallowedStatus) {
            // Re-seed: cluster.status flipped to disallowed; one callsite stays
            // pending so the eligible-callsite filter alone wouldn't catch it.
            $this->reseedInventory(function (array $doc) use ($disallowedStatus): array {
                /** @var list<array<string, mixed>> $clusters */
                $clusters = $doc['clusters'];
                foreach ($clusters as $idx => $c) {
                    if (($c['id'] ?? null) === 'api.treasury') {
                        $c['status'] = $disallowedStatus;
                        $clusters[$idx] = $c;
                    }
                }
                $doc['clusters'] = $clusters;

                return $doc;
            });

            $exit = Artisan::call('sweep:inventory:block', [
                '--inventory-path' => $this->inventoryPath,
                '--schema-path' => $this->schemaPath,
                '--cluster' => 'api.treasury',
                '--reason' => "block while cluster is {$disallowedStatus}",
                '--actor' => 'claude',
            ]);

            $this->assertNotSame(
                0,
                $exit,
                "block --cluster must refuse when cluster status is '{$disallowedStatus}'.",
            );

            $cluster = $this->clusterById('api.treasury');
            $this->assertSame(
                $disallowedStatus,
                $cluster['status'],
                "cluster must remain '{$disallowedStatus}' after refusal.",
            );
        }
    }
}
