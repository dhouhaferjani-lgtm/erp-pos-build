<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Sweep;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Feature test for `php artisan sweep:inventory:start`.
 *
 * Master plan reference:
 *   - Section 4 — workflow state machine (start: claimed → in_progress).
 *
 * Surfaces:
 *   - per-callsite start: --callsite-id=api.treasury.001 --actor=claude
 *   - per-cluster start:  --cluster=api.treasury --actor=claude
 *     transitions cluster status claimed → in_progress AND every CLAIMED
 *     callsite in the cluster claimed → in_progress.
 *
 * Cross-agent enforcement: --actor MUST equal the cluster's current `owner`.
 * NO --force override exists for start — wrong actor on a claimed cluster is
 * a worker bug, not a hand-off case.
 *
 * Audit-trail invariant: cluster mode with zero claimed callsites must refuse,
 * because the cluster mutation would otherwise leave no callsite history event.
 */
class SweepInventoryStartCommandTest extends TestCase
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

    public function test_start_callsite_transitions_claimed_to_in_progress_and_appends_history(): void
    {
        $this->claimClusterAndCallsites('api.treasury', 'claude');

        $exit = Artisan::call('sweep:inventory:start', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--actor' => 'claude',
        ]);

        $this->assertSame(0, $exit, 'start must succeed for a claimed callsite owned by --actor.');

        $callsite = $this->callsiteById('api.treasury.001');
        $this->assertSame('in_progress', $callsite['status']);
        $this->assertSame('claude', $callsite['owner']);

        /** @var list<array<string, mixed>> $history */
        $history = $callsite['history'];
        $latest = $history[count($history) - 1];
        $this->assertSame('start', $latest['action']);
        $this->assertSame('claude', $latest['actor']);
        $this->assertSame('sweep:inventory:start', $latest['command']);
        $this->assertSame('claimed', $latest['from_status']);
        $this->assertSame('in_progress', $latest['to_status']);
        $this->assertSame(['api.treasury.001'], $latest['target_ids']);

        // Single-callsite mode must NOT touch cluster status.
        $cluster = $this->clusterById('api.treasury');
        $this->assertSame('claimed', $cluster['status']);
    }

    public function test_start_cluster_transitions_cluster_and_every_claimed_callsite_in_one_mutation(): void
    {
        $this->claimClusterAndCallsites('api.treasury', 'claude');

        $exit = Artisan::call('sweep:inventory:start', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--cluster' => 'api.treasury',
            '--actor' => 'claude',
        ]);

        $this->assertSame(0, $exit, 'cluster start must succeed when actor matches the cluster owner.');

        $cluster = $this->clusterById('api.treasury');
        $this->assertSame('in_progress', $cluster['status']);
        $this->assertSame('claude', $cluster['owner']);

        $callsite = $this->callsiteById('api.treasury.001');
        $this->assertSame('in_progress', $callsite['status']);
        $this->assertSame('claude', $callsite['owner']);

        // History must record the start event on the callsite.
        /** @var list<array<string, mixed>> $history */
        $history = $callsite['history'];
        $latest = $history[count($history) - 1];
        $this->assertSame('start', $latest['action']);
        $this->assertSame('claimed', $latest['from_status']);
        $this->assertSame('in_progress', $latest['to_status']);
    }

    public function test_start_refuses_when_actor_does_not_match_cluster_owner(): void
    {
        $this->claimClusterAndCallsites('api.treasury', 'claude');

        $exit = Artisan::call('sweep:inventory:start', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--cluster' => 'api.treasury',
            '--actor' => 'codex',
        ]);

        $this->assertNotSame(0, $exit, 'start must refuse when --actor does not match cluster owner.');

        // YAML must be unchanged.
        $cluster = $this->clusterById('api.treasury');
        $this->assertSame('claimed', $cluster['status']);
        $this->assertSame('claude', $cluster['owner']);

        $callsite = $this->callsiteById('api.treasury.001');
        $this->assertSame('claimed', $callsite['status']);
    }

    public function test_start_refuses_when_callsite_not_claimed(): void
    {
        // Default seed: callsite is pending. Try to start it.
        $exit = Artisan::call('sweep:inventory:start', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--actor' => 'claude',
        ]);

        $this->assertNotSame(0, $exit, 'start must refuse when callsite status is not claimed.');

        $callsite = $this->callsiteById('api.treasury.001');
        $this->assertSame('pending', $callsite['status']);
    }

    public function test_start_refuses_when_cluster_not_claimed(): void
    {
        // Default seed: cluster is pending.
        $exit = Artisan::call('sweep:inventory:start', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--cluster' => 'api.treasury',
            '--actor' => 'claude',
        ]);

        $this->assertNotSame(0, $exit, 'start must refuse when cluster status is not claimed.');

        $cluster = $this->clusterById('api.treasury');
        $this->assertSame('pending', $cluster['status']);
    }

    public function test_start_refuses_unknown_callsite(): void
    {
        $exit = Artisan::call('sweep:inventory:start', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.does-not-exist.999',
            '--actor' => 'claude',
        ]);

        $this->assertNotSame(0, $exit, 'start must refuse for an unknown callsite.');
    }

    public function test_start_refuses_unknown_cluster(): void
    {
        $exit = Artisan::call('sweep:inventory:start', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--cluster' => 'api.does-not-exist',
            '--actor' => 'claude',
        ]);

        $this->assertNotSame(0, $exit, 'start must refuse for an unknown cluster.');
    }

    public function test_start_refuses_invalid_actor(): void
    {
        $this->claimClusterAndCallsites('api.treasury', 'claude');

        $exit = Artisan::call('sweep:inventory:start', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--actor' => 'generator',
        ]);

        $this->assertNotSame(0, $exit, '--actor=generator is reserved for the generate command and must be refused.');
    }

    public function test_start_refuses_when_cluster_has_no_claimed_callsites(): void
    {
        // Pre-seed: cluster status flipped to claimed but every callsite remains pending.
        $this->reseedInventory(function (array $doc): array {
            /** @var list<array<string, mixed>> $clusters */
            $clusters = $doc['clusters'];
            foreach ($clusters as $idx => $cluster) {
                if (($cluster['id'] ?? null) === 'api.treasury') {
                    $cluster['status'] = 'claimed';
                    $cluster['owner'] = 'claude';
                    $clusters[$idx] = $cluster;
                }
            }
            $doc['clusters'] = $clusters;

            // Callsites stay pending — simulating a buggy upstream mutation.

            return $doc;
        });

        $exit = Artisan::call('sweep:inventory:start', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--cluster' => 'api.treasury',
            '--actor' => 'claude',
        ]);

        $this->assertNotSame(
            0,
            $exit,
            'cluster start must refuse when no callsites are claimed — otherwise the cluster status flips with no callsite history event.',
        );

        $cluster = $this->clusterById('api.treasury');
        $this->assertSame('claimed', $cluster['status']);
    }

    public function test_must_supply_either_callsite_id_or_cluster_not_both_not_neither(): void
    {
        $exitNeither = Artisan::call('sweep:inventory:start', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--actor' => 'claude',
        ]);
        $this->assertNotSame(0, $exitNeither, 'Must supply --callsite-id or --cluster.');

        $exitBoth = Artisan::call('sweep:inventory:start', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--cluster' => 'api.treasury',
            '--actor' => 'claude',
        ]);
        $this->assertNotSame(0, $exitBoth, 'Must NOT supply both --callsite-id and --cluster.');
    }
}
