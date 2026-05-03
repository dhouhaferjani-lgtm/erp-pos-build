<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Sweep\Scanners;

use App\Application\Sweep\Scanners\CallsiteRow;
use App\Application\Sweep\Scanners\ManualScanner;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Verifies ManualScanner — the fifth scanner in the master plan Section 5
 * lineup. Reads a YAML stub of manually-declared callsites for clusters
 * mechanical scanners can't reach (PlatformIntegration outbound payloads,
 * broadcast channel auth, scheduled jobs that cross tenants — see master
 * plan Section 5.5).
 *
 * Manual rows survive regeneration because their stable_key is a literal
 * `manual:<cluster>:<slug>` prefix, never reproducible by a sha256-based
 * scanner. They're only removed via `sweep:inventory:defer` /
 * `sweep:inventory:resolve` workflow.
 */
class ManualScannerTest extends TestCase
{
    private string $tempStub;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempStub = sys_get_temp_dir().'/manual-scanner-stub-'.bin2hex(random_bytes(8)).'.yml';
    }

    protected function tearDown(): void
    {
        if (is_file($this->tempStub)) {
            unlink($this->tempStub);
        }
        parent::tearDown();
    }

    public function test_empty_stub_returns_empty_result(): void
    {
        $this->writeStub(['manual_callsites' => []]);

        $scanner = new ManualScanner($this->tempStub);

        $this->assertSame([], $scanner->scan());
    }

    public function test_missing_stub_file_returns_empty_result_without_crashing(): void
    {
        // Stub file does not exist — the scanner should treat that as
        // "no manual callsites declared" rather than fail. This matches
        // the inventory-generator's expectation that all five scanners
        // are always callable.
        $scanner = new ManualScanner($this->tempStub);

        $this->assertSame([], $scanner->scan());
    }

    public function test_stub_with_entries_emits_one_row_per_entry(): void
    {
        $this->writeStub([
            'manual_callsites' => [
                [
                    'cluster_id' => 'api.platform-integration',
                    'slug' => 'platform-http-client-tenant-binding',
                    'file' => 'apps/api/app/Modules/Platform/Infrastructure/Http/PlatformHttpClient.php',
                    'symbol' => 'App\\Modules\\Platform\\Infrastructure\\Http\\PlatformHttpClient::request',
                    'pattern_type' => 'outbound_tenant_identity',
                    'resource' => null,
                    'expected_scope' => 'tenant_only',
                    'severity' => 'high',
                    'expected_fix' => 'Verify outbound payload includes correct tenant identity, not shared API key alone.',
                ],
                [
                    'cluster_id' => 'api.broadcast-channels',
                    'slug' => 'company-channel-authorization',
                    'file' => 'apps/api/routes/channels.php',
                    'symbol' => 'canAccessCompanyChannel',
                    'pattern_type' => 'broadcast_channel_auth',
                    'resource' => null,
                    'expected_scope' => 'tenant_and_company',
                    'severity' => 'high',
                    'expected_fix' => 'Channel callback must verify $user->tenant_id matches the authenticated tenant.',
                ],
            ],
        ]);

        $scanner = new ManualScanner($this->tempStub);
        $rows = $scanner->scan();

        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertInstanceOf(CallsiteRow::class, $row);
            $this->assertSame('manual', $row->scanner);
        }
    }

    public function test_stable_key_uses_manual_cluster_slug_prefix(): void
    {
        $this->writeStub([
            'manual_callsites' => [
                [
                    'cluster_id' => 'api.platform-integration',
                    'slug' => 'tenant-binding',
                    'file' => 'apps/api/app/Modules/Platform/Foo.php',
                    'symbol' => 'Foo::bar',
                    'pattern_type' => 'outbound_tenant_identity',
                    'resource' => null,
                    'expected_scope' => 'tenant_only',
                    'severity' => 'high',
                    'expected_fix' => 'fix it',
                ],
            ],
        ]);

        $scanner = new ManualScanner($this->tempStub);
        $rows = $scanner->scan();

        $this->assertCount(1, $rows);
        $this->assertSame('manual:api.platform-integration:tenant-binding', $rows[0]->stableKey);
    }

    public function test_missing_required_field_throws_with_offending_index(): void
    {
        // The 0th entry omits `cluster_id`. The scanner must reject the
        // stub and name the offending entry index in the exception message.
        $this->writeStub([
            'manual_callsites' => [
                [
                    'slug' => 'orphan-no-cluster',
                    'file' => 'apps/api/app/Modules/Platform/Foo.php',
                    'symbol' => 'Foo::bar',
                    'pattern_type' => 'outbound_tenant_identity',
                    'expected_scope' => 'tenant_only',
                    'severity' => 'high',
                    'expected_fix' => 'fix it',
                ],
            ],
        ]);

        $scanner = new ManualScanner($this->tempStub);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/index 0/');
        $scanner->scan();
    }

    public function test_resource_field_is_optional(): void
    {
        $this->writeStub([
            'manual_callsites' => [
                [
                    'cluster_id' => 'api.platform-integration',
                    'slug' => 'no-resource',
                    'file' => 'apps/api/app/Modules/Platform/Foo.php',
                    'symbol' => 'Foo::bar',
                    'pattern_type' => 'outbound_tenant_identity',
                    'expected_scope' => 'tenant_only',
                    'severity' => 'high',
                    'expected_fix' => 'fix it',
                    // resource intentionally omitted
                ],
            ],
        ]);

        $scanner = new ManualScanner($this->tempStub);
        $rows = $scanner->scan();

        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]->resource);
    }

    public function test_scanner_name_is_manual(): void
    {
        $scanner = new ManualScanner($this->tempStub);

        $this->assertSame('manual', $scanner->name());
    }

    public function test_duplicate_cluster_id_plus_slug_throws_with_both_indexes(): void
    {
        // Codex Phase 1 review #4: two entries that share cluster_id + slug
        // produce identical stable_keys (manual:<cluster>:<slug>). Catching
        // this at scan time is much friendlier than letting the duplicate
        // trickle into InventoryService schema validation downstream.
        $this->writeStub([
            'manual_callsites' => [
                [
                    'cluster_id' => 'api.platform-integration',
                    'slug' => 'duplicate-slug',
                    'file' => 'apps/api/app/Modules/Platform/First.php',
                    'symbol' => 'First::bar',
                    'pattern_type' => 'outbound_tenant_identity',
                    'expected_scope' => 'tenant_only',
                    'severity' => 'high',
                    'expected_fix' => 'fix it',
                ],
                [
                    'cluster_id' => 'api.platform-integration',
                    'slug' => 'duplicate-slug',
                    'file' => 'apps/api/app/Modules/Platform/Second.php',
                    'symbol' => 'Second::bar',
                    'pattern_type' => 'outbound_tenant_identity',
                    'expected_scope' => 'tenant_only',
                    'severity' => 'high',
                    'expected_fix' => 'fix it again',
                ],
            ],
        ]);

        $scanner = new ManualScanner($this->tempStub);

        try {
            $scanner->scan();
            $this->fail('Expected RuntimeException for duplicate cluster_id+slug.');
        } catch (\RuntimeException $e) {
            // Message must enumerate the offending key and BOTH indexes so
            // the human can find both entries quickly.
            $this->assertStringContainsString(
                'api.platform-integration:duplicate-slug',
                $e->getMessage(),
                'Exception must name the offending cluster_id:slug pair.',
            );
            $this->assertMatchesRegularExpression(
                '/index(es)? 0[,\s]+(.* )?1/',
                $e->getMessage(),
                'Exception must enumerate both offending indexes (0 and 1).',
            );
        }
    }

    public function test_same_slug_different_cluster_is_not_a_duplicate(): void
    {
        // Two entries with the same slug but different cluster_id are NOT
        // a collision (their stable_keys differ). Pin this so the duplicate
        // check doesn't over-trigger.
        $this->writeStub([
            'manual_callsites' => [
                [
                    'cluster_id' => 'api.platform-integration',
                    'slug' => 'shared-slug',
                    'file' => 'apps/api/app/Modules/Platform/First.php',
                    'symbol' => 'First::bar',
                    'pattern_type' => 'outbound_tenant_identity',
                    'expected_scope' => 'tenant_only',
                    'severity' => 'high',
                    'expected_fix' => 'fix',
                ],
                [
                    'cluster_id' => 'api.broadcast-channels',
                    'slug' => 'shared-slug',
                    'file' => 'apps/api/routes/channels.php',
                    'symbol' => 'canAccessFoo',
                    'pattern_type' => 'broadcast_channel_auth',
                    'expected_scope' => 'tenant_and_company',
                    'severity' => 'high',
                    'expected_fix' => 'fix',
                ],
            ],
        ]);

        $scanner = new ManualScanner($this->tempStub);
        $rows = $scanner->scan();
        $this->assertCount(2, $rows);
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function writeStub(array $document): void
    {
        file_put_contents($this->tempStub, Yaml::dump($document, 6, 2));
    }
}
