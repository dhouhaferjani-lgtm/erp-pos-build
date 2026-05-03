<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Feature test for `php artisan sweep:inventory:generate` (master plan
 * Section 5.4). Walks a synthetic source tree under a temp directory,
 * persists callsites to a temp inventory YAML, and verifies merge
 * semantics (preserve / needs_recheck / stale_orphan) per the rename
 * rules in master plan Section 4.
 */
class SweepInventoryGenerateCommandTest extends TestCase
{
    private string $tempRoot;

    private string $inventoryPath;

    private string $schemaPath;

    private string $scanRoot;

    private string $manualStubPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempRoot = sys_get_temp_dir().'/sweep-generate-test-'.bin2hex(random_bytes(8));
        mkdir($this->tempRoot, 0755, true);

        $this->inventoryPath = $this->tempRoot.'/inventory.yml';
        $this->schemaPath = base_path('app/Application/Sweep/InventoryYamlSchema.json');
        $this->scanRoot = $this->tempRoot.'/source';
        $this->manualStubPath = $this->tempRoot.'/manual.yml';

        $this->seedInventory();
        $this->seedManualStub();
        $this->seedSourceTree();
    }

    protected function tearDown(): void
    {
        $this->removeRecursive($this->tempRoot);
        parent::tearDown();
    }

    public function test_first_run_on_empty_inventory_produces_pending_rows(): void
    {
        $exit = Artisan::call('sweep:inventory:generate', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--scan-root' => $this->scanRoot,
            '--manual-stub' => $this->manualStubPath,
        ]);

        $this->assertSame(0, $exit, 'Expected sweep:inventory:generate to succeed; got exit '.$exit);

        $callsites = $this->loadCallsites();
        $this->assertNotEmpty($callsites, 'First run must emit at least one callsite from the synthetic fixture.');
        foreach ($callsites as $callsite) {
            $this->assertSame('pending', $callsite['status']);
            $this->assertNull($callsite['owner']);
            $this->assertSame('active', $callsite['stale_state']);
        }
    }

    public function test_second_run_with_unchanged_code_preserves_existing_rows(): void
    {
        Artisan::call('sweep:inventory:generate', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--scan-root' => $this->scanRoot,
            '--manual-stub' => $this->manualStubPath,
        ]);
        $first = $this->loadCallsites();

        // Mutate a callsite's status to simulate workflow progress; the
        // second run must NOT reset that status because the stable key is
        // still present in the scanner output.
        $this->mutateInventoryDirectly(function (array $doc): array {
            $doc['callsites'][0]['status'] = 'claimed';
            $doc['callsites'][0]['owner'] = 'codex';

            return $doc;
        });

        Artisan::call('sweep:inventory:generate', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--scan-root' => $this->scanRoot,
            '--manual-stub' => $this->manualStubPath,
        ]);
        $second = $this->loadCallsites();

        $this->assertCount(count($first), $second, 'Re-run must not duplicate rows.');
        $this->assertSame('claimed', $second[0]['status'], 'Workflow status must be preserved across regenerations.');
        $this->assertSame('codex', $second[0]['owner']);
    }

    public function test_dry_run_does_not_persist_changes(): void
    {
        $beforeContents = (string) file_get_contents($this->inventoryPath);

        Artisan::call('sweep:inventory:generate', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--scan-root' => $this->scanRoot,
            '--manual-stub' => $this->manualStubPath,
            '--dry-run' => true,
        ]);

        $afterContents = (string) file_get_contents($this->inventoryPath);
        $this->assertSame($beforeContents, $afterContents, 'Dry run must not modify the inventory file.');
    }

    public function test_symbol_rename_marks_old_row_stale_orphan_and_creates_new_pending_row(): void
    {
        Artisan::call('sweep:inventory:generate', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--scan-root' => $this->scanRoot,
            '--manual-stub' => $this->manualStubPath,
        ]);
        $first = $this->loadCallsites();
        $firstCount = count($first);

        // Simulate a symbol rename: keep the file path the same, but
        // change the class name. The visitor's symbol fingerprint
        // changes → old row goes stale_orphan, new row created pending.
        $this->renameClassInFixture(
            $this->scanRoot.'/Modules/Treasury/Presentation/Requests/BareExistsRequest.php',
            from: 'BareExistsRequest',
            to: 'BareExistsRequestRenamed',
        );

        Artisan::call('sweep:inventory:generate', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--scan-root' => $this->scanRoot,
            '--manual-stub' => $this->manualStubPath,
        ]);
        $second = $this->loadCallsites();

        $stale = array_filter($second, fn ($c): bool => $c['stale_state'] === 'stale_orphan');
        $newPending = array_filter($second, fn ($c): bool => $c['stale_state'] === 'active' && $c['status'] === 'pending');

        $this->assertNotEmpty($stale, 'At least one row must be marked stale_orphan after symbol rename.');
        $this->assertGreaterThanOrEqual($firstCount, count($newPending), 'New pending rows must be created for the renamed symbols.');
    }

    /**
     * Seed the temp inventory with an empty (zero callsites) v2 document
     * matching the seed inventory's metadata.
     */
    private function seedInventory(): void
    {
        $document = [
            'metadata' => [
                'schema_version' => '2',
                'spec_version' => '2026-05-02-master',
                'spec_path' => 'docs/superpowers/plans/2026-05-02-tenant-isolation-master-plan.md',
                'branch' => 'feat/tenant-isolation-sweep-execution',
                'generated_at' => '2026-05-03T00:00:00Z',
                'generated_by' => 'claude',
                'schema_sha256' => str_repeat('a', 64),
                'yaml_sha256' => str_repeat('0', 64),
            ],
            'agents' => [
                'claude' => ['role' => 'lead', 'can_claim' => ['api.treasury'], 'can_review' => ['*']],
                'codex' => ['role' => 'codex', 'can_claim' => [], 'can_review' => ['*']],
            ],
            'statuses_enum' => [
                'pending', 'claimed', 'in_progress', 'under_review', 'fixed',
                'blocked', 'deferred', 'needs_recheck', 'stale_orphan',
            ],
            'clusters' => [
                [
                    'id' => 'api.treasury',
                    'display_name' => 'Treasury',
                    'surface' => 'api',
                    'owner' => null,
                    'required_owner' => 'claude',
                    'status' => 'pending',
                    'blocked_by' => [],
                    'blocked_by_external' => null,
                    'blocked_reason' => null,
                    'blocks' => [],
                    'is_reference' => true,
                    'expected_callsite_count' => null,
                    'test_file' => 'apps/api/tests/Feature/Treasury/TreasuryTenantIsolationTest.php',
                    'review_gate' => [
                        'required' => true,
                        'reviewer_must_differ_from_owner' => true,
                        'review_file' => 'docs/superpowers/reviews/2026-05-02-treasury-cluster-codex-review.md',
                        'accepted_verdicts' => ['APPROVE', 'APPROVE-WITH-MINOR-EDITS-APPLIED'],
                        'verify_review_commit_linkage' => true,
                    ],
                ],
            ],
            'callsites' => [],
            'progress' => [
                'total_callsites' => 0,
                'total_clusters' => 1,
                'by_status' => [
                    'pending' => 0, 'claimed' => 0, 'in_progress' => 0, 'under_review' => 0,
                    'fixed' => 0, 'blocked' => 0, 'deferred' => 0, 'needs_recheck' => 0, 'stale_orphan' => 0,
                ],
                'by_surface' => ['api' => ['total' => 0, 'fixed' => 0]],
                'by_owner' => [
                    'claude' => ['claimed' => 0, 'fixed' => 0],
                    'codex' => ['claimed' => 0, 'fixed' => 0],
                    'unassigned' => 0,
                ],
                'drift' => ['yaml_says_fixed_code_unsafe' => 0, 'code_safe_yaml_pending' => 0],
            ],
        ];

        // Compute canonical hash with chain fields zeroed.
        $canonical = $document;
        $canonical['metadata']['yaml_sha256'] = str_repeat('0', 64);
        $hash = hash('sha256', Yaml::dump($canonical, 8, 2, Yaml::DUMP_OBJECT_AS_MAP));
        $document['metadata']['yaml_sha256'] = $hash;
        file_put_contents($this->inventoryPath, Yaml::dump($document, 8, 2, Yaml::DUMP_OBJECT_AS_MAP));
    }

    private function seedManualStub(): void
    {
        file_put_contents($this->manualStubPath, "manual_callsites: []\n");
    }

    private function seedSourceTree(): void
    {
        $treasuryDir = $this->scanRoot.'/Modules/Treasury/Presentation/Requests';
        mkdir($treasuryDir, 0755, true);
        file_put_contents($treasuryDir.'/BareExistsRequest.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace Tests\Synthetic\Treasury\Presentation\Requests;

class BareExistsRequest
{
    public function rules(): array
    {
        return [
            'payment_method_id' => ['required', 'exists:payment_methods,id'],
            'partner_id' => ['required', 'exists:partners,id'],
        ];
    }
}
PHP);

        $documentDir = $this->scanRoot.'/Modules/Document/Application';
        mkdir($documentDir, 0755, true);
        file_put_contents($documentDir.'/BareFindService.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace Tests\Synthetic\Document\Application;

class BareFindService
{
    public function fetch(int $id): mixed { return \Document::find($id); }
}
PHP);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadCallsites(): array
    {
        /** @var array<string, mixed> $parsed */
        $parsed = (array) Yaml::parseFile($this->inventoryPath);
        /** @var list<array<string, mixed>> $callsites */
        $callsites = $parsed['callsites'];

        return $callsites;
    }

    /**
     * Bypass InventoryService to simulate a separate process having
     * legitimately written to the YAML (e.g. claim/start workflow).
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutator
     */
    private function mutateInventoryDirectly(callable $mutator): void
    {
        /** @var array<string, mixed> $document */
        $document = (array) Yaml::parseFile($this->inventoryPath);
        $document = $mutator($document);

        // Recompute hash like InventoryService does.
        $canonical = $document;
        $canonical['metadata']['yaml_sha256'] = str_repeat('0', 64);
        /** @var list<array<string, mixed>> $callsites */
        $callsites = $canonical['callsites'] ?? [];
        foreach ($callsites as $cIdx => $cs) {
            /** @var list<array<string, mixed>> $hist */
            $hist = $cs['history'] ?? [];
            foreach ($hist as $hIdx => $ev) {
                if (array_key_exists('previous_yaml_sha256', $ev)) {
                    $ev['previous_yaml_sha256'] = null;
                }
                if (array_key_exists('new_yaml_sha256', $ev)) {
                    $ev['new_yaml_sha256'] = null;
                }
                $hist[$hIdx] = $ev;
            }
            $cs['history'] = $hist;
            $callsites[$cIdx] = $cs;
        }
        $canonical['callsites'] = $callsites;
        $hash = hash('sha256', Yaml::dump($canonical, 8, 2, Yaml::DUMP_OBJECT_AS_MAP));
        $document['metadata']['yaml_sha256'] = $hash;
        file_put_contents($this->inventoryPath, Yaml::dump($document, 8, 2, Yaml::DUMP_OBJECT_AS_MAP));
    }

    private function renameClassInFixture(string $absolutePath, string $from, string $to): void
    {
        $contents = (string) file_get_contents($absolutePath);
        $renamed = str_replace($from, $to, $contents);
        file_put_contents($absolutePath, $renamed);
    }

    private function removeRecursive(string $path): void
    {
        if (! file_exists($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            unlink($path);

            return;
        }
        $dir = opendir($path);
        if ($dir === false) {
            return;
        }
        while (($entry = readdir($dir)) !== false) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeRecursive($path.'/'.$entry);
        }
        closedir($dir);
        rmdir($path);
    }
}
