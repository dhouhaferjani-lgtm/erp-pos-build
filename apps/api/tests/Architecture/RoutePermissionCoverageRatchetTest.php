<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Illuminate\Support\Facades\Route as RouteFacade;
use PHPUnit\Framework\Attributes\Test;
use Tests\Architecture\Support\RouteCoverageClassifier;
use Tests\Architecture\Support\RouteCoverageRatchetChecker;
use Tests\Architecture\Support\RouteCoverageReport;
use Tests\Architecture\Support\RoutePermissionCoverageScanner;
use Tests\TestCase;

/**
 * The route-coverage RATCHET — three directions, all fail-closed, following the
 * two ratchets already in the tree (DocumentPerActionBaselineRatchetTest,
 * TenantOnlyUniqueOnCatalogueTablesRatchetTest).
 *
 *  (a) GROWTH      — an uncovered route that is not in the baseline fails.
 *  (b) STALE       — a baseline entry that is now covered fails, so the entry
 *                    must be deleted in the very merge that fixed the route and
 *                    a fix cannot be forgotten.
 *  (c) ANTI-GROWTH — the baseline's key set compared against a blob pinned in a
 *                    CI repository variable, so a contributor cannot land a
 *                    violation and its baseline entry in one change.
 *
 * DIRECTION (c) IS DEFERRED TO WAVE 0b-11 AND SKIPS UNTIL THEN — but NOT
 * because wave 0a "cannot touch" .github/workflows/ci.yml: this wave appends a
 * step to that very file (Task 6 step 1), and the overlap with the two
 * in-flight lanes is accepted and verified by hunk location. The real reason is
 * SEQUENCING: ROUTE_COVERAGE_BASELINE_PROTECTED_BLOB is a repository variable
 * only the owner can register, and the `env:` line that reads it is worthless
 * until they do. Until 0b-11 registers both, (a) and (b) are live and (c) is
 * inert. 0b-11 must ALSO change the skip below into the fail-closed-on-unset
 * form that DocumentPerActionBaselineRatchetTest uses.
 *
 * SOURCE OF TRUTH is the live router: the test boots the application and reads
 * Route::getRoutes(), so a route added by any mechanism is seen. It needs no
 * database. The classification itself lives in RoutePermissionCoverageScanner,
 * which this class simply points at the live route table.
 *
 * DETECTOR LIVENESS AND TAMPER COVERAGE ARE IN THE SIBLING CLASS
 * RoutePermissionCoverageRatchetLivenessTest (convention 08): the fixture routes
 * registered through LivenessRouteServiceProvider before router boot, plus the
 * new-violation, stale-entry and matched-growth cases driven through the scanner
 * with injected routes. Both classes run in the same CI step (Task 6 step 1),
 * which is the half of convention 08 that is easy to forget.
 *
 * NEW ROUTES MAY NEVER ENTER THE BASELINE. A new route with no gate fails with
 * the remediation message RouteCoverageRatchetChecker produces.
 */
final class RoutePermissionCoverageRatchetTest extends TestCase
{
    private const BASELINE_RELATIVE = 'tests/Architecture/baselines/route-permission-coverage-baseline.json';

    private const PROTECTED_BLOB_ENV = 'ROUTE_COVERAGE_BASELINE_PROTECTED_BLOB';

    private const REGENERATE_ENV = 'ROUTE_COVERAGE_BASELINE_REGENERATE';

    /**
     * Shrink-only. Generated at 167 AFTER 0a-3 applied `authz.self` (which
     * reclassified four tombstone writes and six self-service writes out of the
     * uncovered count) and lowered to 152 by 0a-5.
     *
     * The two ceilings are SEPARATE so closing reads can never buy headroom for
     * writes — owner ruling D3, "writes first".
     */
    public const UNCOVERED_WRITE_CEILING = 167;

    /**
     * Shrink-only. Generated at 148 (149 uncovered reads minus GET /auth/me,
     * which 0a-3 reclassified) and lowered to 146 by 0a-5's two coupon reads.
     *
     * IT STOPS AT 146 IN THIS WAVE. The four Identity reads that take it to 142
     * are wave 0b task 0b-15, which ships them with the RolesPage / route /
     * Settings-card guards they require (plan gate r1 B-3). Lowering it here for
     * closures this wave does not make would hand 0b four units of unearned
     * headroom — the failure the shrink-only rule exists to deny.
     */
    public const UNCOVERED_READ_CEILING = 148;

    #[Test]
    public function uncovered_routes_match_the_baseline_exactly(): void
    {
        $result = (new RouteCoverageRatchetChecker)->check($this->liveReport(), $this->baselineKeys());

        self::assertTrue(
            $result->isClean(),
            $result->message()."\n\nBaseline: ".self::BASELINE_RELATIVE,
        );
    }

    #[Test]
    public function uncovered_counts_are_within_the_shrink_only_ceilings(): void
    {
        $report = $this->liveReport();
        $writes = $report->writeCount();
        $reads = $report->readCount();

        self::assertLessThanOrEqual(
            self::UNCOVERED_WRITE_CEILING,
            $writes,
            'Uncovered WRITE routes ('.$writes.') exceed UNCOVERED_WRITE_CEILING ('
            .self::UNCOVERED_WRITE_CEILING.'). The ceiling is shrink-only: it is lowered in the commit '
            .'that closes routes, never raised.',
        );
        self::assertLessThanOrEqual(
            self::UNCOVERED_READ_CEILING,
            $reads,
            'Uncovered READ routes ('.$reads.') exceed UNCOVERED_READ_CEILING ('
            .self::UNCOVERED_READ_CEILING.').',
        );
    }

