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

    /**
     * Closes the splice exploit surfaced by the 2A.4 audit. With an action-
     * based relaxation, an attacker could insert a fake `regenerate` event
     * with `new_yaml_sha256: null` followed by a malicious workflow event with
     * any `previous_yaml_sha256`; the action carve-out would short-circuit
     * the chain check at the boundary. The eventIndex==1 carve-out only
     * accepts a single arbitrary previous-hash slot (immediately after the
     * seed), so the splice fails at history[2].
     */
    public function test_verify_history_refuses_splice_after_fake_regenerate_event(): void
    {
        $this->reseedInventory(function (array $doc): array {
            /** @var list<array<string, mixed>> $callsites */
            $callsites = $doc['callsites'];
            foreach ($callsites as $idx => $cs) {
                if (($cs['id'] ?? null) === 'api.treasury.001') {
                    /** @var list<array<string, mixed>> $history */
                    $history = $cs['history'];
                    // history[1]: a fake regenerate event with null new hash.
                    // Without the splice fix this would be silently allowed
                    // (eventIndex==1 may declare arbitrary previous_yaml_sha256).
                    $history[] = [
                        'at' => '2026-05-03T00:30:00Z',
                        'actor' => 'generator',
                        'action' => 'regenerate',
                        'command' => 'sweep:inventory:generate',
                        'previous_yaml_sha256' => str_repeat('a', 64),
                        'new_yaml_sha256' => null,
                        'target_ids' => ['api.treasury.001'],
                        'from_status' => 'pending',
                        'to_status' => 'pending',
                        'commit' => null,
                        'test' => null,
                        'review_file' => null,
                        'review_commit' => null,
                        'note' => 'fake regenerate inserted to splice the chain',
                    ];
                    // history[2]: a malicious workflow event with arbitrary
                    // previous_yaml_sha256. With the eventIndex!=1 fix, this
                    // slot is checked strictly: previousNewHash is null AND
                    // eventIndex == 2, so the chain-broken branch fires.
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
                        'note' => 'malicious workflow event spliced after fake regenerate',
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
        $this->assertNotSame(
            0,
            $exit,
            'verify-history must reject a malicious workflow event spliced after a fake regenerate (slot-based gating, not action-based). Output was: '.$output,
        );
        $this->assertStringContainsString('chain', $output);
    }

    /**
     * File-level hand-edit defence (audit finding #2): a hand-edit that
     * bumps both content AND metadata.yaml_sha256 to its new value would
     * otherwise survive verify-history's chain checks unobserved. The
     * canonical-hash recomputation closes that window.
     */
    public function test_verify_history_refuses_when_metadata_yaml_sha256_does_not_match_content(): void
    {
        // Hand-edit metadata.yaml_sha256 to a non-canonical value. We can't
        // use reseedInventory() here because it normalises the hash via the
        // canonical algorithm — we need to bypass that.
        $raw = (string) file_get_contents($this->inventoryPath);
        $tampered = preg_replace(
            '/^(\s*yaml_sha256:\s*)[\'"]?[a-f0-9]{64}[\'"]?$/m',
            '$1\''.str_repeat('e', 64).'\'',
            $raw,
            1,
        );
        $this->assertNotNull($tampered, 'preg_replace must succeed.');
        $this->assertNotSame($raw, $tampered, 'tampered YAML must differ from original.');
        file_put_contents($this->inventoryPath, $tampered);

        $exit = Artisan::call('sweep:inventory:verify-history', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
        ]);

        $output = Artisan::output();
        $this->assertNotSame(
            0,
            $exit,
            'verify-history must reject when metadata.yaml_sha256 does not equal the recomputed canonical hash. Output was: '.$output,
        );
        $this->assertStringContainsString('metadata.yaml_sha256', $output);
    }

    /**
     * Codex BLOCK finding #2: a forged TERMINAL workflow event with
     * `new_yaml_sha256: null` would have passed the prior chain check
     * (no successor existed to expose the null as a broken predecessor link).
     * The added per-event `new_yaml_sha256` non-null requirement closes this.
     */
    public function test_verify_history_refuses_terminal_forged_event_with_null_new_yaml_sha256(): void
    {
        $this->reseedInventory(function (array $doc): array {
            /** @var list<array<string, mixed>> $callsites */
            $callsites = $doc['callsites'];
            foreach ($callsites as $idx => $cs) {
                if (($cs['id'] ?? null) === 'api.treasury.001') {
                    /** @var list<array<string, mixed>> $history */
                    $history = $cs['history'];
                    // history[0] is the seed event with new_yaml_sha256: null.
                    // history[1] is a single forged terminal event whose
                    // previous_yaml_sha256 is non-null (allowed by the slot
                    // relaxation at eventIndex==1) AND whose new_yaml_sha256
                    // is null. The prior chain check would not have noticed
                    // because no history[2] existed to fail the link.
                    $history[] = [
                        'at' => '2026-05-04T00:30:00Z',
                        'actor' => 'claude',
                        'action' => 'claim',
                        'command' => 'sweep:inventory:claim',
                        'previous_yaml_sha256' => str_repeat('a', 64),
                        'new_yaml_sha256' => null,
                        'target_ids' => ['api.treasury.001'],
                        'from_status' => 'pending',
                        'to_status' => 'claimed',
                        'commit' => null,
                        'test' => null,
                        'review_file' => null,
                        'review_commit' => null,
                        'note' => 'forged terminal event with null new_yaml_sha256',
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
        $this->assertNotSame(
            0,
            $exit,
            'verify-history must reject a terminal forged event whose new_yaml_sha256 is null (Codex BLOCK finding #2). Output was: '.$output,
        );
        $this->assertStringContainsString('new_yaml_sha256', $output);
    }

    /**
     * Codex round-2 finding #6 (CRITICAL): the round-1 fix only checked
     * `new_yaml_sha256 !== null`. An attacker can supply any non-null value
     * including a bogus 64-char string. Format validation + the document-level
     * anchor check catch the forgery: even if the hash is well-formed, it
     * doesn't match the stored metadata.yaml_sha256 (which was not updated
     * because canonicalForHashing zeroes chain fields before computing).
     */
    public function test_verify_history_refuses_terminal_forged_event_with_fake_non_null_new_yaml_sha256(): void
    {
        $fakeHash = str_repeat('f', 64); // valid 64-char hex but not a real document hash
        $this->reseedInventory(function (array $doc) use ($fakeHash): array {
            /** @var list<array<string, mixed>> $callsites */
            $callsites = $doc['callsites'];
            foreach ($callsites as $idx => $cs) {
                if (($cs['id'] ?? null) === 'api.treasury.001') {
                    /** @var list<array<string, mixed>> $history */
                    $history = $cs['history'];
                    // Forged terminal event: previous_yaml_sha256 set to a
                    // matching value (the seed's null is bridged by the
                    // eventIndex==1 relaxation), new_yaml_sha256 set to a
                    // valid-format hex string that doesn't anchor to metadata.
                    $history[] = [
                        'at' => '2026-05-04T01:00:00Z',
                        'actor' => 'claude',
                        'action' => 'claim',
                        'command' => 'sweep:inventory:claim',
                        'previous_yaml_sha256' => str_repeat('a', 64),
                        'new_yaml_sha256' => $fakeHash,
                        'target_ids' => ['api.treasury.001'],
                        'from_status' => 'pending',
                        'to_status' => 'claimed',
                        'commit' => null,
                        'test' => null,
                        'review_file' => null,
                        'review_commit' => null,
                        'note' => 'forged terminal event with non-null fake new_yaml_sha256',
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
        $this->assertNotSame(
            0,
            $exit,
            'verify-history must reject a terminal forged event whose new_yaml_sha256 is a valid-looking hex string that does not anchor to metadata.yaml_sha256 (Codex round-2 finding #6). Output was: '.$output,
        );
        $this->assertStringContainsString('anchor', $output);
    }

    /**
     * Format validation: a non-seed event with a malformed new_yaml_sha256
     * (wrong length, uppercase, non-hex characters) must be rejected even
     * before the anchor check fires.
     */
    public function test_verify_history_refuses_event_with_malformed_new_yaml_sha256(): void
    {
        foreach (['too-short', 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'ggggggggggggggggggggggggggggggggggggggggggggggggggggggggggggggg!'] as $malformed) {
            $this->reseedInventory(function (array $doc) use ($malformed): array {
                /** @var list<array<string, mixed>> $callsites */
                $callsites = $doc['callsites'];
                foreach ($callsites as $idx => $cs) {
                    if (($cs['id'] ?? null) === 'api.treasury.001') {
                        /** @var list<array<string, mixed>> $history */
                        $history = $cs['history'];
                        $history[] = [
                            'at' => '2026-05-04T01:00:00Z',
                            'actor' => 'claude',
                            'action' => 'claim',
                            'command' => 'sweep:inventory:claim',
                            'previous_yaml_sha256' => str_repeat('a', 64),
                            'new_yaml_sha256' => $malformed,
                            'target_ids' => ['api.treasury.001'],
                            'from_status' => 'pending',
                            'to_status' => 'claimed',
                            'commit' => null,
                            'test' => null,
                            'review_file' => null,
                            'review_commit' => null,
                            'note' => "forged terminal with malformed hash: {$malformed}",
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
            $this->assertNotSame(
                0,
                $exit,
                "verify-history must reject malformed new_yaml_sha256 '{$malformed}'. Output was: ".$output,
            );
        }
    }

    /**
     * Negative control: the legitimate happy-path workflow (claim through real
     * artisan commands) MUST continue to pass verify-history after the
     * format + anchor checks land. Catches accidental over-rejection.
     */
    public function test_verify_history_passes_after_real_workflow_with_anchor_check_active(): void
    {
        // Drive a real claim through the artisan command. mutate() will stamp
        // the post-mutation hash on the new event AND set metadata.yaml_sha256
        // to the same value, so the anchor check trivially passes.
        $this->assertSame(0, Artisan::call('sweep:inventory:claim', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--actor' => 'claude',
        ]));

        $exit = Artisan::call('sweep:inventory:verify-history', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
        ]);

        $output = Artisan::output();
        $this->assertSame(
            0,
            $exit,
            'verify-history must pass after a legitimate claim — the anchor check is satisfied because mutate() stamps metadata.yaml_sha256 onto the new event. Output was: '.$output,
        );
    }
}
