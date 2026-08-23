<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Guard: every queue name a job is dispatched to MUST be consumed by a
 * Horizon supervisor.
 *
 * 2026-06-12 POS reports audit root cause: ApplyFiscalEventProjectionJob
 * (Task 23, 2026-05-18) was dispatched to `onQueue('fiscal-projections')`
 * but config/horizon.php only consumed ['default'] — projection rows sat
 * `pending` forever on every Horizon deployment, so `pos_receipts` never
 * materialized and every server-backed POS report (Today Sales, web-admin
 * sales) showed zero sales. Local dev masked it via QUEUE_CONNECTION=sync.
 *
 * This test scans app/ for `onQueue('...')` / `->onQueue("...")` literals
 * AND for `public $queue = '...'` property declarations, then asserts each
 * named queue appears in every Horizon supervisor defaults entry. If you add
 * a new named queue, add it to config/horizon.php `defaults.*.queue` (or give
 * it its own supervisor).
 *
 * The property form was added by the R-8 gate (finding P3-2). A queued
 * LISTENER never calls `onQueue()` — `Dispatcher::queueHandler()` reads
 * `$listener->queue ?? null` off the class instead — so
 * `PostShiftCashVarianceAdjustment`'s `public string $queue = 'default'` was
 * invisible to the guard. It happens to name a consumed queue, but the next
 * `public string $queue = '<new-queue>'` would have reproduced the 2026-06-12
 * `fiscal-projections` incident this test exists to prevent.
 */
class HorizonQueueCoverageTest extends TestCase
{
    public function test_every_dispatched_queue_is_consumed_by_a_horizon_supervisor(): void
    {
        $appPath = base_path('app');
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($appPath, \FilesystemIterator::SKIP_DOTS)
        );

        $dispatchedQueues = [];
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php' || ! $file->isFile() || ! $file->isReadable()) {
                continue;
            }
            $contents = @file_get_contents($file->getPathname());
            if ($contents === false) {
                continue;
            }
            if (preg_match_all("/onQueue\\(\\s*['\"]([^'\"]+)['\"]\\s*\\)/", $contents, $matches) > 0) {
                foreach ($matches[1] as $queue) {
                    $dispatchedQueues[$queue] = true;
                }
            }

            // R-8 gate P3-2 — the DECLARATION form. Queued listeners (and jobs
            // that prefer a property over a fluent call) name their queue as
            // `public string $queue = '…'`, which Dispatcher::queueHandler()
            // and Queue::pushOn() honour exactly like onQueue(). Optional
            // `string`/`?string` type and optional `readonly` are all accepted.
            if (preg_match_all(
                "/public\\s+(?:readonly\\s+)?(?:\\??string\\s+)?\\\$queue\\s*=\\s*['\"]([^'\"]+)['\"]/",
                $contents,
                $propertyMatches,
            ) > 0) {
                foreach ($propertyMatches[1] as $queue) {
                    $dispatchedQueues[$queue] = true;
                }
            }
        }

        $this->assertNotEmpty($dispatchedQueues, 'Expected at least one onQueue() callsite or $queue declaration in app/');

        $supervisors = config('horizon.defaults');
        $this->assertIsArray($supervisors);
        $this->assertNotEmpty($supervisors, 'horizon.defaults must define at least one supervisor');

        $consumedQueues = [];
        foreach ($supervisors as $supervisor) {
            if (! is_array($supervisor)) {
                continue;
            }
            $queues = $supervisor['queue'] ?? [];
            if (! is_array($queues)) {
                continue;
            }
            foreach ($queues as $queue) {
                if (is_string($queue)) {
                    $consumedQueues[$queue] = true;
                }
            }
        }

        $uncovered = array_diff_key($dispatchedQueues, $consumedQueues);

        $this->assertSame(
            [],
            array_keys($uncovered),
            'These queues receive jobs via onQueue() or a $queue declaration but NO Horizon supervisor '
            .'consumes them (jobs would sit in Redis forever): '.implode(', ', array_keys($uncovered))
            .'. Add them to config/horizon.php defaults.*.queue.'
        );
    }

    /**
     * R-8 gate P3-2 — the scanner must actually SEE the declaration form.
     *
     * Without this, a regression that silently drops the `$queue` property
     * branch would leave the test green (the `onQueue()` literals alone still
     * satisfy every assertion above) while the guard quietly stopped covering
     * every queued listener in the codebase.
     */
    public function test_the_scanner_sees_queue_declared_as_a_property(): void
    {
        $listener = base_path('app/Modules/Treasury/Application/Listeners/PostShiftCashVarianceAdjustment.php');
        $this->assertFileExists($listener);

        $contents = (string) file_get_contents($listener);
        $this->assertMatchesRegularExpression(
            "/public\\s+(?:readonly\\s+)?(?:\\??string\\s+)?\\\$queue\\s*=\\s*['\"]([^'\"]+)['\"]/",
            $contents,
            'The $queue property scanner no longer matches a real queued listener declaration.',
        );
    }

    /**
     * Guard: every APP_ENV we deploy with MUST match an entry in
     * horizon.environments.
     *
     * 2026-07-03 staging media audit root cause: Horizon's
     * ProvisioningPlan::deploy() silently starts ZERO supervisors when the
     * current environment matches no horizon.environments key — staging ran
     * a "healthy" Horizon master that consumed nothing, so every queued job
     * (images renditions, fiscal-projections, imports, enrichment) sat in
     * Redis forever on erp.otospex.dev.
     */
    public function test_every_deploy_environment_has_a_horizon_provisioning_entry(): void
    {
        $deployEnvironments = ['production', 'staging', 'local'];

        $plans = config('horizon.environments');
        $this->assertIsArray($plans);

        foreach ($deployEnvironments as $environment) {
            // Mirror ProvisioningPlan::deploy(): first key matching Str::is wins.
            $matched = collect($plans)->first(
                fn ($_, string $name): bool => Str::is($name, $environment)
            );

            $this->assertNotEmpty(
                $matched,
                "APP_ENV={$environment} matches no horizon.environments entry — Horizon would "
                .'start zero supervisors and every queued job would sit in Redis forever. '
                .'Add a supervisor plan for it in config/horizon.php.'
            );
        }
    }
}
