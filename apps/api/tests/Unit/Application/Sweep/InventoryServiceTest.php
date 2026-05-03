<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Sweep;

use App\Application\Sweep\Domain\InventoryDocument;
use App\Application\Sweep\Domain\MutationContext;
use App\Application\Sweep\Domain\MutationResult;
use App\Application\Sweep\Exceptions\OptimisticLockException;
use App\Application\Sweep\InventoryService;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;
use Throwable;

/**
 * Verifies App\Application\Sweep\InventoryService — the atomic mutate path
 * for the tenant-isolation sweep inventory YAML (master plan Section 4 + 5).
 *
 * Contract pinned by these tests:
 *   1. load() parses + validates the YAML; surfaces clusters/callsites typed.
 *   2. mutate() acquires a flock, re-reads, validates yaml_sha256 chain,
 *      runs the mutator, recomputes hash, validates against schema, and
 *      writes atomically (tempfile + fsync + rename).
 *   3. mutate() appends a history[] event to every affected callsite with
 *      previous_yaml_sha256 / new_yaml_sha256 pinning the chain.
 *   4. Any exception inside the mutator MUST leave the on-disk file
 *      unchanged — partial writes are not acceptable.
 *   5. Schema-invalid mutator output is rejected before write.
 *   6. If the on-disk YAML changed between read and mutate (e.g. another
 *      process appended), OptimisticLockException is thrown — the in-flight
 *      mutator's view of the world is stale.
 *
 * Uses a per-test temp directory; never mutates the real seed inventory.
 */
class InventoryServiceTest extends TestCase
{
    private string $tempDir;

    private string $inventoryPath;

    private string $schemaPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir().'/inventory-service-test-'.bin2hex(random_bytes(8));
        if (! mkdir($this->tempDir, 0755, true) && ! is_dir($this->tempDir)) {
            $this->fail('Failed to create temp dir at '.$this->tempDir);
        }

        $this->inventoryPath = $this->tempDir.'/inventory.yml';
        $this->schemaPath = base_path('app/Application/Sweep/InventoryYamlSchema.json');

