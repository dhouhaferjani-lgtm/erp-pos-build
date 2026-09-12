<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The deploy entrypoint's PER-TENANT permission-cache reset must report its
 * outcome, and must not send its stderr to /dev/null.
 *
 * Why this is a test and not a review note: `tenants:run` iterates tenants with
 * no try/catch (Stancl `Tenancy::runForMultiple()`), so under
 * database-per-tenant ONE tenant whose database is missing aborts the loop and
 * every tenant after it keeps a stale permission snapshot for the cache TTL
 * (`config/permission.php` 24 h). `|| true` keeps that from blocking boot,
 * which is correct; `2>/dev/null` ALSO kept it out of the deploy log, which
 * made a partial reset indistinguishable from a full one. The house pattern is
 * two lines above, on `tenants:migrate-rolling` — the command that exists
 * BECAUSE per-tenant isolation was needed.
 *
 * This test does not assert isolation (the wave does not add it — see residual
 * R-2 in the wave-0a plan). It asserts VISIBILITY, and that never-blocks-boot
 * survives: `set -e` is disabled inside an `if` condition, so the `else` arm
 * runs and the script continues.
 */
final class EntrypointPermissionCacheResetShapeTest extends TestCase
{
    private const ENTRYPOINT = 'apps/api/docker/entrypoint.sh';

    private const PER_TENANT_COMMAND = 'php artisan tenants:run permission:cache-reset';

    #[Test]
    public function the_per_tenant_cache_reset_does_not_suppress_stderr(): void
    {
        foreach ($this->linesContaining(self::PER_TENANT_COMMAND) as $lineNumber => $line) {
            self::assertStringNotContainsString(
                '2>/dev/null',
                $line,
                self::ENTRYPOINT.':'.$lineNumber.' sends the per-tenant permission cache reset\'s stderr to /dev/null, '
                .'which hides an aborted tenants:run loop from the deploy log. Drop the redirect and report the outcome.',
            );
        }
    }

    #[Test]
    public function the_per_tenant_cache_reset_runs_inside_a_reporting_if_block(): void
    {
        $script = $this->script();

        self::assertStringContainsString(
            'if DB_HOST="$DIRECT_DB_HOST" '.self::PER_TENANT_COMMAND.'; then',
            $script,
            self::ENTRYPOINT.' must run the per-tenant reset as an `if` condition, following the '
            .'`tenants:migrate-rolling` pattern in the same file, so `set -e` cannot abort boot and both arms can report.',
        );
        self::assertStringContainsString(
            'Per-tenant permission cache reset: [completed]',
            $script,
            self::ENTRYPOINT.' must print a success line for the per-tenant reset.',
        );
        self::assertStringContainsString(
            'Per-tenant permission cache reset: [ABORTED',
            $script,
            self::ENTRYPOINT.' must print a distinguishable failure line naming the remediation for the per-tenant reset.',
        );
    }

    #[Test]
    public function the_central_base_key_reset_is_retained_and_still_never_blocks_boot(): void
    {
        self::assertStringContainsString(
            'php artisan permission:cache-reset 2>/dev/null || true',
            $this->script(),
            self::ENTRYPOINT.' must keep the bare central reset: in single-schema compatibility mode the '
            .'unsuffixed key is the LIVE key, and it is also the legacy pre-W0a-S1 shared key that must be evicted once.',
        );
    }

    private function script(): string
    {
        $path = dirname(base_path(), 2).'/'.self::ENTRYPOINT;
        self::assertFileExists($path, 'Could not resolve '.self::ENTRYPOINT);

        $contents = file_get_contents($path);
        self::assertIsString($contents, 'Could not read '.self::ENTRYPOINT);

        return $contents;
    }

    /**
     * @return array<int, string> one-based line number => line
     */
    private function linesContaining(string $needle): array
    {
        $matches = [];
        foreach (explode("\n", $this->script()) as $index => $line) {
            if (str_contains($line, $needle)) {
                $matches[$index + 1] = $line;
            }
        }

        self::assertNotSame([], $matches, self::ENTRYPOINT.' no longer contains "'.$needle.'"');

        return $matches;
    }
}
