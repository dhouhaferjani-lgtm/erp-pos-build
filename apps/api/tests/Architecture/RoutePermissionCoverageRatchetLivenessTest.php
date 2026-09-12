<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use PHPUnit\Framework\Attributes\Test;
use Tests\Architecture\Support\LivenessRouteFixture;
use Tests\Architecture\Support\LivenessRouteServiceProvider;
use Tests\Architecture\Support\RouteCoverage;
use Tests\Architecture\Support\RouteCoverageClassifier;
use Tests\Architecture\Support\RouteCoverageRatchetChecker;
use Tests\Architecture\Support\RoutePermissionCoverageScanner;
use Tests\Architecture\Support\SelfServiceRouteRegistry;
use Tests\TestCase;

/**
 * Detector liveness for RoutePermissionCoverageRatchetTest
 * (docs/conventions/08-DETECTOR-LIVENESS.md), in two halves.
 *
 * HALF ONE — the detector SEES the shapes it claims to see. The fixture's
 * throw-away routes are registered by LivenessRouteServiceProvider BEFORE the
 * router boots (spec 4.4.3 :1525), and each must receive the classification the
 * fixture declares. A refactor that breaks the classifier fails here instead of
 * quietly reporting zero — the C6 failure mode the convention was written after.
 *
 * HALF TWO — the baseline-backed GUARD FAILS on a planted violation. Convention
 * 08 :48-56 requires three cases for a shrink-only ratchet, and none of them is
 * satisfied by "a fixture route classifies uncovered":
 *
 *   (a) NEW VIOLATION      — an uncovered route absent from the baseline fails,
 *                            with the remediation message;
 *   (b) STALE ENTRY        — a baseline entry whose route is now gated fails;
 *   (c) MATCHED GROWTH     — planting the violation AND its own baseline entry
 *                            still leaves the ratchet defeated, which is exactly
 *                            why direction (c) of the ratchet compares the
 *                            baseline against a CI-pinned blob. This case pins
 *                            the LOOPHOLE so the pin cannot be quietly dropped:
 *                            it asserts the checker reports CLEAN, and its
 *                            failure message names ROUTE_COVERAGE_BASELINE_PROTECTED_BLOB
 *                            and wave 0b-11 as the thing that closes it.
 *
 * Half two drives RoutePermissionCoverageScanner with INJECTED routes, so no
 * case depends on the live route table and none of them can be made green by
 * editing an application route.
 */
