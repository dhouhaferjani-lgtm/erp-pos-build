<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Sweep;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Feature test for `php artisan sweep:inventory:unblock`.
 *
 * Master plan reference:
 *   - Section 4 — workflow state machine (unblock: blocked → previous state).
 *
 * Only callsite-mode is supported (--callsite-id).
 * Cluster mode is OUT OF SCOPE per the master plan — unblock is per-callsite.
 *
 * Behaviour:
 *   - Callsite must be blocked. Refuse otherwise.
 *   - Walk history backwards to find the first block event; restore from_status.
 *   - Clear blocked_reason.
 *   - Append ONE history event: action=unblock, from_status=blocked, to_status=<prev>.
 *
 * Optional: --reason (captured in history note; not required).
 */
class SweepInventoryUnblockCommandTest extends TestCase
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

    /**
     * Seed a callsite to `blocked` status with a `block` history event.
     * The block event records from_status so unblock can recover it.
     */
    private function blockCallsite(string $callsiteId, string $previousStatus): void
    {
        $this->reseedInventory(function (array $doc) use ($callsiteId, $previousStatus): array {
            /** @var list<array<string, mixed>> $callsites */
            $callsites = $doc['callsites'];
            foreach ($callsites as $idx => $cs) {
                if (($cs['id'] ?? null) === $callsiteId) {
                    $cs['status'] = 'blocked';
                    $cs['blocked_reason'] = 'Seeded block reason';
                    /** @var list<array<string, mixed>> $history */
                    $history = $cs['history'];
                    $history[] = [
                        'at' => '2026-05-03T01:00:00Z',
                        'actor' => 'claude',
                        'action' => 'block',
                        'command' => 'sweep:inventory:block',
                        'previous_yaml_sha256' => null,
                        'new_yaml_sha256' => null,
                        'target_ids' => [$callsiteId],
                        'from_status' => $previousStatus,
                        'to_status' => 'blocked',
                        'commit' => null,
                        'test' => null,
                        'review_file' => null,
                        'review_commit' => null,
                        'note' => 'Seeded block for unblock test',
                    ];
                    $cs['history'] = $history;
                    $callsites[$idx] = $cs;
                }
            }
            $doc['callsites'] = $callsites;

            return $doc;
        });
    }

    public function test_unblock_callsite_restores_previous_state_from_latest_block_event(): void
    {
        $this->blockCallsite('api.treasury.001', 'pending');

        $exit = Artisan::call('sweep:inventory:unblock', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--actor' => 'claude',
        ]);

        $this->assertSame(0, $exit, 'unblock must succeed for a blocked callsite with a block event in history.');

        $callsite = $this->callsiteById('api.treasury.001');
        $this->assertSame('pending', $callsite['status'], 'status must be restored to the pre-block state.');

        /** @var list<array<string, mixed>> $history */
        $history = $callsite['history'];
        $latest = $history[count($history) - 1];
        $this->assertSame('unblock', $latest['action']);
        $this->assertSame('claude', $latest['actor']);
        $this->assertSame('sweep:inventory:unblock', $latest['command']);
        $this->assertSame('blocked', $latest['from_status']);
        $this->assertSame('pending', $latest['to_status']);
        $this->assertSame(['api.treasury.001'], $latest['target_ids']);
        $this->assertNotEmpty($latest['note']);
    }

    public function test_unblock_callsite_restores_to_in_progress_when_blocked_from_in_progress(): void
    {
        $this->blockCallsite('api.treasury.001', 'in_progress');

        $exit = Artisan::call('sweep:inventory:unblock', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--actor' => 'human',
        ]);

        $this->assertSame(0, $exit, 'unblock must restore in_progress from the block event.');

        $callsite = $this->callsiteById('api.treasury.001');
        $this->assertSame('in_progress', $callsite['status']);

        /** @var list<array<string, mixed>> $history */
        $history = $callsite['history'];
        $latest = $history[count($history) - 1];
        $this->assertSame('unblock', $latest['action']);
        $this->assertSame('blocked', $latest['from_status']);
        $this->assertSame('in_progress', $latest['to_status']);
    }

    public function test_unblock_callsite_clears_blocked_reason(): void
    {
        $this->blockCallsite('api.treasury.001', 'claimed');

        $exit = Artisan::call('sweep:inventory:unblock', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--actor' => 'claude',
        ]);

        $this->assertSame(0, $exit);

        $callsite = $this->callsiteById('api.treasury.001');
        $this->assertNull($callsite['blocked_reason'], 'blocked_reason must be cleared after unblock.');
        $this->assertSame('claimed', $callsite['status']);
    }

    public function test_unblock_refuses_callsite_not_blocked(): void
    {
        // Default seed: callsite is pending — not blocked.
        $exit = Artisan::call('sweep:inventory:unblock', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--actor' => 'claude',
        ]);

        $this->assertNotSame(0, $exit, 'unblock must refuse when callsite is not blocked.');

        $callsite = $this->callsiteById('api.treasury.001');
        $this->assertSame('pending', $callsite['status'], 'callsite must remain pending after refusal.');
    }

    public function test_unblock_refuses_when_history_has_no_block_event(): void
    {
        // Seed a callsite with status=blocked but NO block action in history.
        $this->reseedInventory(function (array $doc): array {
            /** @var list<array<string, mixed>> $callsites */
            $callsites = $doc['callsites'];
            foreach ($callsites as $idx => $cs) {
                if (($cs['id'] ?? null) === 'api.treasury.001') {
                    $cs['status'] = 'blocked';
                    $cs['blocked_reason'] = 'Manually set without history';
                    // history stays as-is: only the initial generate event, no block event
                    $callsites[$idx] = $cs;
                }
            }
            $doc['callsites'] = $callsites;

            return $doc;
        });

        $exit = Artisan::call('sweep:inventory:unblock', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--actor' => 'claude',
        ]);

        $this->assertNotSame(
            0,
            $exit,
            'unblock must refuse when history contains no block event (YAML is malformed — cannot recover previous state).',
        );

        // Status must stay blocked (no change).
        $callsite = $this->callsiteById('api.treasury.001');
        $this->assertSame('blocked', $callsite['status']);
    }

    public function test_unblock_refuses_unknown_callsite(): void
    {
        $exit = Artisan::call('sweep:inventory:unblock', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.does-not-exist.999',
            '--actor' => 'claude',
        ]);

        $this->assertNotSame(0, $exit, 'unblock must refuse for an unknown callsite.');
    }

    public function test_unblock_refuses_cluster_mode(): void
    {
        $exit = Artisan::call('sweep:inventory:unblock', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--cluster' => 'api.treasury',
            '--actor' => 'claude',
        ]);

        $this->assertNotSame(0, $exit, 'unblock must refuse --cluster; it is a per-callsite operation only.');
    }

    public function test_unblock_refuses_invalid_actor(): void
    {
        $this->blockCallsite('api.treasury.001', 'pending');

        $exit = Artisan::call('sweep:inventory:unblock', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--actor' => 'generator',
        ]);

        $this->assertNotSame(0, $exit, '--actor=generator is reserved and must be refused.');

        $callsite = $this->callsiteById('api.treasury.001');
        $this->assertSame('blocked', $callsite['status'], 'callsite must remain blocked after actor refusal.');
    }

    /**
     * Closes the recovery gap surfaced by the 2A.3 audit: review --verdict=BLOCK
     * appends action='review' (not 'block'). Walking only for action='block'
     * would leave such callsites unrecoverable. recoverPreviousStatus walks for
     * to_status='blocked' instead, which catches BOTH paths.
     */
    public function test_unblock_recovers_callsite_blocked_via_review_verdict_block(): void
    {
        // Seed: callsite under_review, then review writes a `review` event
        // with from_status=under_review, to_status=blocked. No `block` action.
        $this->reseedInventory(function (array $doc): array {
            /** @var list<array<string, mixed>> $callsites */
            $callsites = $doc['callsites'];
            foreach ($callsites as $idx => $cs) {
                if (($cs['id'] ?? null) === 'api.treasury.001') {
                    $cs['status'] = 'blocked';
                    $cs['blocked_reason'] = 'Reviewer rejected with BLOCK verdict';
                    $cs['owner'] = 'claude';
                    /** @var list<array<string, mixed>> $history */
                    $history = $cs['history'];
                    $history[] = [
                        'at' => '2026-05-03T01:30:00Z',
                        'actor' => 'codex',
                        'action' => 'review',
                        'command' => 'sweep:inventory:review',
                        'previous_yaml_sha256' => null,
                        'new_yaml_sha256' => null,
                        'target_ids' => ['api.treasury.001'],
                        'from_status' => 'under_review',
                        'to_status' => 'blocked',
                        'commit' => null,
                        'test' => null,
                        'review_file' => 'docs/superpowers/reviews/treasury.md',
                        'review_commit' => null,
                        'note' => 'BLOCK verdict from cluster review',
                    ];
                    $cs['history'] = $history;
                    $callsites[$idx] = $cs;
                }
            }
            $doc['callsites'] = $callsites;

            return $doc;
        });

        $exit = Artisan::call('sweep:inventory:unblock', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--actor' => 'claude',
        ]);

        $this->assertSame(
            0,
            $exit,
            'unblock must recover callsites blocked via review BLOCK by walking to_status=blocked, not action=block.',
        );

        $callsite = $this->callsiteById('api.treasury.001');
        $this->assertSame('under_review', $callsite['status'], 'must restore the pre-review state recorded in the review event.');
        $this->assertNull($callsite['blocked_reason']);
    }
}