        // Seed the temp inventory with a minimal valid v2 document.
        $this->writeRawInventory($this->minimalInventoryArray());
    }

    protected function tearDown(): void
    {
        if (is_file($this->inventoryPath)) {
            unlink($this->inventoryPath);
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    public function test_load_parses_seed_inventory_into_typed_document(): void
    {
        $service = new InventoryService($this->inventoryPath, $this->schemaPath);

        $doc = $service->load();

        $this->assertInstanceOf(InventoryDocument::class, $doc);
        $this->assertSame('2', $doc->schemaVersion());
        $this->assertCount(1, $doc->clusters());
        $this->assertSame('api.treasury', $doc->clusters()[0]['id']);
        $this->assertCount(1, $doc->callsites());
        $this->assertSame('api.treasury.001', $doc->callsites()[0]['id']);
    }

    public function test_mutate_appends_history_event_with_sha256_chain_and_writes_atomically(): void
    {
        $service = new InventoryService($this->inventoryPath, $this->schemaPath);
        $beforeHash = $this->fieldOnDisk('metadata', 'yaml_sha256');

        $context = new MutationContext('claude', 'sweep:inventory:claim', 'claim', 'abc1234');

        $result = $service->mutate(
            function (InventoryDocument $doc) use ($context): InventoryDocument {
                return $doc->withCallsiteUpdate(
                    'api.treasury.001',
                    function (array $callsite) use ($context): array {
                        $callsite['status'] = 'claimed';
                        $callsite['owner'] = 'claude';
                        $callsite['history'][] = $this->makeHistoryStub(
                            actor: 'claude',
                            action: 'claim',
                            command: $context->command,
                            fromStatus: 'pending',
                            toStatus: 'claimed',
                            targetIds: ['api.treasury.001'],
                        );

                        return $callsite;
                    },
                );
            },
            $context,
        );

        $this->assertInstanceOf(MutationResult::class, $result);
        $this->assertSame($beforeHash, $result->previousYamlSha256);
        $this->assertNotSame($beforeHash, $result->newYamlSha256);
        $this->assertSame(1, $result->eventsAppended);

        // Re-read from disk to verify atomic write actually persisted the changes.
        $afterHash = $this->fieldOnDisk('metadata', 'yaml_sha256');
        $this->assertSame($result->newYamlSha256, $afterHash);

        /** @var array<string, mixed> $reread */
        $reread = (array) Yaml::parseFile($this->inventoryPath);
        /** @var list<array<string, mixed>> $callsites */
        $callsites = $reread['callsites'];
        $this->assertSame('claimed', $callsites[0]['status']);
        /** @var list<array<string, mixed>> $history */
        $history = $callsites[0]['history'];
        // Originally 1 (generate) + the new event we appended (claim) +
        // the chain event auto-appended by the service. We require AT LEAST
        // a chain event with previous/new yaml_sha256 set.
        $latest = $history[count($history) - 1];
        $this->assertSame($beforeHash, $latest['previous_yaml_sha256']);
        $this->assertSame($result->newYamlSha256, $latest['new_yaml_sha256']);
        $this->assertSame('sweep:inventory:claim', $latest['command']);
        $this->assertSame('claude', $latest['actor']);
        $this->assertSame('abc1234', $latest['commit']);
    }

    public function test_mutate_throws_optimistic_lock_exception_when_on_disk_yaml_changed_between_load_and_apply(): void
    {
        $service = new InventoryService($this->inventoryPath, $this->schemaPath);

        $context = new MutationContext('claude', 'sweep:inventory:claim', 'claim', null);

        $this->expectException(OptimisticLockException::class);

        $service->mutate(
            function (InventoryDocument $doc): InventoryDocument {
                // Simulate a concurrent writer that bypasses the lock by
                // editing the file directly between our load and write.
                // We can't bypass our own flock from inside the same process,
                // so we rewrite the file's metadata.yaml_sha256 to something
                // bogus to invalidate the chain check.
                $contents = (string) file_get_contents($this->inventoryPath);
                // Symfony's YAML dumper writes the hash unquoted; match
                // either form to be safe.
                $tampered = preg_replace(
                    '/yaml_sha256: "?[0-9a-f]{64}"?/',
                    'yaml_sha256: '.str_repeat('f', 64),
                    $contents,
                    1,
                );
                $this->assertNotNull($tampered);
                $this->assertNotSame($contents, $tampered, 'Test setup: tampering must actually change the file content.');
                file_put_contents($this->inventoryPath, $tampered);

                return $doc;
            },
            $context,
        );
    }

    public function test_mutator_thrown_exception_leaves_file_on_disk_unchanged(): void
    {
        $service = new InventoryService($this->inventoryPath, $this->schemaPath);

        $beforeContents = (string) file_get_contents($this->inventoryPath);
        $beforeHash = $this->fieldOnDisk('metadata', 'yaml_sha256');

        $thrown = null;
        try {
            $service->mutate(
                function (InventoryDocument $doc): InventoryDocument {
                    throw new \RuntimeException('mutator blew up');
                },
                new MutationContext('claude', 'sweep:inventory:claim', 'claim', null),
            );
        } catch (Throwable $t) {
            $thrown = $t;
        }

        $this->assertInstanceOf(\RuntimeException::class, $thrown);
        $this->assertSame('mutator blew up', $thrown->getMessage());

        // File is byte-for-byte unchanged.
        $afterContents = (string) file_get_contents($this->inventoryPath);
        $this->assertSame($beforeContents, $afterContents);
        $this->assertSame($beforeHash, $this->fieldOnDisk('metadata', 'yaml_sha256'));
    }

    public function test_schema_validation_rejects_mutator_producing_invalid_document(): void
    {
        $service = new InventoryService($this->inventoryPath, $this->schemaPath);

        $beforeContents = (string) file_get_contents($this->inventoryPath);

        $thrown = null;
        try {
            $service->mutate(
                function (InventoryDocument $doc): InventoryDocument {
                    return $doc->withCallsiteUpdate(
                        'api.treasury.001',
                        function (array $callsite): array {
                            // Schema enum says status must be one of the 9
                            // documented states. This bogus value must be
                            // rejected before the write happens.
                            $callsite['status'] = 'not_a_real_status';

                            return $callsite;
                        },
                    );
                },
                new MutationContext('claude', 'sweep:inventory:claim', 'claim', null),
            );
        } catch (Throwable $t) {
            $thrown = $t;
        }

        $this->assertNotNull($thrown, 'Expected schema validation to throw.');
        $this->assertStringContainsString('schema', strtolower($thrown->getMessage()));

        // File on disk unchanged.
        $this->assertSame($beforeContents, (string) file_get_contents($this->inventoryPath));
    }

    public function test_mutate_returns_zero_events_appended_when_no_history_events_added(): void
    {
        $service = new InventoryService($this->inventoryPath, $this->schemaPath);

        $context = new MutationContext('claude', 'sweep:inventory:claim', 'claim', null);

        // A no-op mutator: returns the document unchanged. The service should
        // still seal the chain (yaml_sha256 may need recompute) but no
        // domain-level history events were added by the caller.
        $result = $service->mutate(
            fn (InventoryDocument $doc): InventoryDocument => $doc,
            $context,
        );

        $this->assertSame(0, $result->eventsAppended);
    }

    public function test_metadata_chain_event_records_target_ids_for_status_changes(): void
    {
        $service = new InventoryService($this->inventoryPath, $this->schemaPath);

        $context = new MutationContext('claude', 'sweep:inventory:claim', 'claim', null);

        $service->mutate(
            function (InventoryDocument $doc): InventoryDocument {
                return $doc->withCallsiteUpdate(
                    'api.treasury.001',
                    function (array $callsite): array {
                        $callsite['status'] = 'claimed';
                        $callsite['owner'] = 'claude';
                        $callsite['history'][] = $this->makeHistoryStub(
                            actor: 'claude',
                            action: 'claim',
                            command: 'sweep:inventory:claim',
                            fromStatus: 'pending',
                            toStatus: 'claimed',
                            targetIds: ['api.treasury.001'],
                        );

                        return $callsite;
                    },
                );
            },
            $context,
        );

        /** @var array<string, mixed> $reread */
        $reread = (array) Yaml::parseFile($this->inventoryPath);
        /** @var list<array<string, mixed>> $callsites */
        $callsites = $reread['callsites'];
        /** @var list<array<string, mixed>> $history */
        $history = $callsites[0]['history'];
        $chainEvent = $history[count($history) - 1];

        $this->assertSame(['api.treasury.001'], $chainEvent['target_ids']);
        $this->assertSame('pending', $chainEvent['from_status']);
        $this->assertSame('claimed', $chainEvent['to_status']);
    }

    /**
     * Read a top-level field from the inventory YAML on disk.
     */
    private function fieldOnDisk(string $section, string $field): string
    {
        /** @var array<string, mixed> $parsed */
        $parsed = (array) Yaml::parseFile($this->inventoryPath);
        /** @var array<string, mixed> $sectionData */
        $sectionData = $parsed[$section];

        return (string) $sectionData[$field];
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function writeRawInventory(array $document): void
    {
        // Compute the canonical yaml_sha256 the same way the service does:
        // serialize with yaml_sha256 zeroed, hash, then set it.
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
     * @return array<string, mixed>
     */
    private function minimalInventoryArray(): array
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
                    'can_claim' => ['api.treasury'],
                    'can_review' => ['*'],
                ],
                'codex' => [
                    'role' => 'codex',
                    'can_claim' => [],
                    'can_review' => ['*'],
                ],
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
            'callsites' => [
                [
                    'id' => 'api.treasury.001',
                    'stable_key' => 'sha256:'.str_repeat('a', 64),
                    'surface' => 'api',
                    'cluster_id' => 'api.treasury',
                    'scanner' => 'php_presentation_exists',
                    'file' => 'apps/api/app/Modules/Treasury/Presentation/Requests/Stub.php',
                    'line' => 23,
                    'symbol' => 'App\\Modules\\Treasury\\Presentation\\Requests\\Stub::rules',
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
                            'target_ids' => ['api.treasury.001'],
                            'from_status' => null,
                            'to_status' => 'pending',
                            'commit' => null,
                            'test' => null,
                            'review_file' => null,
                            'review_commit' => null,
                            'note' => 'initial detection (test fixture)',
                        ],
                    ],
                ],
            ],
            'progress' => [
                'total_callsites' => 1,
                'total_clusters' => 1,
                'by_status' => [
                    'pending' => 1, 'claimed' => 0, 'in_progress' => 0, 'under_review' => 0,
                    'fixed' => 0, 'blocked' => 0, 'deferred' => 0, 'needs_recheck' => 0, 'stale_orphan' => 0,
                ],
                'by_surface' => [
                    'api' => ['total' => 1, 'fixed' => 0],
                ],
                'by_owner' => [
                    'claude' => ['claimed' => 0, 'fixed' => 0],
                    'codex' => ['claimed' => 0, 'fixed' => 0],
                    'unassigned' => 1,
                ],
                'drift' => [
                    'yaml_says_fixed_code_unsafe' => 0,
                    'code_safe_yaml_pending' => 0,
                ],
            ],
        ];
    }

    /**
     * @param  list<string>  $targetIds
     * @return array<string, mixed>
     */
    private function makeHistoryStub(
        string $actor,
        string $action,
        string $command,
        ?string $fromStatus,
        string $toStatus,
        array $targetIds,
    ): array {
        return [
            'at' => '2026-05-03T01:00:00Z',
            'actor' => $actor,
            'action' => $action,
            'command' => $command,
            'previous_yaml_sha256' => null,
            'new_yaml_sha256' => null,
            'target_ids' => $targetIds,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'commit' => null,
            'test' => null,
            'review_file' => null,
            'review_commit' => null,
            'note' => 'test event',
        ];
    }
}
