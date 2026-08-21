<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Liveness test for scripts/run-feature-lane-local.sh — the O-29 interim execution path.
 *
 * WHY THIS FILE EXISTS (gate-r2 R2-6)
 * -----------------------------------
 * The round-1 fix round claimed "every fix is pinned by a liveness case". That was
 * false for this script: its guards were verified by hand-run probes pasted into a
 * report, and nothing re-ran them. The reviewer was right to call the claim, and the
 * choice here is to make the claim TRUE rather than to soften it — because the thing
 * these guards prevent is a `RefreshDatabase` suite dropping every table in a
 * database that is not a throwaway, and "we checked once, by hand, in August" is not
 * a control for that.
 *
 * The script's guards are deliberately reachable without docker, a database or a
 * PHP application: they run at argument/environment-resolution time, and `--dry-run`
 * exits after them and before the first side effect. So every case below executes
 * the REAL script — no copy, no re-implementation of its logic — and asserts on its
 * exit code and message.
 *
 * Deliberately in its own FILE (not `tests/Architecture` wholesale) and wired as its
 * own step in the `backend-architecture` job, matching the convention
 * docs/conventions/08-DETECTOR-LIVENESS.md and the sibling
 * FeatureLaneManifestCheckerTest: that directory carries pre-existing failures this
 * work does not own.
 */
final class FeatureLaneLocalHarnessTest extends TestCase
{
    private const LANE = 'feature-lane-platform-misc';

    private string $script;

    protected function setUp(): void
    {
        parent::setUp();

        $this->script = dirname(__DIR__, 4).'/scripts/run-feature-lane-local.sh';
        self::assertFileExists($this->script);

        // The script reads the lane's group list with jq. Asserted rather than
        // skipped: a missing jq must not quietly turn this suite into a no-op.
        self::assertNotEmpty(shell_exec('command -v jq'), 'jq is required by the harness under test');
    }

    /**
     * @param  array<string,string>  $env
     * @param  list<string>  $args
     * @return array{0:int,1:string}
     */
    private function runHarness(array $env = [], array $args = ['--dry-run']): array
    {
        $prefix = '';
        foreach ($env as $key => $value) {
            $prefix .= $key.'='.escapeshellarg($value).' ';
        }

        $command = $prefix.'bash '.escapeshellarg($this->script).' '.self::LANE.' '
            .implode(' ', array_map('escapeshellarg', $args)).' 2>&1';

        $output = [];
        $exit = 0;
        exec($command, $output, $exit);

        return [$exit, implode("\n", $output)];
    }

    /**
     * THE CORE PROPERTY: a hostile ambient environment is NEUTRALISED, not obeyed.
     *
     * Every variable set here is one a developer might genuinely have exported —
     * `DB_CENTRAL_*` in particular is what a staging console session leaves behind,
     * and the `central` connection is hardcoded pgsql and prefers it over `DB_*`,
     * so it reaches `TenantProvisioningService`'s unconditional DELETEs.
     */
    public function test_a_hostile_ambient_environment_is_ignored_entirely(): void
    {
        [$exit, $out] = $this->runHarness([
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => '10.9.9.9',
            'DB_PORT' => '5432',
            'DB_DATABASE' => 'autoerp',
            'DB_URL' => 'postgres://evil.example.com/autoerp',
            'DB_CENTRAL_HOST' => 'staging.example.com',
            'DB_CENTRAL_DATABASE' => 'synerivia_central',
            'DB_CENTRAL_URL' => 'postgres://staging.example.com/synerivia_central',
            'REDIS_HOST' => '10.9.9.9',
        ]);

        self::assertSame(0, $exit, $out);
        self::assertStringContainsString('default=pgsql://127.0.0.1:5433/autoerp_lane_test', $out);
        self::assertStringContainsString('central=pgsql://127.0.0.1:5433/autoerp_lane_test', $out);
        self::assertStringContainsString('redis=127.0.0.1:6380', $out);
        self::assertStringNotContainsString('10.9.9.9', $out);
        self::assertStringNotContainsString('staging.example.com', $out);
        self::assertStringNotContainsString('synerivia_central', $out);
    }

    /** `--sqlite` forces the default connection AND still pins `central` to loopback. */
    public function test_sqlite_mode_forces_sqlite_and_still_pins_the_central_connection(): void
    {
        [$exit, $out] = $this->runHarness(
            ['DB_CONNECTION' => 'pgsql', 'DB_CENTRAL_HOST' => 'staging.example.com'],
            ['--sqlite', '--dry-run'],
        );

        self::assertSame(0, $exit, $out);
        self::assertStringContainsString('default=sqlite', $out);
        self::assertStringContainsString('central=pgsql://127.0.0.1:5433/autoerp_lane_test', $out);
        self::assertStringNotContainsString('staging.example.com', $out);
    }

    public function test_it_refuses_a_non_loopback_database_host(): void
    {
        [$exit, $out] = $this->runHarness(['LANE_DB_HOST' => '10.9.9.9']);

        self::assertSame(2, $exit, $out);
        self::assertStringContainsString('non-loopback host', $out);
    }

    public function test_it_refuses_a_non_loopback_redis_host(): void
    {
        [$exit, $out] = $this->runHarness(['LANE_REDIS_HOST' => '10.9.9.9']);

        self::assertSame(2, $exit, $out);
        self::assertStringContainsString('LANE_REDIS_HOST', $out);
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function realDatabaseNames(): array
    {
        return [
            'the dev database' => ['LANE_DB_DATABASE', 'autoerp'],
            'a tenant database' => ['LANE_DB_DATABASE', 'tenant_0192b8f2'],
            'the central directory' => ['LANE_DB_CENTRAL_DATABASE', 'synerivia_central'],
            'a near-miss name' => ['LANE_DB_DATABASE', 'autoerp_testing_backup'],
        ];
    }

    #[DataProvider('realDatabaseNames')]
    public function test_it_refuses_a_database_name_outside_the_throwaway_pattern(string $variable, string $name): void
    {
        [$exit, $out] = $this->runHarness([$variable => $name]);

        self::assertSame(2, $exit, $out);
        self::assertStringContainsString('refusing to run destructive', $out);
        self::assertStringContainsString($name, $out);
    }

    /** The shared dev container must never be clamped — other projects use it. */
    public function test_it_refuses_to_clamp_the_shared_postgres_container(): void
    {
        [$exit, $out] = $this->runHarness([], ['--docker-db-limits', '--dry-run']);

        self::assertSame(2, $exit, $out);
        self::assertStringContainsString('refuses to clamp the SHARED container', $out);
    }

    /** A dry run must create nothing at all — no log directory, no database. */
    public function test_a_dry_run_has_no_side_effects(): void
    {
        $sessions = dirname(__DIR__, 4).'/docs/sessions/feature-lanes';
        $before = is_dir($sessions) ? scandir($sessions) : [];

        [$exit, $out] = $this->runHarness();
        self::assertSame(0, $exit, $out);
        self::assertStringContainsString('no database created and no test executed', $out);

        $after = is_dir($sessions) ? scandir($sessions) : [];
        self::assertSame($before, $after, 'a dry run must not create a log directory');
    }
}
