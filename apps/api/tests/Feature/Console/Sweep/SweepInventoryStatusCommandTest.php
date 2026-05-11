<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Sweep;

use App\Application\Sweep\Scanners\ClusterResolver;
use App\Application\Sweep\Scanners\PhpPresentationExistsScanner;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Feature test for `php artisan sweep:inventory:status` (read-only).
 *
 * Master plan reference:
 *   - Section 4 — workflow state machine (status reports the YAML state).
 *   - Section 4 lines 319-326 — `progress` block schema (drives default mode).
 *
 * Three modes:
 *   - default (no flags): print summary from the YAML's `progress` block.
 *     Always exit 0.
 *   - --unmapped: enumerate callsites in the api.unmapped fallback cluster.
 *     Always exit 0 (informational).
 *   - --drift: re-run scanners against the live tree (or a --scan-root override),
 *     compare to the YAML, print drift counts. Exit non-zero if
 *     `yaml_says_fixed_code_unsafe > 0` (security regression).
 *
 * The command MUST NOT mutate the YAML — it calls InventoryService::load() only.
 */
class SweepInventoryStatusCommandTest extends TestCase
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

    public function test_status_default_mode_prints_summary_and_exits_zero(): void
    {
        $exit = Artisan::call('sweep:inventory:status', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
        ]);

        $this->assertSame(0, $exit, 'default status mode must exit 0.');

        $output = Artisan::output();
        $this->assertStringContainsString('Tenant-isolation sweep status', $output);
        $this->assertStringContainsString('By status', $output);
        $this->assertStringContainsString('pending:', $output);
        // The seed has 2 callsites, both pending.
        $this->assertMatchesRegularExpression('/pending:\s+2/', $output);
    }

    public function test_status_default_mode_prints_yaml_sha256_and_branch_metadata(): void
    {
        $exit = Artisan::call('sweep:inventory:status', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
        ]);
        $this->assertSame(0, $exit);

        $output = Artisan::output();
        $document = $this->readInventory();
        /** @var array{branch: string, yaml_sha256: string, generated_at: string, generated_by: string, schema_version: string} $metadata */
        $metadata = $document['metadata'];

        $shortHash = substr($metadata['yaml_sha256'], 0, 12);

        $this->assertStringContainsString('YAML SHA-256:', $output);
        $this->assertStringContainsString($shortHash, $output);
        $this->assertStringContainsString('Branch:', $output);
        $this->assertStringContainsString($metadata['branch'], $output);
        $this->assertStringContainsString('Generated:', $output);
        $this->assertStringContainsString($metadata['generated_at'], $output);
        $this->assertStringContainsString($metadata['generated_by'], $output);
        $this->assertStringContainsString('Schema:', $output);
        $this->assertStringContainsString('v'.$metadata['schema_version'], $output);
    }

    public function test_status_default_mode_includes_drift_block_from_yaml_progress(): void
    {
        // Re-seed the YAML's progress.drift block to non-zero values so we
        // can verify the status command surfaces them verbatim.
        $this->reseedInventory(function (array $doc): array {
            /** @var array<string, mixed> $progress */
            $progress = $doc['progress'];
            $progress['drift'] = [
                'yaml_says_fixed_code_unsafe' => 3,
                'code_safe_yaml_pending' => 5,
            ];
            $doc['progress'] = $progress;

            return $doc;
        });

        $exit = Artisan::call('sweep:inventory:status', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
        ]);
        $this->assertSame(0, $exit);

        $output = Artisan::output();
        $this->assertStringContainsString('Drift (last computed)', $output);
        $this->assertMatchesRegularExpression('/yaml_says_fixed_code_unsafe:\s+3/', $output);
        $this->assertMatchesRegularExpression('/code_safe_yaml_pending:\s+5/', $output);
    }

    public function test_status_unmapped_mode_lists_no_callsites_when_seed_has_none(): void
    {
        $exit = Artisan::call('sweep:inventory:status', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--unmapped' => true,
        ]);

        $this->assertSame(0, $exit, '--unmapped mode is informational; must always exit 0.');

        $output = Artisan::output();
        // The seed has no api.unmapped callsites; output should clearly say so.
        $this->assertStringContainsString('No callsites in api.unmapped cluster', $output);
    }

    public function test_status_unmapped_mode_enumerates_callsites_in_api_unmapped_cluster(): void
    {
        $this->reseedInventory(function (array $doc): array {
            /** @var list<array<string, mixed>> $clusters */
            $clusters = $doc['clusters'];
            // Append the synthetic api.unmapped cluster the resolver falls back to.
            $clusters[] = [
                'id' => 'api.unmapped',
                'display_name' => 'Unmapped',
                'surface' => 'api',
                'owner' => null,
                'required_owner' => null,
                'status' => 'pending',
                'blocked_by' => [],
                'blocked_by_external' => null,
                'blocked_reason' => null,
                'blocks' => [],
                'is_reference' => false,
                'expected_callsite_count' => null,
                'test_file' => 'apps/api/tests/Feature/Unmapped/UnmappedTenantIsolationTest.php',
                'review_gate' => [
                    'required' => true,
                    'reviewer_must_differ_from_owner' => true,
                    'review_file' => 'docs/superpowers/reviews/2026-05-02-unmapped-cluster-review.md',
                    'accepted_verdicts' => ['APPROVE'],
                    'verify_review_commit_linkage' => false,
                ],
            ];
            $doc['clusters'] = $clusters;

            /** @var list<array<string, mixed>> $callsites */
            $callsites = $doc['callsites'];
            $callsites[] = [
                'id' => 'api.unmapped.001',
                'stable_key' => 'sha256:'.str_repeat('c', 64),
                'surface' => 'api',
                'cluster_id' => 'api.unmapped',
                'scanner' => 'php_presentation_exists',
                'file' => 'apps/api/app/Modules/Mystery/Presentation/Requests/Mystery.php',
                'line' => 17,
                'symbol' => 'App\\Modules\\Mystery\\Presentation\\Requests\\Mystery::rules',
                'pattern_type' => 'bare_exists_validator',
                'resource' => 'mystery_table',
                'expected_scope' => 'tenant_and_company',
                'expected_fix' => 'Reclassify the Mystery module under a real cluster.',
                'severity' => 'high',
                'fiscal_path' => false,
                'cross_module' => false,
                'stale_state' => 'active',
                'status' => 'pending',
                'owner' => null,
                'claimed_at' => null,
                'review' => [
                    'reviewer' => null,
                    'verdict' => null,
                    'reviewed_at' => null,
                    'review_file' => null,
                    'review_commit' => null,
                ],
                'fix_commit' => null,
                'regression_test' => null,
                'blocked_reason' => null,
                'history' => [
                    [
                        'at' => '2026-05-03T00:00:00Z',
                        'actor' => 'generator',
                        'action' => 'generate',
                        'command' => 'sweep:inventory:generate',
                        'previous_yaml_sha256' => null,
                        'new_yaml_sha256' => null,
                        'target_ids' => ['api.unmapped.001'],
                        'from_status' => null,
                        'to_status' => 'pending',
                        'commit' => null,
                        'test' => null,
                        'review_file' => null,
                        'review_commit' => null,
                        'note' => 'initial detection (test seed)',
                    ],
                ],
            ];
            $doc['callsites'] = $callsites;

            // Update progress.total_callsites + by_status.pending.
            /** @var array<string, mixed> $progress */
            $progress = $doc['progress'];
            $progress['total_callsites'] = 3;
            $progress['total_clusters'] = 3;
            /** @var array<string, int> $byStatus */
            $byStatus = $progress['by_status'];
            $byStatus['pending'] = 3;
            $progress['by_status'] = $byStatus;
            $doc['progress'] = $progress;

            return $doc;
        });

        $exit = Artisan::call('sweep:inventory:status', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--unmapped' => true,
        ]);

        $this->assertSame(0, $exit);

        $output = Artisan::output();
        $this->assertStringContainsString('api.unmapped.001', $output);
        $this->assertStringContainsString('apps/api/app/Modules/Mystery/Presentation/Requests/Mystery.php', $output);
        $this->assertStringContainsString('mystery_table', $output);
    }

    public function test_status_drift_mode_runs_scanners_and_prints_drift_counts(): void
    {
        // Point --scan-root at an empty fixture directory so scanners produce
        // zero rows. The seed's two pending callsites have status='pending'
        // and won't be in the scanner output → code_safe_yaml_pending = 2.
        $scanRoot = $this->tempDir.'/empty-scan-root';
        mkdir($scanRoot, 0755, true);
        $manualStub = $this->tempDir.'/empty-manual.yml';
        file_put_contents($manualStub, "[]\n");

        $exit = Artisan::call('sweep:inventory:status', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--scan-root' => $scanRoot,
            '--repo-root' => $this->tempDir,
            '--manual-stub' => $manualStub,
            '--drift' => true,
        ]);

        $this->assertSame(0, $exit, '--drift exits 0 when no fixed callsites are unsafe.');

        $output = Artisan::output();
        $this->assertStringContainsString('yaml_says_fixed_code_unsafe', $output);
        $this->assertStringContainsString('code_safe_yaml_pending', $output);
        $this->assertStringContainsString('unmapped_in_scanner_output', $output);
        // Both seed callsites are pending and absent from the empty-scan-root
        // → code_safe_yaml_pending = 2.
        $this->assertMatchesRegularExpression('/code_safe_yaml_pending:\s+2/', $output);
        $this->assertMatchesRegularExpression('/yaml_says_fixed_code_unsafe:\s+0/', $output);
    }

    public function test_status_drift_mode_exits_non_zero_when_yaml_says_fixed_code_unsafe(): void
    {
        // Create a fixture file that the PhpPresentationExistsScanner WILL
        // detect (a Presentation/Requests/*.php with bare exists:<table>),
        // then re-seed a YAML callsite with status=fixed AND the matching
        // stable_key the scanner will emit for that fixture row.
        $scanRoot = $this->tempDir.'/scan-fixture';
        $modulesDir = $scanRoot.'/Modules/StubMod/Presentation/Requests';
        mkdir($modulesDir, 0755, true);
        $stubPhp = $modulesDir.'/StubReq.php';
        file_put_contents(
            $stubPhp,
            <<<'PHP'
<?php

namespace App\Modules\StubMod\Presentation\Requests;

class StubReq
{
    public function rules(): array
    {
        return [
            'payment_method_id' => ['required', 'exists:payment_methods,id'],
        ];
    }
}
PHP
        );
        $manualStub = $this->tempDir.'/empty-manual.yml';
        file_put_contents($manualStub, "[]\n");

        // Pre-compute the stable_key the scanner will emit for this fixture.
        // Use the same (scanRoot, repoRoot) pair the artisan command will use
        // (we'll pass --repo-root=$this->tempDir below). Mismatching repoRoots
        // would change the relativized path → different stable_key → no drift.
        $resolver = new ClusterResolver(
            ClusterResolver::defaultModuleToClusterMap(),
            ClusterResolver::DEFAULT_FALLBACK_CLUSTER_ID,
        );
        $scanner = new PhpPresentationExistsScanner(
            $scanRoot,
            $this->tempDir,
            $resolver,
        );
        $rows = $scanner->scan();
        $this->assertCount(1, $rows, 'Scanner fixture must yield exactly one row.');
        $row = $rows[0];

        // Re-seed: replace api.treasury.001's stable_key + file with the
        // scanner's emitted values, and mark status=fixed. The YAML now
        // claims this callsite is fixed, but the scanner still detects it
        // → drift surfaces it as yaml_says_fixed_code_unsafe.
        $this->reseedInventory(function (array $doc) use ($row): array {
            /** @var list<array<string, mixed>> $callsites */
            $callsites = $doc['callsites'];
            foreach ($callsites as $idx => $cs) {
                if (($cs['id'] ?? null) === 'api.treasury.001') {
                    $cs['stable_key'] = $row->stableKey;
                    $cs['file'] = $row->relativePath;
                    $cs['line'] = $row->line;
                    $cs['symbol'] = $row->symbol;
                    $cs['status'] = 'fixed';
                    $cs['owner'] = 'claude';
                    $cs['claimed_at'] = '2026-05-03T00:00:00Z';
                    $cs['fix_commit'] = '0123456789abcdef0123456789abcdef01234567';
                    $cs['regression_test'] = 'tests/Feature/StubMod/StubModTest.php::test_baseline';
                    $callsites[$idx] = $cs;
                }
            }
            $doc['callsites'] = $callsites;

            return $doc;
        });

        $exit = Artisan::call('sweep:inventory:status', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--scan-root' => $scanRoot,
            '--repo-root' => $this->tempDir,
            '--manual-stub' => $manualStub,
            '--drift' => true,
        ]);

        $output = Artisan::output();
        $this->assertNotSame(0, $exit, 'Drift MUST exit non-zero when a fixed callsite is still detectable as unsafe. Output was: '.$output);
        $this->assertMatchesRegularExpression('/yaml_says_fixed_code_unsafe:\s+1/', $output);
    }

    public function test_status_unmapped_flag_does_not_mutate_yaml(): void
    {
        $before = file_get_contents($this->inventoryPath);

        Artisan::call('sweep:inventory:status', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--unmapped' => true,
        ]);

        $after = file_get_contents($this->inventoryPath);
        $this->assertSame($before, $after, 'sweep:inventory:status MUST be read-only — YAML must be byte-identical.');
    }
}