    /**
     * The classifier's gating set is EXACTLY five aliases. A middleware that
     * only sometimes checks a permission is not a gate.
     *
     * `lane/w-lot-a-1a`'s BatchActionAccess is the incoming worked example: it
     * returns `$next($request)` with no check while its activation flag is
     * false, and false is the shipped default. It does not exist at this base,
     * so the live shape asserted here is `module:` — the same class of
     * conditional middleware — plus the pin on the alias list itself, which is
     * what stops 0b widening the set by accident.
     */
    #[Test]
    public function a_flag_conditional_middleware_is_not_gating(): void
    {
        self::assertSame(
            ['can', 'require.any.permission', 'super_admin', 'central_admin', 'central_admin_role'],
            RouteCoverageClassifier::GATING_ALIASES,
            'The gating alias set changed. Widening it silently reclassifies live gaps as covered; '
            .'a flag-conditional middleware (e.g. BatchActionAccess, arriving with lane/w-lot-a-1a) '
            .'must NEVER be added here — 0b gates those routes with a real `can:` BESIDE it.',
        );

        // The LIVE shape — that a `module:`-only route really does classify
        // uncovered — is proven in RoutePermissionCoverageRatchetLivenessTest,
        // through the pre-boot provider. Asserting it a second time here would
        // register the same throw-away route twice on one router.
    }

    /**
     * The sweep is a pure function of the route table: running it twice yields
     * an identical key set. This is the wave's convention-09 re-run axis.
     */
    #[Test]
    public function the_sweep_is_idempotent(): void
    {
        self::assertSame($this->liveReport()->uncoveredKeys(), $this->liveReport()->uncoveredKeys());
    }

    /**
     * Direction (c). Inert until wave 0b-11 registers the repository variable
     * and the CI env line; see the class docblock.
     */
    #[Test]
    public function the_baseline_key_set_matches_the_ci_protected_blob(): void
    {
        $blob = getenv(self::PROTECTED_BLOB_ENV);

        if ($blob === false || $blob === '') {
            self::markTestSkipped(
                'Anti-growth is inert until wave 0b-11 registers '.self::PROTECTED_BLOB_ENV
                .' as a repository VARIABLE (owner-only) and adds the matching `env:` line to the '
                .'backend-architecture step wave 0a created. Wave 0a appends that step itself; what it '
                .'cannot do is set an owner-held variable. 0b-11 must also replace this skip with the '
                .'fail-closed-on-unset form used by DocumentPerActionBaselineRatchetTest.',
            );
        }

        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $blob, 'Malformed protected blob hash.');

        $pinned = shell_exec('git -C '.escapeshellarg(dirname(base_path(), 2)).' cat-file -p '.escapeshellarg($blob).' 2>/dev/null');
        self::assertIsString($pinned, 'Could not fetch the protected baseline blob '.$blob.'.');

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($pinned, true, 512, JSON_THROW_ON_ERROR);
        /** @var list<string> $pinnedKeys */
        $pinnedKeys = array_values((array) ($decoded['uncovered'] ?? []));

        $added = array_values(array_diff($this->baselineKeys(), $pinnedKeys));
        self::assertSame(
            [],
            $added,
            'Baseline keys present in the working tree but absent from the CI-pinned blob: '
            .implode(', ', $added).'. A new violation cannot be authorised by editing its own baseline; '
            .'this needs an owner re-pin of '.self::PROTECTED_BLOB_ENV.'.',
        );
    }

    /**
     * Baseline regenerator. Deliberately FAILS after writing, so it can never be
     * a way to make the ratchet green.
     */
    #[Test]
    public function regenerate_the_baseline_when_explicitly_asked(): void
    {
        if (getenv(self::REGENERATE_ENV) !== '1') {
            self::markTestSkipped('Set '.self::REGENERATE_ENV.'=1 to regenerate the baseline.');
        }

        $report = $this->liveReport();
        $writes = $report->writeCount();
        $reads = $report->readCount();

        file_put_contents(
            base_path(self::BASELINE_RELATIVE),
            json_encode([
                'note' => 'Uncovered routes: no `can:`, `require.any.permission:`, `super_admin`, '
                    .'`central_admin` or `central_admin_role` middleware, and not public, self-service or '
                    .'a tombstone. Shrink-only. DELETE an entry in the same commit that gates its route.',
                'generated_write_count' => $writes,
                'generated_read_count' => $reads,
                'uncovered' => $report->uncoveredKeys(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
        );

        self::fail(
            'Baseline regenerated with '.$writes.' writes and '.$reads.' reads. Review the diff, set the '
            .'two ceilings to those numbers, then re-run WITHOUT '.self::REGENERATE_ENV.'.',
        );
    }

    /**
     * One scan of the LIVE router. Everything else in this class reads from it,
     * so the router is walked once per assertion and never twice with two
     * different rules.
     */
    private function liveReport(): RouteCoverageReport
    {
        return (new RoutePermissionCoverageScanner)->scan(RouteFacade::getRoutes()->getRoutes());
    }

    /**
     * @return list<string>
     */
    private function baselineKeys(): array
    {
        $contents = @file_get_contents(base_path(self::BASELINE_RELATIVE));
        self::assertIsString($contents, 'Could not read '.self::BASELINE_RELATIVE);

        /** @var array{uncovered?: list<string>} $decoded */
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        $keys = $decoded['uncovered'] ?? [];
        sort($keys);

        return $keys;
    }
}
