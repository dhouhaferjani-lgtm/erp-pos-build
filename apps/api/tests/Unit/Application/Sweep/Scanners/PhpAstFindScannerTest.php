<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Sweep\Scanners;

use App\Application\Sweep\Scanners\CallsiteRow;
use App\Application\Sweep\Scanners\ClusterResolver;
use App\Application\Sweep\Scanners\PhpAstFindScanner;
use App\Application\Sweep\Visitors\FindCallVisitor;
use Tests\TestCase;

/**
 * Verifies PhpAstFindScanner over fixture files. Same wiring contract as
 * PhpPresentationExistsScannerTest: the AST traversal lives in the
 * {@see FindCallVisitor}; the scanner adds
 * stable-key generation, cluster resolution, and CallsiteRow shaping.
 *
 * Fixtures sit under Fixtures/find/ — three flavors covering the
 * Application-tier file filter (Application/, Domain/Services/,
 * Presentation/Controllers/).
 */
class PhpAstFindScannerTest extends TestCase
{
    private string $fixtureRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureRoot = __DIR__.'/Fixtures/find';
    }

    public function test_scan_returns_callsite_rows_for_bare_find_in_application_tier(): void
    {
        $scanner = $this->scanner(['Document' => 'api.document'], $this->fixtureRoot);

        $rows = $scanner->scan();

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertInstanceOf(CallsiteRow::class, $row);
            $this->assertSame('php_ast_find', $row->scanner);
            $this->assertSame('api', $row->surface);
            $this->assertSame('api.document', $row->clusterId);
        }
    }

    public function test_scan_emits_two_rows_for_bare_find_pair(): void
    {
        $scanner = $this->scanner(['Document' => 'api.document'], $this->fixtureRoot);

        $rows = $scanner->scan();

        // BareFindService.php has two unscoped calls (find + findOrFail).
        // ScopedFindService.php has zero. CrossTenantController.php is
        // skipped via the attribute.
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame('Document', $row->resource);
        }
    }

    public function test_scan_skips_methods_annotated_cross_tenant_route(): void
    {
        $scanner = $this->scanner(['Document' => 'api.document'], $this->fixtureRoot);

        $rows = $scanner->scan();

        foreach ($rows as $row) {
            $this->assertStringNotContainsString(
                'CrossTenantController',
                $row->relativePath,
                'CrossTenantRoute-annotated methods must be skipped by the scanner.',
            );
        }
    }

    public function test_stable_key_is_invariant_across_scanner_invocations(): void
    {
        $scanner = $this->scanner(['Document' => 'api.document'], $this->fixtureRoot);

        $first = $scanner->scan();
        $second = $scanner->scan();

        $firstKeys = array_map(fn (CallsiteRow $r): string => $r->stableKey, $first);
        $secondKeys = array_map(fn (CallsiteRow $r): string => $r->stableKey, $second);

        sort($firstKeys);
        sort($secondKeys);
        $this->assertSame($firstKeys, $secondKeys);
    }

    public function test_unmapped_module_falls_back_to_configured_cluster(): void
    {
        $scanner = $this->scanner([], $this->fixtureRoot, fallback: 'api.identity-company');

        $rows = $scanner->scan();

        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame('api.identity-company', $row->clusterId);
        }
    }

    public function test_scanner_name_is_php_ast_find(): void
    {
        $scanner = $this->scanner([], $this->fixtureRoot);

        $this->assertSame('php_ast_find', $scanner->name());
    }

    /**
     * @param  array<string, string>  $moduleMap
     */
    private function scanner(array $moduleMap, string $base, string $fallback = 'api.identity-company'): PhpAstFindScanner
    {
        return new PhpAstFindScanner(
            scanRoot: $base,
            repoRoot: $base,
            clusterResolver: new ClusterResolver($moduleMap, $fallback),
            guardedModels: ['Document'],
        );
    }
}
