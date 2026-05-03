<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Sweep\Scanners;

use App\Application\Sweep\Scanners\CallsiteRow;
use App\Application\Sweep\Scanners\ClusterResolver;
use App\Application\Sweep\Scanners\PhpPresentationExistsScanner;
use App\Application\Sweep\Visitors\ExistsRuleVisitor;
use Tests\TestCase;

/**
 * Verifies PhpPresentationExistsScanner over fixture files. The scanner
 * delegates to {@see ExistsRuleVisitor}
 * (the AST logic that powers Architecture Gate A) and wraps each violation
 * as a {@see CallsiteRow} with a content-addressed stable key.
 *
 * Fixtures sit under tests/Unit/Application/Sweep/Scanners/Fixtures/exists/.
 * The visitor's deeper behavioural coverage stays in
 * tests/Unit/Shared/Architecture/ExistsRuleVisitorTest — this file pins the
 * scanner-level wiring (path filter, cluster resolution, row shape, key
 * stability).
 */
class PhpPresentationExistsScannerTest extends TestCase
{
    private string $fixtureRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureRoot = __DIR__.'/Fixtures/exists';
    }

    public function test_scan_returns_callsite_rows_for_bare_exists_in_presentation_tier(): void
    {
        $scanner = $this->scanner(['Treasury' => 'api.treasury'], $this->fixtureRoot);

        $rows = $scanner->scan();

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertInstanceOf(CallsiteRow::class, $row);
            $this->assertSame('php_presentation_exists', $row->scanner);
            $this->assertSame('api', $row->surface);
        }
    }

    public function test_scan_emits_one_row_per_violation_with_stable_keys(): void
    {
        $scanner = $this->scanner(['Treasury' => 'api.treasury'], $this->fixtureRoot);

        $rows = $scanner->scan();

        // The fixture file at Fixtures/exists/Modules/Treasury/Presentation/Requests/BareExistsRequest.php
        // has exactly two bare-exists rules and zero scoped rules. The
        // negative fixture (ScopedExistsRequest.php) must not contribute.
        $this->assertCount(2, $rows);
        $stableKeys = array_map(fn (CallsiteRow $r): string => $r->stableKey, $rows);
        $this->assertSame($stableKeys, array_unique($stableKeys), 'Stable keys must be unique per violation.');
        foreach ($stableKeys as $key) {
            $this->assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/', $key);
        }
    }

    public function test_scan_skips_methods_annotated_cross_tenant_route(): void
    {
        $scanner = $this->scanner(['Treasury' => 'api.treasury'], $this->fixtureRoot);

        $rows = $scanner->scan();

        // CrossTenantRouteRequest.php has bare exists rules but the method is
        // annotated #[CrossTenantRoute] so the visitor skips them. We expect
        // those rules NOT to surface as scanner output.
        foreach ($rows as $row) {
            $this->assertStringNotContainsString(
                'CrossTenantRouteRequest',
                $row->relativePath,
                'CrossTenantRoute-annotated methods must be skipped by the scanner.',
            );
        }
    }

    public function test_stable_key_is_invariant_across_scanner_invocations(): void
    {
        $scanner = $this->scanner(['Treasury' => 'api.treasury'], $this->fixtureRoot);

        $first = $scanner->scan();
        $second = $scanner->scan();

        $firstKeys = array_map(fn (CallsiteRow $r): string => $r->stableKey, $first);
        $secondKeys = array_map(fn (CallsiteRow $r): string => $r->stableKey, $second);

        sort($firstKeys);
        sort($secondKeys);
        $this->assertSame($firstKeys, $secondKeys, 'Stable keys must be deterministic across scans.');
    }

    public function test_cluster_id_resolves_from_module_path(): void
    {
        $scanner = $this->scanner(['Treasury' => 'api.treasury'], $this->fixtureRoot);

        $rows = $scanner->scan();

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame('api.treasury', $row->clusterId);
        }
    }

    public function test_unmapped_module_falls_back_to_configured_cluster(): void
    {
        // Empty map → every row gets the fallback.
        $scanner = $this->scanner([], $this->fixtureRoot, fallback: 'api.identity-company');

        $rows = $scanner->scan();

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame('api.identity-company', $row->clusterId);
        }
    }

    public function test_scanner_name_is_php_presentation_exists(): void
    {
        $scanner = $this->scanner([], $this->fixtureRoot);

        $this->assertSame('php_presentation_exists', $scanner->name());
    }

    /**
     * @param  array<string, string>  $moduleMap
     */
    private function scanner(array $moduleMap, string $base, string $fallback = 'api.identity-company'): PhpPresentationExistsScanner
    {
        return new PhpPresentationExistsScanner(
            scanRoot: $base,
            repoRoot: $base, // for fixture paths, repo root == scan root
            clusterResolver: new ClusterResolver($moduleMap, $fallback),
            guardedTables: ['payment_methods', 'partners'],
        );
    }
}
