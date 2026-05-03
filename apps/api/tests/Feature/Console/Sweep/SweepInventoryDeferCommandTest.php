<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Sweep;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Feature test for `php artisan sweep:inventory:defer`.
 *
 * Master plan reference:
 *   - Section 4 — workflow state machine (defer: pending|claimed → deferred).
 *
 * Modes:
 *   --callsite-id  Defer a single callsite.
 *   --cluster      Defer the cluster AND every pending or claimed callsite
 *                  within it. Refuses when there are no eligible callsites
 *                  (audit-trail invariant).
 *
 * Required:
 *   --reason          non-empty string.
 *   --revisit-date    strict YYYY-MM-DD format, must be a valid date.
 *
 * The revisit date is encoded in the history event's note field as
 * "<reason>; revisit by <YYYY-MM-DD>" — no new schema field is added.
 */
class SweepInventoryDeferCommandTest extends TestCase
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

    public function test_defer_callsite_transitions_pending_to_deferred_with_revisit_date_in_note(): void
    {
        $exit = Artisan::call('sweep:inventory:defer', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--reason' => 'Waiting for platform upgrade',
            '--revisit-date' => '2026-06-01',
            '--actor' => 'claude',
        ]);

        $this->assertSame(0, $exit, 'defer must succeed for a pending callsite.');

        $callsite = $this->callsiteById('api.treasury.001');
        $this->assertSame('deferred', $callsite['status']);

        /** @var list<array<string, mixed>> $history */
        $history = $callsite['history'];
        $latest = $history[count($history) - 1];
        $this->assertSame('defer', $latest['action']);
        $this->assertSame('claude', $latest['actor']);
        $this->assertSame('sweep:inventory:defer', $latest['command']);
        $this->assertSame('pending', $latest['from_status']);
        $this->assertSame('deferred', $latest['to_status']);
        $this->assertSame(['api.treasury.001'], $latest['target_ids']);
        $this->assertStringContainsString('2026-06-01', (string) $latest['note']);
        $this->assertStringContainsString('Waiting for platform upgrade', (string) $latest['note']);

        // Single-callsite mode must NOT touch cluster status.
        $cluster = $this->clusterById('api.treasury');
        $this->assertSame('pending', $cluster['status']);
    }

    public function test_defer_callsite_transitions_claimed_to_deferred(): void
    {
        $this->claimClusterAndCallsites('api.treasury', 'claude');

        $exit = Artisan::call('sweep:inventory:defer', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--reason' => 'Scope cut for current sprint',
            '--revisit-date' => '2026-07-15',
            '--actor' => 'claude',
        ]);

        $this->assertSame(0, $exit, 'defer must succeed for a claimed callsite.');

        $callsite = $this->callsiteById('api.treasury.001');
        $this->assertSame('deferred', $callsite['status']);

        /** @var list<array<string, mixed>> $history */
        $history = $callsite['history'];
        $latest = $history[count($history) - 1];
        $this->assertSame('defer', $latest['action']);
        $this->assertSame('claimed', $latest['from_status']);
        $this->assertSame('deferred', $latest['to_status']);
        $this->assertStringContainsString('2026-07-15', (string) $latest['note']);
    }

    public function test_defer_cluster_transitions_cluster_and_every_eligible_callsite_in_one_mutation(): void
    {
        // Add a second callsite in the treasury cluster, also pending.
        $this->reseedInventory(function (array $doc): array {
            /** @var list<array<string, mixed>> $callsites */
            $callsites = $doc['callsites'];
            $extra = $this->makeCallsite('api.treasury.002', 'api.treasury', 'c');
            $extra['status'] = 'claimed';
            $callsites[] = $extra;
            $doc['callsites'] = $callsites;

            return $doc;
        });

        $exit = Artisan::call('sweep:inventory:defer', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--cluster' => 'api.treasury',
            '--reason' => 'Full cluster deferred to next quarter',
            '--revisit-date' => '2026-09-01',
            '--actor' => 'codex',
        ]);

        $this->assertSame(0, $exit, 'cluster defer must succeed with eligible callsites.');

        $cluster = $this->clusterById('api.treasury');
        $this->assertSame('deferred', $cluster['status']);

        $callsite1 = $this->callsiteById('api.treasury.001');
        $this->assertSame('deferred', $callsite1['status']);

        /** @var list<array<string, mixed>> $history1 */
        $history1 = $callsite1['history'];
        $latest1 = $history1[count($history1) - 1];
        $this->assertSame('defer', $latest1['action']);
        $this->assertStringContainsString('2026-09-01', (string) $latest1['note']);

        $callsite2 = $this->callsiteById('api.treasury.002');
        $this->assertSame('deferred', $callsite2['status']);

        /** @var list<array<string, mixed>> $history2 */
        $history2 = $callsite2['history'];
        $latest2 = $history2[count($history2) - 1];
        $this->assertSame('defer', $latest2['action']);
    }

    public function test_defer_refuses_callsite_in_progress(): void
    {
        $this->reseedInventory(function (array $doc): array {
            /** @var list<array<string, mixed>> $callsites */
            $callsites = $doc['callsites'];
            foreach ($callsites as $idx => $cs) {
                if (($cs['id'] ?? null) === 'api.treasury.001') {
                    $cs['status'] = 'in_progress';
                    $callsites[$idx] = $cs;
                }
            }
            $doc['callsites'] = $callsites;

            return $doc;
        });

        $exit = Artisan::call('sweep:inventory:defer', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--reason' => 'Trying to defer in_progress',
            '--revisit-date' => '2026-06-01',
            '--actor' => 'claude',
        ]);

        $this->assertNotSame(
            0,
            $exit,
            'defer must refuse for in_progress status (only pending|claimed allowed).',
        );

        $callsite = $this->callsiteById('api.treasury.001');
        $this->assertSame('in_progress', $callsite['status'], 'callsite must remain in_progress after refusal.');
    }

    public function test_defer_refuses_when_reason_missing_or_empty(): void
    {
        $exitMissing = Artisan::call('sweep:inventory:defer', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--revisit-date' => '2026-06-01',
            '--actor' => 'claude',
        ]);

        $this->assertNotSame(0, $exitMissing, '--reason is required; must refuse when absent.');

        $exitEmpty = Artisan::call('sweep:inventory:defer', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--reason' => '',
            '--revisit-date' => '2026-06-01',
            '--actor' => 'claude',
        ]);

        $this->assertNotSame(0, $exitEmpty, '--reason is required; must refuse when empty.');

        $callsite = $this->callsiteById('api.treasury.001');
        $this->assertSame('pending', $callsite['status']);
    }

    /**
     * Tests multiple bad --revisit-date inputs: empty, "tomorrow", wrong separator, out-of-range.
     */
    public function test_defer_refuses_when_revisit_date_missing_empty_or_malformed(): void
    {
        $badInputs = [
            null,
            '',
            'tomorrow',
            '2026/05/01',
            '2026-13-01',
        ];

        foreach ($badInputs as $badDate) {
            $params = [
                '--inventory-path' => $this->inventoryPath,
                '--schema-path' => $this->schemaPath,
                '--callsite-id' => 'api.treasury.001',
                '--reason' => 'Defer test',
                '--actor' => 'claude',
            ];
            if ($badDate !== null) {
                $params['--revisit-date'] = $badDate;
            }

            $exit = Artisan::call('sweep:inventory:defer', $params);

            $this->assertNotSame(
                0,
                $exit,
                'defer must refuse for invalid --revisit-date value: '.var_export($badDate, true),
            );

            // Callsite must remain unchanged.
            $callsite = $this->callsiteById('api.treasury.001');
            $this->assertSame(
                'pending',
                $callsite['status'],
                'callsite must remain pending after refusal for date: '.var_export($badDate, true),
            );
        }
    }

    public function test_defer_refuses_unknown_callsite(): void
    {
        $exit = Artisan::call('sweep:inventory:defer', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.does-not-exist.999',
            '--reason' => 'Defer',
            '--revisit-date' => '2026-06-01',
            '--actor' => 'claude',
        ]);

        $this->assertNotSame(0, $exit, 'defer must refuse for an unknown callsite.');
    }

    public function test_defer_refuses_unknown_cluster(): void
    {
        $exit = Artisan::call('sweep:inventory:defer', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--cluster' => 'api.does-not-exist',
            '--reason' => 'Defer',
            '--revisit-date' => '2026-06-01',
            '--actor' => 'claude',
        ]);

        $this->assertNotSame(0, $exit, 'defer must refuse for an unknown cluster.');
    }

    public function test_defer_refuses_invalid_actor(): void
    {
        $exit = Artisan::call('sweep:inventory:defer', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--reason' => 'Defer',
            '--revisit-date' => '2026-06-01',
            '--actor' => 'generator',
        ]);

        $this->assertNotSame(0, $exit, '--actor=generator is reserved and must be refused.');

        $callsite = $this->callsiteById('api.treasury.001');
        $this->assertSame('pending', $callsite['status']);
    }

    public function test_defer_refuses_when_cluster_has_no_eligible_callsites(): void
    {
        // Seed: treasury callsite is in_progress — not eligible for defer.
        $this->reseedInventory(function (array $doc): array {
            /** @var list<array<string, mixed>> $callsites */
            $callsites = $doc['callsites'];
            foreach ($callsites as $idx => $cs) {
                if (($cs['cluster_id'] ?? null) === 'api.treasury') {
                    $cs['status'] = 'in_progress';
                    $callsites[$idx] = $cs;
                }
            }
            $doc['callsites'] = $callsites;

            return $doc;
        });

        $exit = Artisan::call('sweep:inventory:defer', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--cluster' => 'api.treasury',
            '--reason' => 'All in progress — no eligible callsites',
            '--revisit-date' => '2026-06-01',
            '--actor' => 'claude',
        ]);

        $this->assertNotSame(
            0,
            $exit,
            'cluster defer must refuse when there are no pending or claimed callsites (audit-trail invariant).',
        );
    }

    public function test_must_supply_either_callsite_id_or_cluster_not_both_not_neither(): void
    {
        $exitNeither = Artisan::call('sweep:inventory:defer', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--reason' => 'Defer',
            '--revisit-date' => '2026-06-01',
            '--actor' => 'claude',
        ]);
        $this->assertNotSame(0, $exitNeither, 'Must supply --callsite-id or --cluster.');

        $exitBoth = Artisan::call('sweep:inventory:defer', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--cluster' => 'api.treasury',
            '--reason' => 'Defer',
            '--revisit-date' => '2026-06-01',
            '--actor' => 'claude',
        ]);
        $this->assertNotSame(0, $exitBoth, 'Must NOT supply both --callsite-id and --cluster.');
    }
}
