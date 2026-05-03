<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Sweep;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Feature test for `php artisan sweep:inventory:verify-history` (read-only).
 *
 * Master plan reference: 2026-05-02-tenant-isolation-master-plan.md
 *   Section 4 lines 362-366 — CI hand-edit detection algorithm.
 *
 * Algorithm:
 *   1. Walk every callsite's history array.
 *   2. For each event:
 *      - command MUST be non-null (rejects hand-edits that didn't go through
 *        artisan CLI; the schema declares command as string|null so PHPStan
 *        won't catch this — verify-history is the gate).
 *      - actor MUST be non-null.
 *      - target_ids MUST be non-empty AND every id must exist in the
 *        inventory's callsites or clusters list.
 *      - For events with index > 0: previous_yaml_sha256 MUST be non-null
 *        AND MUST equal the previous event's new_yaml_sha256.
 *   3. Print a per-failure error citing callsite + history index + check.
 *   4. Exit 0 if every event passes; non-zero otherwise. Print a summary at
 *      the end ("verified N events across M callsites; K problems").
 *
 * Cross-callsite chain ordering is NOT enforced — that invariant is
 * handled implicitly by the YAML's metadata.yaml_sha256.
 */
class SweepInventoryVerifyHistoryCommandTest extends TestCase
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

    public function test_verify_history_passes_on_clean_seed(): void
    {
        $exit = Artisan::call('sweep:inventory:verify-history', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
        ]);

        $output = Artisan::output();
        $this->assertSame(0, $exit, 'Clean seed must verify. Output was: '.$output);
    }

    public function test_verify_history_passes_after_real_workflow_commands(): void
    {
        // claim → start over Treasury so multiple history events exist with
        // a real chain. Both events must come through the artisan CLI so the
        // chain hashes are computed by InventoryService.
        $claimExit = Artisan::call('sweep:inventory:claim', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--actor' => 'claude',
        ]);
        $this->assertSame(0, $claimExit);

        $startExit = Artisan::call('sweep:inventory:start', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--actor' => 'claude',
        ]);
        $this->assertSame(0, $startExit);

        $exit = Artisan::call('sweep:inventory:verify-history', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
        ]);

        $output = Artisan::output();
        $this->assertSame(0, $exit, 'Real workflow runs must verify. Output was: '.$output);
        $this->assertStringContainsString('verified', $output);
    }

    public function test_verify_history_refuses_when_command_is_null(): void
    {
        $this->reseedInventory(function (array $doc): array {
            /** @var list<array<string, mixed>> $callsites */
            $callsites = $doc['callsites'];
            foreach ($callsites as $idx => $cs) {
                if (($cs['id'] ?? null) === 'api.treasury.001') {
                    /** @var list<array<string, mixed>> $history */
                    $history = $cs['history'];
                    $history[0]['command'] = null;
                    $cs['history'] = $history;
                    $callsites[$idx] = $cs;
                }
            }
            $doc['callsites'] = $callsites;

            return $doc;
        });

        $exit = Artisan::call('sweep:inventory:verify-history', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
        ]);

        $output = Artisan::output();
        $this->assertNotSame(0, $exit, 'A null command field is the canonical hand-edit signal. Output was: '.$output);
        $this->assertStringContainsString('command', $output);
        $this->assertStringContainsString('api.treasury.001', $output);
    }

    public function test_verify_history_refuses_when_actor_is_null(): void
    {
        $this->reseedInventory(function (array $doc): array {
            /** @var list<array<string, mixed>> $callsites */
            $callsites = $doc['callsites'];
            foreach ($callsites as $idx => $cs) {
                if (($cs['id'] ?? null) === 'api.treasury.001') {
                    /** @var list<array<string, mixed>> $history */
                    $history = $cs['history'];
                    $history[0]['actor'] = null;
                    $cs['history'] = $history;
                    $callsites[$idx] = $cs;
                }
            }
            $doc['callsites'] = $callsites;

            return $doc;
        });

        $exit = Artisan::call('sweep:inventory:verify-history', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
        ]);

        $output = Artisan::output();
        $this->assertNotSame(0, $exit, 'A null actor must be rejected. Output was: '.$output);
        $this->assertStringContainsString('actor', $output);
    }

    public function test_verify_history_refuses_when_target_ids_is_empty(): void
    {
        $this->reseedInventory(function (array $doc): array {
            /** @var list<array<string, mixed>> $callsites */
            $callsites = $doc['callsites'];
            foreach ($callsites as $idx => $cs) {
                if (($cs['id'] ?? null) === 'api.treasury.001') {
                    /** @var list<array<string, mixed>> $history */
                    $history = $cs['history'];
                    $history[0]['target_ids'] = [];
                    $cs['history'] = $history;
                    $callsites[$idx] = $cs;
                }
            }
            $doc['callsites'] = $callsites;

            return $doc;
        });

        $exit = Artisan::call('sweep:inventory:verify-history', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
        ]);

        $output = Artisan::output();
        $this->assertNotSame(0, $exit, 'Empty target_ids must be rejected. Output was: '.$output);
        $this->assertStringContainsString('target_ids', $output);
    }

    public function test_verify_history_refuses_when_target_ids_references_unknown_id(): void
    {
        $this->reseedInventory(function (array $doc): array {
            /** @var list<array<string, mixed>> $callsites */
            $callsites = $doc['callsites'];
            foreach ($callsites as $idx => $cs) {
                if (($cs['id'] ?? null) === 'api.treasury.001') {
                    /** @var list<array<string, mixed>> $history */
                    $history = $cs['history'];
                    $history[0]['target_ids'] = ['api.does-not-exist.999'];
                    $cs['history'] = $history;
                    $callsites[$idx] = $cs;
                }
            }
            $doc['callsites'] = $callsites;

            return $doc;
        });

        $exit = Artisan::call('sweep:inventory:verify-history', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
        ]);

        $output = Artisan::output();
        $this->assertNotSame(0, $exit, 'Unknown target_id must be rejected. Output was: '.$output);
        $this->assertStringContainsString('api.does-not-exist.999', $output);
    }

    public function test_verify_history_refuses_when_chain_is_broken(): void
    {
        // Append a synthetic second event whose previous_yaml_sha256 does
        // NOT match event[0]'s new_yaml_sha256. The seed leaves event[0]
        // chain hashes null (initial generate event), so event[1] must
        // follow with previous == event[0].new_yaml_sha256 — also null.
        // We deliberately set previous to a non-matching hex string.
        $this->reseedInventory(function (array $doc): array {
            /** @var list<array<string, mixed>> $callsites */
            $callsites = $doc['callsites'];
            foreach ($callsites as $idx => $cs) {
                if (($cs['id'] ?? null) === 'api.treasury.001') {
                    /** @var list<array<string, mixed>> $history */
                    $history = $cs['history'];
                    $history[0]['new_yaml_sha256'] = str_repeat('a', 64);
                    $history[] = [
                        'at' => '2026-05-03T01:00:00Z',
                        'actor' => 'claude',
                        'action' => 'claim',
                        'command' => 'sweep:inventory:claim',
                        'previous_yaml_sha256' => str_repeat('b', 64),
                        'new_yaml_sha256' => str_repeat('c', 64),
                        'target_ids' => ['api.treasury.001'],
                        'from_status' => 'pending',
                        'to_status' => 'claimed',
                        'commit' => null,
                        'test' => null,
                        'review_file' => null,
                        'review_commit' => null,
                        'note' => 'broken chain test',
                    ];
                    $cs['history'] = $history;
                    $callsites[$idx] = $cs;
                }
            }
            $doc['callsites'] = $callsites;

            return $doc;
        });

        $exit = Artisan::call('sweep:inventory:verify-history', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
        ]);

        $output = Artisan::output();
        $this->assertNotSame(0, $exit, 'A broken chain must be rejected. Output was: '.$output);
        $this->assertStringContainsString('chain', $output);
    }

    public function test_verify_history_refuses_when_previous_yaml_sha256_is_null_on_non_first_event(): void
    {
        $this->reseedInventory(function (array $doc): array {
            /** @var list<array<string, mixed>> $callsites */
            $callsites = $doc['callsites'];
            foreach ($callsites as $idx => $cs) {
                if (($cs['id'] ?? null) === 'api.treasury.001') {
                    /** @var list<array<string, mixed>> $history */
                    $history = $cs['history'];
                    $history[] = [
                        'at' => '2026-05-03T01:00:00Z',
                        'actor' => 'claude',
                        'action' => 'claim',
                        'command' => 'sweep:inventory:claim',
                        'previous_yaml_sha256' => null,
                        'new_yaml_sha256' => str_repeat('c', 64),
                        'target_ids' => ['api.treasury.001'],
                        'from_status' => 'pending',
                        'to_status' => 'claimed',
                        'commit' => null,
                        'test' => null,
                        'review_file' => null,
                        'review_commit' => null,
                        'note' => 'null-prev-on-non-first test',
                    ];
                    $cs['history'] = $history;
                    $callsites[$idx] = $cs;
                }
            }
            $doc['callsites'] = $callsites;

            return $doc;
        });

        $exit = Artisan::call('sweep:inventory:verify-history', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
        ]);

        $output = Artisan::output();
        $this->assertNotSame(0, $exit, 'A null previous_yaml_sha256 on a non-first event must be rejected. Output was: '.$output);
        $this->assertStringContainsString('previous_yaml_sha256', $output);
    }

    public function test_verify_history_prints_summary_with_count_of_events_and_problems(): void
    {
        $exit = Artisan::call('sweep:inventory:verify-history', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
        ]);
        $this->assertSame(0, $exit);

        $output = Artisan::output();
        // The seed has 2 callsites with 1 history event each.
        $this->assertMatchesRegularExpression('/verified\s+2\s+event/', $output);
        $this->assertMatchesRegularExpression('/across\s+2\s+callsite/', $output);
        $this->assertMatchesRegularExpression('/0\s+problem/', $output);
    }

    public function test_verify_history_does_not_mutate_yaml(): void
    {
        $before = file_get_contents($this->inventoryPath);

        Artisan::call('sweep:inventory:verify-history', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
        ]);

        $after = file_get_contents($this->inventoryPath);
        $this->assertSame($before, $after, 'sweep:inventory:verify-history MUST be read-only — YAML must be byte-identical.');
    }
}
