<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Sweep;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Verifies the live tenant-isolation sweep inventory at
 * docs/superpowers/plans/tenant-isolation-sweep-inventory.yml conforms to the
 * JSON Schema at apps/api/app/Application/Sweep/InventoryYamlSchema.json.
 *
 * The schema is the authoritative shape for the sweep:inventory:* artisan
 * commands; CI rejects hand-edits via SweepInventoryVerifyHistoryCommand
 * (Section 5). This test pins the seed file's structure so a stray YAML edit
 * surfaces immediately.
 */
class InventoryYamlSchemaTest extends TestCase
{
    private const SCHEMA_PATH = 'app/Application/Sweep/InventoryYamlSchema.json';

    /**
     * Path is relative to the repo root (two levels above Laravel base_path()).
     * The inventory YAML is shared between apps/api and the docs/ tree, so we
     * locate it via dirname(base_path(), 2).
     */
    private const INVENTORY_RELATIVE_FROM_REPO_ROOT = 'docs/superpowers/plans/tenant-isolation-sweep-inventory.yml';

    public function test_schema_file_is_valid_json(): void
    {
        $contents = $this->loadSchemaContents();

        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        // JSON_THROW_ON_ERROR raises if the file is malformed; the assertion
        // here pins that the top-level decoded value is the expected object
        // shape so a stray scalar/null doesn't slip past.
        $this->assertIsArray($decoded);
    }