final class RoutePermissionCoverageRatchetLivenessTest extends TestCase
{
    public function createApplication(): Application
    {
        /** @var Application $app */
        $app = require Application::inferBasePath().'/bootstrap/app.php';

        // BEFORE bootstrap(): the provider's boot() then runs while providers
        // are booting, so the fixture routes exist by the time anything reads
        // the router. This is the whole point of the provider (gate r1 B-2 (1)).
        $app->register(LivenessRouteServiceProvider::class);

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    /**
     * The EXACT five-way classification, straight from the classifier (gate r2
     * M-4). Rev 2 reduced every non-`uncovered` outcome to the single word
     * "covered", so a `gated` fixture route misclassified as `public`,
     * `self_service` or `tombstone` passed — which is precisely the drift the
     * fixture's `expected_classification` column exists to catch, and it is why
     * `countsByClassification()` was promised and never written.
     */
    #[Test]
    public function the_classifier_reports_every_fixture_route_with_its_exact_declared_classification(): void
    {
        $classifier = new RouteCoverageClassifier;

        foreach (LivenessRouteFixture::records() as $record) {
            $key = $record['method'].' '.$record['uri'];
            $expected = RouteCoverage::from($record['expected_classification']);
            $observed = $classifier->classify($this->findRoute($key));

            self::assertSame(
                $expected,
                $observed,
                $key.' classified as '.$observed->value.' but the liveness fixture declares '
                .$expected->value.'. Reason the fixture gives: '.$record['why'],
            );
        }
    }

    /**
     * ...and the SCAN of the in-universe fixture routes reports that same
     * distribution, so the reduction the report performs cannot lose a
     * classification the classifier got right.
     */
    #[Test]
    public function scanning_the_fixture_reports_the_exact_five_way_distribution(): void
    {
        $expected = [
            RouteCoverage::Gated->value => 0,
            RouteCoverage::Open->value => 0,
            RouteCoverage::SelfService->value => 0,
            RouteCoverage::Tombstone->value => 0,
            RouteCoverage::Uncovered->value => 0,
        ];

        $routes = [];
        foreach (LivenessRouteFixture::records() as $record) {
            $route = $this->findRoute($record['method'].' '.$record['uri']);
            $routes[] = $route;

            if ($record['in_api_universe']) {
                $expected[$record['expected_classification']]++;
            }
        }

        self::assertSame(
            $expected,
            (new RoutePermissionCoverageScanner)->scan($routes)->countsByClassification(),
            'The scan\'s five-way distribution does not match the classifications the liveness fixture '
            .'declares for its in-universe records. Either the classifier drifted or the scanner is no '
            .'longer counting what it classifies.',
        );
    }

    /**
     * THE UNIVERSE RULE, proven both ways (gate r2 B-1). The scanner's scope is
     * `apps/api/routes/api.php` plus the module routes.php files — every one of
     * them under `api/` — and NOT the 38 framework/`web`/Horizon/Telescope/
     * Scramble/Debugbar routes the live router also carries. A scanner that
     * forgets the filter reports 353 uncovered routes instead of 315 and makes
     * this wave's own baseline command unreachable.
     */
    #[Test]
    public function a_planted_non_api_route_is_outside_the_scanned_universe(): void
    {
        $outside = $this->plantedRoute('POST', 'internal/__tamper__/ungated-write', ['web']);
        $inside = $this->plantedRoute('POST', 'api/v1/__tamper__/ungated-write', ['api', 'auth:sanctum']);
        $scanner = new RoutePermissionCoverageScanner;

        // (a) the classifier is NOT what excludes it: on its own merits the
        //     planted route is uncovered, exactly like its in-universe twin.
        self::assertSame(RouteCoverage::Uncovered, (new RouteCoverageClassifier)->classify($outside));

        // (b) the SCANNER excludes it, before classification.
        self::assertFalse($scanner->isInApiUniverse($outside), 'isInApiUniverse() accepted a non-`api/` URI.');
        self::assertTrue($scanner->isInApiUniverse($inside));

        $report = $scanner->scan([$outside]);
        self::assertSame([], $report->uncoveredKeys(), 'A non-API route entered the coverage baseline.');
        self::assertSame(0, $report->writeCount());
        self::assertSame(0, $report->readCount());
        self::assertSame(
            [
                RouteCoverage::Gated->value => 0,
                RouteCoverage::Open->value => 0,
                RouteCoverage::SelfService->value => 0,
                RouteCoverage::Tombstone->value => 0,
                RouteCoverage::Uncovered->value => 0,
            ],
            $report->countsByClassification(),
            'A non-API route was counted in a classification bucket. It must be skipped BEFORE classification.',
        );

        // (c) the positive control: the same shape under `api/` IS counted, so
        //     the filter cannot be satisfied by a scanner that counts nothing.
        self::assertSame(
            ['POST api/v1/__tamper__/ungated-write'],
            $scanner->scan([$inside])->uncoveredKeys(),
        );
    }

    #[Test]
    public function a_new_uncovered_route_absent_from_the_baseline_fails_the_guard(): void
    {
        $planted = $this->plantedRoute('POST', 'api/v1/__tamper__/ungated-write', ['api', 'auth:sanctum']);

        $result = (new RouteCoverageRatchetChecker)->check(
            (new RoutePermissionCoverageScanner)->scan([$planted]),
            [],
        );

        self::assertFalse($result->isClean(), 'The ratchet did not fire on a NEW ungated write. It is neutered.');
        self::assertSame(['POST api/v1/__tamper__/ungated-write'], $result->newViolations());
        self::assertStringContainsString('NEW ROUTES CANNOT BE ADDED TO THE COVERAGE BASELINE.', $result->message());
    }

    #[Test]
    public function a_baseline_entry_whose_route_is_now_gated_fails_the_guard(): void
    {
        $gated = $this->plantedRoute('POST', 'api/v1/__tamper__/now-gated', ['api', 'auth:sanctum', 'can:roles.view']);

        $result = (new RouteCoverageRatchetChecker)->check(
            (new RoutePermissionCoverageScanner)->scan([$gated]),
            ['POST api/v1/__tamper__/now-gated'],
        );

        self::assertFalse($result->isClean(), 'The ratchet did not fire on a STALE baseline entry, so a fix can be forgotten.');
        self::assertSame(['POST api/v1/__tamper__/now-gated'], $result->staleEntries());
        self::assertStringContainsString('DELETE them from the baseline in the same commit that gated the route', $result->message());
    }

    #[Test]
    public function matched_growth_defeats_the_checker_which_is_what_the_protected_blob_exists_for(): void
    {
        $planted = $this->plantedRoute('POST', 'api/v1/__tamper__/ungated-with-its-own-entry', ['api', 'auth:sanctum']);

        $result = (new RouteCoverageRatchetChecker)->check(
            (new RoutePermissionCoverageScanner)->scan([$planted]),
            ['POST api/v1/__tamper__/ungated-with-its-own-entry'],
        );

        self::assertTrue(
            $result->isClean(),
            'The checker rejected matched growth on its own. If that is now TRUE, the anti-growth blob may be '
            .'redundant — say so in the handback rather than deleting either mechanism silently.',
        );

        // The point of the case: growth + its own baseline entry passes (a) and
        // (b), which is precisely the hole ROUTE_COVERAGE_BASELINE_PROTECTED_BLOB
        // closes. Wave 0b-11 registers the variable and replaces the ratchet's
        // markTestSkipped with the fail-closed-on-unset form. This assertion is
        // the executable record of that debt: if 0b-11 lands and this case is
        // deleted without the blob check being live, the loophole reopens
        // silently.
        self::assertTrue(
            defined('PHP_VERSION') && str_contains(
                (string) file_get_contents(base_path('tests/Architecture/RoutePermissionCoverageRatchetTest.php')),
                'ROUTE_COVERAGE_BASELINE_PROTECTED_BLOB',
            ),
            'RoutePermissionCoverageRatchetTest no longer references ROUTE_COVERAGE_BASELINE_PROTECTED_BLOB. '
            .'Matched growth is only caught by that pin; removing it reopens the hole this case documents.',
        );
    }

    /**
     * @param  list<string>  $middleware
     */
    private function plantedRoute(string $method, string $uri, array $middleware): Route
    {
        $route = new Route([$method], $uri, static fn (): string => 'ok');
        $route->middleware($middleware);

        return $route;
    }

    private function findRoute(string $key): Route
    {
        /** @var Route $route */
        foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
            if (SelfServiceRouteRegistry::routeKey($route) === $key) {
                return $route;
            }
        }

        self::fail('Liveness fixture route not registered: '.$key.'. LivenessRouteServiceProvider did not run '
            .'before the router booted — check the createApplication() override.');
    }
}
