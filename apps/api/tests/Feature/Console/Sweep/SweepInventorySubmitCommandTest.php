<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Sweep;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Feature test for `php artisan sweep:inventory:submit`.
 *
 * Master plan reference:
 *   - Section 4 — workflow state machine (submit: in_progress → under_review).
 *
 * Surface: callsite-scoped only. There is no per-cluster submit in the master
 * plan — every fix has its own commit + regression test, so submission is
 * inherently per-callsite. --cluster is refused.
 *
 * Required options:
 *   --callsite-id, --commit=<sha>, --test=<path::name>. All three must be
 *   non-empty strings.
 *
 * Cross-agent enforcement: --actor MUST equal the callsite's current `owner`
 * (set by claim/start). NO --force override — submitting on someone else's
 * callsite would corrupt attribution.
 *
 * On success the callsite gains:
 *   status=under_review, fix_commit=<--commit>, regression_test=<--test>,
 *   plus one history event (action=submit, commit/test fields populated).
 */
class SweepInventorySubmitCommandTest extends TestCase
{
    use SweepInventoryTestSeed;

    private const FIX_COMMIT = '7777777aaaaaaa1111111111222222223333333344';

    private const REGRESSION_TEST = 'tests/Feature/Treasury/TreasuryTenantIsolationTest.php::test_payment_method_scoped_exists';

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
     * Drive api.treasury.001 into in_progress so it can be submitted.
     */
    private function driveCallsiteIntoInProgress(): void
    {
        $this->claimClusterAndCallsites('api.treasury', 'claude');

        $exit = Artisan::call('sweep:inventory:start', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--actor' => 'claude',
        ]);
        $this->assertSame(0, $exit, 'precondition: start must succeed before testing submit.');
    }

    public function test_submit_transitions_in_progress_to_under_review_and_records_commit_and_test(): void
    {
        $this->driveCallsiteIntoInProgress();

        $exit = Artisan::call('sweep:inventory:submit', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--actor' => 'claude',
            '--commit' => self::FIX_COMMIT,
            '--test' => self::REGRESSION_TEST,
        ]);

        $this->assertSame(0, $exit, 'submit must succeed for an in_progress callsite owned by --actor.');

        $callsite = $this->callsiteById('api.treasury.001');
        $this->assertSame('under_review', $callsite['status']);
        $this->assertSame('claude', $callsite['owner']);
        $this->assertSame(self::FIX_COMMIT, $callsite['fix_commit']);
        $this->assertSame(self::REGRESSION_TEST, $callsite['regression_test']);

        /** @var list<array<string, mixed>> $history */
        $history = $callsite['history'];
        $latest = $history[count($history) - 1];
        $this->assertSame('submit', $latest['action']);
        $this->assertSame('claude', $latest['actor']);
        $this->assertSame('sweep:inventory:submit', $latest['command']);
        $this->assertSame('in_progress', $latest['from_status']);
        $this->assertSame('under_review', $latest['to_status']);
        $this->assertSame(self::FIX_COMMIT, $latest['commit']);
        $this->assertSame(self::REGRESSION_TEST, $latest['test']);
        $this->assertSame(['api.treasury.001'], $latest['target_ids']);
    }

    public function test_submit_refuses_when_actor_is_not_callsite_owner(): void
    {
        $this->driveCallsiteIntoInProgress();

        $exit = Artisan::call('sweep:inventory:submit', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--actor' => 'codex',
            '--commit' => self::FIX_COMMIT,
            '--test' => self::REGRESSION_TEST,
        ]);

        $this->assertNotSame(0, $exit, 'submit must refuse when --actor differs from callsite owner.');

        $callsite = $this->callsiteById('api.treasury.001');
        $this->assertSame('in_progress', $callsite['status']);
        $this->assertNull($callsite['fix_commit']);
    }

    public function test_submit_refuses_when_callsite_not_in_progress(): void
    {
        // Default seed: callsite is pending. Try to submit.
        $exit = Artisan::call('sweep:inventory:submit', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--actor' => 'claude',
            '--commit' => self::FIX_COMMIT,
            '--test' => self::REGRESSION_TEST,
        ]);

        $this->assertNotSame(0, $exit, 'submit must refuse when callsite is not in_progress.');

        $callsite = $this->callsiteById('api.treasury.001');
        $this->assertSame('pending', $callsite['status']);
    }

    public function test_submit_refuses_unknown_callsite(): void
    {
        $exit = Artisan::call('sweep:inventory:submit', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.999',
            '--actor' => 'claude',
            '--commit' => self::FIX_COMMIT,
            '--test' => self::REGRESSION_TEST,
        ]);

        $this->assertNotSame(0, $exit, 'submit must refuse for an unknown callsite.');
    }

    public function test_submit_requires_commit_option_non_empty(): void
    {
        $this->driveCallsiteIntoInProgress();

        $exit = Artisan::call('sweep:inventory:submit', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--actor' => 'claude',
            '--commit' => '',
            '--test' => self::REGRESSION_TEST,
        ]);

        $this->assertNotSame(0, $exit, '--commit must be non-empty.');

        $callsite = $this->callsiteById('api.treasury.001');
        $this->assertSame('in_progress', $callsite['status']);
        $this->assertNull($callsite['fix_commit']);
    }

    public function test_submit_requires_test_option_non_empty(): void
    {
        $this->driveCallsiteIntoInProgress();

        $exit = Artisan::call('sweep:inventory:submit', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--actor' => 'claude',
            '--commit' => self::FIX_COMMIT,
            '--test' => '',
        ]);

        $this->assertNotSame(0, $exit, '--test must be non-empty.');

        $callsite = $this->callsiteById('api.treasury.001');
        $this->assertSame('in_progress', $callsite['status']);
        $this->assertNull($callsite['regression_test']);
    }

    public function test_submit_refuses_cluster_mode(): void
    {
        $this->driveCallsiteIntoInProgress();

        $exit = Artisan::call('sweep:inventory:submit', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--cluster' => 'api.treasury',
            '--actor' => 'claude',
            '--commit' => self::FIX_COMMIT,
            '--test' => self::REGRESSION_TEST,
        ]);

        $this->assertNotSame(0, $exit, 'submit is callsite-scoped; --cluster must be refused.');

        $callsite = $this->callsiteById('api.treasury.001');
        $this->assertSame('in_progress', $callsite['status']);
    }

    public function test_submit_refuses_invalid_actor(): void
    {
        $this->driveCallsiteIntoInProgress();

        $exit = Artisan::call('sweep:inventory:submit', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.treasury.001',
            '--actor' => 'generator',
            '--commit' => self::FIX_COMMIT,
            '--test' => self::REGRESSION_TEST,
        ]);

        $this->assertNotSame(0, $exit, '--actor=generator is reserved and must be refused.');
    }
}