    public function test_schema_declares_v2_with_metadata_constraint(): void
    {
        /** @var array<string, mixed> $schema */
        $schema = json_decode($this->loadSchemaContents(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(
            '2',
            $schema['properties']['metadata']['properties']['schema_version']['const'] ?? null,
            'Schema must pin schema_version to "2" so v1 documents fail loudly.',
        );
    }

    public function test_seed_inventory_validates_against_schema(): void
    {
        $errors = $this->validateInventory();
        $this->assertSame(
            [],
            $errors,
            "Seed inventory must validate against the schema. Errors:\n".json_encode($errors, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }

    public function test_seed_inventory_lists_all_expected_api_clusters_per_master_plan_section_6(): void
    {
        /** @var array<string, mixed> $inventory */
        $inventory = $this->loadInventory();

        $apiClusterIds = [];
        /** @var array<int, array<string, mixed>> $clusters */
        $clusters = $inventory['clusters'];
        foreach ($clusters as $cluster) {
            if (($cluster['surface'] ?? null) === 'api') {
                $apiClusterIds[] = $cluster['id'];
            }
        }

        $expected = [
            'api.treasury',
            'api.document',
            'api.inventory',
            'api.taxation',
            'api.loyalty',
            'api.accounting',
            'api.catalog',
            'api.contact',
            'api.compliance',
            'api.pricing',
            'api.service',
            'api.cart',
            'api.workshop',
            'api.identity-company',
            'api.platform-integration',
            'api.webhooks-incoming',
            'api.broadcast-channels',
            'api.scheduled-jobs',
            'api.console-commands',
            'api.auth-permissions',
            'api.super-admin-context',
            'api.module-gating',
            // POS-stabilization landed in this branch as part of the POS
            // go-live work; the cluster remains in the inventory because the
            // tauri.* clusters (sqlite-cache, sync-envelope) still reference
            // it for traceability. Status is verified separately in
            // test_pos_stabilization_cluster_is_fixed_after_pos_go_live().
            'api.pos-stabilization',
            // Marketplace cluster covers MarketplaceListing/MarketplaceSeller
            // controllers and was added after the original master plan
            // Section 6 numbering. It is tracked here so any future scanner
            // regression that drops the cluster surfaces immediately.
            'api.marketplace',
            // api.unmapped is the synthetic catch-all cluster added per
            // Codex Phase 1 review #2. Callsites whose module isn't in
            // ClusterResolver's default map land here so triage can
            // re-classify them in Phase 2 instead of silently joining
            // api.identity-company.
            'api.unmapped',
        ];

        sort($apiClusterIds);
        sort($expected);
        $this->assertSame($expected, $apiClusterIds);
    }

    public function test_tauri_pos_clusters_remain_blocked_on_pos_orchestrator_branch(): void
    {
        /** @var array<string, mixed> $inventory */
        $inventory = $this->loadInventory();
        /** @var array<int, array<string, mixed>> $clusters */
        $clusters = $inventory['clusters'];

        // api.pos-stabilization shipped on the POS go-live branch and is
        // tracked separately by test_pos_stabilization_cluster_is_fixed_after_pos_go_live().
        // The remaining tauri.* clusters track POS-orchestrator-branch work
        // that has not yet landed and must stay blocked here.
        $blockedIds = ['tauri.sqlite-cache', 'tauri.sync-envelope'];
        foreach ($clusters as $cluster) {
            if (! in_array($cluster['id'] ?? null, $blockedIds, true)) {
                continue;
            }
            $this->assertSame('blocked', $cluster['status'] ?? null,
                "Cluster {$cluster['id']} must have status=blocked");
            $this->assertSame('pos_orchestrator_branch', $cluster['blocked_by_external'] ?? null,
                "Cluster {$cluster['id']} must reference pos_orchestrator_branch as blocker");
        }
    }

    public function test_pos_stabilization_cluster_is_fixed_after_pos_go_live(): void
    {
        /** @var array<string, mixed> $inventory */
        $inventory = $this->loadInventory();
        /** @var array<int, array<string, mixed>> $clusters */
        $clusters = $inventory['clusters'];

        $posStabilization = null;
        foreach ($clusters as $cluster) {
            if (($cluster['id'] ?? null) === 'api.pos-stabilization') {
                $posStabilization = $cluster;
                break;
            }
        }
        $this->assertNotNull($posStabilization,
            'api.pos-stabilization cluster must exist in seed inventory');

        // POS go-live work landed on dev via PRs #1-#4 (Z-report cash-count
        // contract, receipt legal-field printing, discount permission
        // fail-closed, operator runbooks). The cluster is therefore expected
        // to be fixed rather than blocked. If this assertion fails, either
        // the YAML reverted incorrectly or the audit-trail entries proving
        // the POS work are missing — investigate before flipping back.
        $this->assertSame('fixed', $posStabilization['status'] ?? null,
            'api.pos-stabilization must be status=fixed after POS go-live shipped on dev.');
        // The schema keeps `blocked_by_external` as a structural field for
        // every cluster, but a fixed cluster must not reference any
        // external branch — the value must be null/empty.
        $this->assertEmpty($posStabilization['blocked_by_external'] ?? null,
            'api.pos-stabilization must no longer reference an external blocker once it is fixed.');
    }

    public function test_treasury_cluster_is_reference_and_blocks_every_other_api_cluster(): void
    {
        /** @var array<string, mixed> $inventory */
        $inventory = $this->loadInventory();
        /** @var array<int, array<string, mixed>> $clusters */
        $clusters = $inventory['clusters'];

        $treasury = null;
        foreach ($clusters as $cluster) {
            if (($cluster['id'] ?? null) === 'api.treasury') {
                $treasury = $cluster;
                break;
            }
        }
        $this->assertNotNull($treasury, 'api.treasury cluster must exist in seed inventory');

        $this->assertTrue($treasury['is_reference'] ?? false,
            'api.treasury must be marked is_reference: true (HARD GATE per master plan Section 7)');
        $this->assertSame('claude', $treasury['required_owner'] ?? null,
            'api.treasury required_owner must be claude');

        $blocks = $treasury['blocks'] ?? [];
        $this->assertContains('api.document', $blocks);
        $this->assertContains('api.inventory', $blocks);
        $this->assertContains('api.auth-permissions', $blocks);
        $this->assertContains('api.console-commands', $blocks);
    }

    public function test_status_enum_lists_all_nine_states(): void
    {
        /** @var array<string, mixed> $inventory */
        $inventory = $this->loadInventory();

        $expected = [
            'pending',
            'claimed',
            'in_progress',
            'under_review',
            'fixed',
            'blocked',
            'deferred',
            'needs_recheck',
            'stale_orphan',
        ];
        $this->assertSame($expected, $inventory['statuses_enum']);
    }

    /**
     * @return array<int, array{path: string, message: string}>
     */
    private function validateInventory(): array
    {
        $schemaContents = $this->loadSchemaContents();
        $inventoryRaw = $this->loadInventoryAsObject();

        $validator = new Validator;
        $resolver = $validator->resolver();
        if ($resolver !== null) {
            $resolver->registerRaw($schemaContents, 'urn:tenant-isolation-sweep-inventory-v2');
        }
        $result = $validator->validate($inventoryRaw, 'urn:tenant-isolation-sweep-inventory-v2');

        if ($result->isValid()) {
            return [];
        }

        $error = $result->error();
        if ($error === null) {
            return [];
        }
        $formatter = new ErrorFormatter;
        $flat = $formatter->formatFlat($error);

        $rows = [];
        foreach ($flat as $message) {
            $rows[] = ['path' => '', 'message' => (string) $message];
        }

        return $rows;
    }

    private function loadSchemaContents(): string
    {
        return (string) file_get_contents(base_path(self::SCHEMA_PATH));
    }

    private function inventoryPath(): string
    {
        return dirname(base_path(), 2).'/'.self::INVENTORY_RELATIVE_FROM_REPO_ROOT;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadInventory(): array
    {
        $parsed = Yaml::parseFile($this->inventoryPath());
        if (! is_array($parsed)) {
            $this->fail('Inventory YAML must parse to an array.');
        }

        return $parsed;
    }

    /**
     * Opis JSON Schema validates against object-shaped input (stdClass), not
     * arrays. Round-trip through json_encode/decode to convert the YAML's
     * associative arrays into objects.
     */
    private function loadInventoryAsObject(): mixed
    {
        $array = $this->loadInventory();

        return json_decode((string) json_encode($array), false, 512, JSON_THROW_ON_ERROR);
    }
}
