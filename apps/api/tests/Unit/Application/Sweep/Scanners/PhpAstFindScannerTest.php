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

    public function test_scan_emits_rows_for_bare_find_pair(): void
    {
        $scanner = $this->scanner(['Document' => 'api.document'], $this->fixtureRoot);

        $rows = $scanner->scan();

        // Positive fixtures:
        //   - BareFindService.php — 2 unscoped calls (find + findOrFail)
        //   - MultipleFindMethodsController.php — 2 findOrFail calls in different methods
        // ScopedFindService.php contributes zero. CrossTenantController.php is skipped.
        $this->assertCount(4, $rows);
        foreach ($rows as $row) {
            $this->assertSame('Document', $row->resource);
        }
    }

    public function test_find_or_fail_in_two_methods_of_same_class_yield_distinct_stable_keys(): void
    {
        // Codex Phase 1 review #1: the scanner used to set
        // `symbol = "<ClassFqn>::find"` (the called Eloquent method),
        // collapsing all findOrFail() calls in the same class to one
        // stable_key. The symbol now carries the enclosing method, so
        // ::show vs ::edit produce distinct keys.
        $scanner = $this->scanner(['Document' => 'api.document'], $this->fixtureRoot);

        $rows = array_values(array_filter(
            $scanner->scan(),
            static fn (CallsiteRow $r): bool => str_contains($r->relativePath, 'MultipleFindMethodsController'),
        ));

        $this->assertCount(2, $rows);
        $this->assertNotSame(
            $rows[0]->stableKey,
            $rows[1]->stableKey,
            'findOrFail() in different methods of the same class must have distinct stable_keys.',
        );
        $symbols = array_map(static fn (CallsiteRow $r): string => $r->symbol, $rows);
        sort($symbols);
        $this->assertCount(2, array_unique($symbols), 'Symbols must reflect the enclosing method, not the Eloquent method called.');
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

    public function test_renaming_enclosing_method_changes_stable_key(): void
    {
        // Codex Phase 1 review #1 acceptance criterion: a method rename in
        // the enclosing class MUST change the stable_key. Proof that rename
        // semantics flow through the symbol field.
        $beforeDir = $this->materializeFixture(<<<'PHP'
<?php
namespace Tests\Fixtures\Sweep\RenameProbe\Modules\Document\Application;
use App\Modules\Document\Domain\Document;
class RenameProbeService {
    public function fetch(int $id): mixed { return Document::findOrFail($id); }
}
PHP);
        $afterDir = $this->materializeFixture(<<<'PHP'
<?php
namespace Tests\Fixtures\Sweep\RenameProbe\Modules\Document\Application;
use App\Modules\Document\Domain\Document;
class RenameProbeService {
    public function fetchRenamed(int $id): mixed { return Document::findOrFail($id); }
}
PHP);

        try {
            $beforeRows = $this->scanner(['Document' => 'api.document'], $beforeDir)->scan();
            $afterRows = $this->scanner(['Document' => 'api.document'], $afterDir)->scan();

            $this->assertCount(1, $beforeRows);
            $this->assertCount(1, $afterRows);
            $this->assertNotSame(
                $beforeRows[0]->stableKey,
                $afterRows[0]->stableKey,
                'Renaming the enclosing method must change the stable_key.',
            );
        } finally {
            $this->cleanupFixture($beforeDir);
            $this->cleanupFixture($afterDir);
        }
    }

    private function materializeFixture(string $code): string
    {
        $base = sys_get_temp_dir().'/sweep-rename-fixture-'.bin2hex(random_bytes(6));
        $appPath = $base.'/Modules/Document/Application';
        mkdir($appPath, 0o755, true);
        file_put_contents($appPath.'/RenameProbeService.php', $code);

        return $base;
    }

    private function cleanupFixture(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iter as $entry) {
            if (! $entry instanceof \SplFileInfo) {
                continue;
            }
            if ($entry->isDir()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($dir);
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
