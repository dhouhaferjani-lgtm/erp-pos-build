<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Sweep;

use Symfony\Component\Yaml\Yaml;

/**
 * Test trait shared by every `sweep:inventory:*` workflow command test.
 *
 * Each test seeds a temp directory with a v2-shape inventory containing:
 *   - the api.treasury reference cluster (with hard-gate review_gate)
 *   - one non-treasury cluster (api.document) used to exercise the hard gate
 *   - one callsite per cluster, both pending
 *   - canonical metadata.yaml_sha256 computed via the same algorithm
 *     InventoryService uses (chain fields zeroed → serialize → sha256)
 *
 * Tests then call `php artisan sweep:inventory:<verb>` against the temp paths
 * via Artisan::call(). The live inventory file is NEVER touched.
 */
trait SweepInventoryTestSeed
{
    private string $tempDir;

    private string $inventoryPath;

    private string $schemaPath;

    private function bootSweepSeed(): void
    {
        $this->tempDir = sys_get_temp_dir().'/sweep-workflow-test-'.bin2hex(random_bytes(8));
        if (! mkdir($this->tempDir, 0755, true) && ! is_dir($this->tempDir)) {
            $this->fail('Failed to create temp dir at '.$this->tempDir);
        }

        $this->inventoryPath = $this->tempDir.'/inventory.yml';
        $this->schemaPath = base_path('app/Application/Sweep/InventoryYamlSchema.json');

        $this->writeRawInventory($this->seedInventoryArray());
    }

    private function destroySweepSeed(): void
    {
        if (is_file($this->inventoryPath)) {
            unlink($this->inventoryPath);
        }
        if (is_dir($this->tempDir)) {
            $entries = scandir($this->tempDir);
            if ($entries !== false) {
                foreach ($entries as $entry) {
                    if ($entry === '.' || $entry === '..') {
                        continue;
                    }
                    @unlink($this->tempDir.'/'.$entry);
                }
            }
            rmdir($this->tempDir);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function seedInventoryArray(): array
    {
        return [
            'metadata' => [
                'schema_version' => '2',
                'spec_version' => '2026-05-02-master',
                'spec_path' => 'docs/superpowers/plans/2026-05-02-tenant-isolation-master-plan.md',
                'branch' => 'feat/tenant-isolation-sweep-execution',
                'generated_at' => '2026-05-03T00:00:00Z',
                'generated_by' => 'claude',
                'schema_sha256' => str_repeat('e', 64),
                'yaml_sha256' => str_repeat('0', 64),
            ],
            'agents' => [
                'claude' => [
                    'role' => 'lead',
                    'can_claim' => ['api.treasury', 'api.super-admin-context'],
                    'can_review' => ['*'],
                ],
                'codex' => [
                    'role' => 'codex',
                    'can_claim' => ['api.document'],
                    'can_review' => ['*'],
                ],
            ],
            'statuses_enum' => [
                'pending', 'claimed', 'in_progress', 'under_review', 'fixed',
                'blocked', 'deferred', 'needs_recheck', 'stale_orphan',
            ],
            'clusters' => [
                $this->makeTreasuryCluster(),
                $this->makeDocumentCluster(),
            ],
            'callsites' => [
                $this->makeCallsite('api.treasury.001', 'api.treasury', 'a'),
                $this->makeCallsite('api.document.001', 'api.document', 'b'),
            ],
            'progress' => [
                'total_callsites' => 2,
                'total_clusters' => 2,
                'by_status' => [
                    'pending' => 2, 'claimed' => 0, 'in_progress' => 0, 'under_review' => 0,
                    'fixed' => 0, 'blocked' => 0, 'deferred' => 0, 'needs_recheck' => 0, 'stale_orphan' => 0,
                ],
                'by_surface' => ['api' => ['total' => 2, 'fixed' => 0]],
                'by_owner' => [
                    'claude' => ['claimed' => 0, 'fixed' => 0],
                    'codex' => ['claimed' => 0, 'fixed' => 0],
                    'unassigned' => 2,
                ],
                'drift' => [
                    'yaml_says_fixed_code_unsafe' => 0,
                    'code_safe_yaml_pending' => 0,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function makeTreasuryCluster(): array
    {
        return [
            'id' => 'api.treasury',
            'display_name' => 'Treasury',
            'surface' => 'api',
            'owner' => null,
            'required_owner' => 'claude',
            'status' => 'pending',
            'blocked_by' => [],
            'blocked_by_external' => null,
            'blocked_reason' => null,
            'blocks' => ['api.document'],
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
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function makeDocumentCluster(): array
    {
        return [
            'id' => 'api.document',
            'display_name' => 'Document',
            'surface' => 'api',
            'owner' => null,
            'required_owner' => 'codex',
            'status' => 'pending',
            'blocked_by' => ['api.treasury'],
            'blocked_by_external' => null,
            'blocked_reason' => null,
            'blocks' => [],
            'is_reference' => false,
            'expected_callsite_count' => null,
            'test_file' => 'apps/api/tests/Feature/Document/DocumentTenantIsolationTest.php',
            'review_gate' => [
                'required' => true,
                'reviewer_must_differ_from_owner' => true,
                'review_file' => 'docs/superpowers/reviews/2026-05-02-document-cluster-claude-review.md',
                'accepted_verdicts' => ['APPROVE', 'APPROVE-WITH-MINOR-EDITS-APPLIED'],
                'verify_review_commit_linkage' => true,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function makeCallsite(string $callsiteId, string $clusterId, string $hashChar): array
    {
        return [
            'id' => $callsiteId,
            'stable_key' => 'sha256:'.str_repeat($hashChar, 64),
            'surface' => 'api',
            'cluster_id' => $clusterId,
            'scanner' => 'php_presentation_exists',
            'file' => 'apps/api/app/Modules/Stub/Presentation/Requests/Stub.php',
            'line' => 23,
            'symbol' => 'App\\Modules\\Stub\\Presentation\\Requests\\Stub::rules',
            'pattern_type' => 'bare_exists_validator',
            'resource' => 'payment_methods',
            'expected_scope' => 'tenant_and_company',
            'expected_fix' => 'Replace bare exists with ScopedExists::tenantAndCompany',
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
                    'target_ids' => [$callsiteId],
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
    }

    /**
     * Write the inventory file with a canonical yaml_sha256 (chain fields zeroed).
     * Mirrors InventoryService's hashing algorithm.
     *
     * @param  array<string, mixed>  $document
     */
    private function writeRawInventory(array $document): void
    {
        $document['metadata']['yaml_sha256'] = str_repeat('0', 64);
        $serialized = Yaml::dump($document, 8, 2, Yaml::DUMP_OBJECT_AS_MAP);
        $hash = hash('sha256', $serialized);
        $document['metadata']['yaml_sha256'] = $hash;
        file_put_contents(
            $this->inventoryPath,
            Yaml::dump($document, 8, 2, Yaml::DUMP_OBJECT_AS_MAP),
        );
    }

    /**
     * Re-seed using a mutator that operates on the parsed inventory array.
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutator
     */
    private function reseedInventory(callable $mutator): void
    {
        /** @var array<string, mixed> $document */
        $document = (array) Yaml::parseFile($this->inventoryPath);
        $document = $mutator($document);
        // Recompute the canonical hash like InventoryService does (chain fields zeroed).
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

    /**
     * Read the inventory file from disk, parsed.
     *
     * @return array<string, mixed>
     */
    private function readInventory(): array
    {
        /** @var array<string, mixed> $parsed */
        $parsed = (array) Yaml::parseFile($this->inventoryPath);

        return $parsed;
    }

    /**
     * Convenience: pluck a callsite from disk by id.
     *
     * @return array<string, mixed>
     */
    private function callsiteById(string $callsiteId): array
    {
        $document = $this->readInventory();
        /** @var list<array<string, mixed>> $callsites */
        $callsites = $document['callsites'];
        foreach ($callsites as $cs) {
            if (($cs['id'] ?? null) === $callsiteId) {
                return $cs;
            }
        }
        $this->fail('Callsite not found in inventory: '.$callsiteId);
    }

    /**
     * Convenience: pluck a cluster from disk by id.
     *
     * @return array<string, mixed>
     */
    private function clusterById(string $clusterId): array
    {
        $document = $this->readInventory();
        /** @var list<array<string, mixed>> $clusters */
        $clusters = $document['clusters'];
        foreach ($clusters as $c) {
            if (($c['id'] ?? null) === $clusterId) {
                return $c;
            }
        }
        $this->fail('Cluster not found in inventory: '.$clusterId);
    }
}
