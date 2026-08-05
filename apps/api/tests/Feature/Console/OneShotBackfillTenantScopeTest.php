<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Media\Application\Jobs\GenerateRenditions;
use App\Modules\Media\Domain\Enums\MediaAssetType;
use App\Modules\Media\Domain\Enums\MediaSource;
use App\Modules\Media\Domain\Enums\MediaStatus;
use App\Modules\Media\Domain\Media\MediaAsset;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The four remaining cat-(b) one-shot / maintenance commands
 * (2026-08-05 wave 2).
 *
 * All four asserted a fleet-wide scope over TENANT tables from the console's
 * CENTRAL connection, so all four have been raising 42P01 and doing nothing
 * since the 2026-05-28 database-per-tenant flip:
 *
 *   procurement:backfill-goods-receipts  companies / goods_receipt_lines /
 *                                        stock_movements
 *   import:fix-orphaned-products         products / stock_levels / locations
 *   products:generate-image-variants     media_assets
 *   parapharmacy:migrate-data            parapharmacy_product_metadata + pivots
 *
 * The shared contract asserted here is the one-shot rule: the scope must be
 * named. A repair pass that silently skips a tenant leaves that tenant
 * permanently broken with no signal, so an absent scope and an unreachable
 * `--tenant` are both loud failures rather than "nothing to do".
 */
final class OneShotBackfillTenantScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function oneShotCommandProvider(): array
    {
        return [
            'procurement:backfill-goods-receipts' => ['procurement:backfill-goods-receipts'],
            'import:fix-orphaned-products' => ['import:fix-orphaned-products'],
            'products:generate-image-variants' => ['products:generate-image-variants'],
            'parapharmacy:migrate-data' => ['parapharmacy:migrate-data'],
        ];
    }

    #[DataProvider('oneShotCommandProvider')]
    public function test_it_refuses_to_run_without_an_explicit_scope(string $command): void
    {
        Tenant::factory()->create();

        $this->artisan($command)
            ->expectsOutputToContain('Refusing to run without an explicit scope')
            ->assertExitCode(2);
    }

    #[DataProvider('oneShotCommandProvider')]
    public function test_it_refuses_both_scopes_at_once(string $command): void
    {
        $tenant = Tenant::factory()->create();

        $this->artisan($command, ['--tenant' => $tenant->id, '--all-tenants' => true])
            ->expectsOutputToContain('mutually exclusive')
            ->assertExitCode(2);
    }

    #[DataProvider('oneShotCommandProvider')]
    public function test_an_unknown_tenant_fails_loudly(string $command): void
    {
        Tenant::factory()->create();

        $this->artisan($command, ['--tenant' => (string) Str::uuid()])
            ->expectsOutputToContain('not found in the central tenant directory')
            ->assertFailed();
    }

    // =================================================================
    // import:fix-orphaned-products
    // =================================================================

    public function test_orphan_repair_only_touches_the_named_tenant(): void
    {
        [$selectedTenant, $selectedProduct] = $this->makeOrphanedProduct();
        [, $otherProduct] = $this->makeOrphanedProduct();

        $this->artisan('import:fix-orphaned-products', ['--tenant' => $selectedTenant->id])
            ->assertExitCode(0);

        $this->assertSame(1, $this->stockLevelCount((string) $selectedProduct->id));
        $this->assertSame(
            0,
            $this->stockLevelCount((string) $otherProduct->id),
            'A tenant outside the named scope must not be repaired by a one-shot pass',
        );
    }

    public function test_orphan_repair_reaches_every_tenant_under_all_tenants(): void
    {
        [, $a] = $this->makeOrphanedProduct();
        [, $b] = $this->makeOrphanedProduct();

        $this->artisan('import:fix-orphaned-products', ['--all-tenants' => true])
            ->assertExitCode(0);

        $this->assertSame(1, $this->stockLevelCount((string) $a->id));
        $this->assertSame(1, $this->stockLevelCount((string) $b->id));
    }

    /**
     * A product whose tenant_id has no row in the central directory is
     * unreachable by forEachTenant, so it must never be repaired — the
     * pre-conversion fleet-wide left join would have swept it up.
     */
    public function test_a_product_whose_tenant_is_absent_from_the_directory_is_never_repaired(): void
    {
        [, $reachable] = $this->makeOrphanedProduct();

        $orphanCompany = Company::factory()->create(['tenant_id' => (string) Str::uuid()]);
        Location::factory()->create(['company_id' => $orphanCompany->id, 'is_default' => true, 'is_active' => true]);
        $orphanProduct = Product::factory()->create([
            'tenant_id' => $orphanCompany->tenant_id,
            'company_id' => $orphanCompany->id,
        ]);
        DB::table('stock_levels')->where('product_id', $orphanProduct->id)->delete();

        $this->artisan('import:fix-orphaned-products', ['--all-tenants' => true])
            ->assertExitCode(0);

        $this->assertSame(1, $this->stockLevelCount((string) $reachable->id));
        $this->assertSame(0, $this->stockLevelCount((string) $orphanProduct->id));
    }

    // =================================================================
    // products:generate-image-variants
    // =================================================================

    public function test_rendition_dispatch_only_covers_the_named_tenant(): void
    {
        Queue::fake();

        $selected = $this->makeUploadedImageAsset();
        $other = $this->makeUploadedImageAsset();

        $this->artisan('products:generate-image-variants', ['--tenant' => $selected->tenant_id])
            ->assertExitCode(0);

        Queue::assertPushed(GenerateRenditions::class, 1);
        Queue::assertPushed(
            GenerateRenditions::class,
            fn (GenerateRenditions $job): bool => $this->jobTenantId($job) === $selected->tenant_id,
        );
        Queue::assertNotPushed(
            GenerateRenditions::class,
            fn (GenerateRenditions $job): bool => $this->jobTenantId($job) === $other->tenant_id,
        );
    }

    public function test_rendition_dispatch_reaches_every_tenant_under_all_tenants(): void
    {
        Queue::fake();

        $this->makeUploadedImageAsset();
        $this->makeUploadedImageAsset();

        $this->artisan('products:generate-image-variants', ['--all-tenants' => true])
            ->assertExitCode(0);

        Queue::assertPushed(GenerateRenditions::class, 2);
    }

    // =================================================================
    // Helpers
    // =================================================================

    /**
     * @return array{0: Tenant, 1: Product}
     */
    private function makeOrphanedProduct(): array
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        Location::factory()->create([
            'company_id' => $company->id,
            'is_default' => true,
            'is_active' => true,
        ]);

        $product = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
        ]);

        // Product creation may seed stock levels; strip them so the product is
        // genuinely the orphan this command exists to repair.
        DB::table('stock_levels')->where('product_id', $product->id)->delete();

        return [$tenant, $product];
    }

    private function makeUploadedImageAsset(): MediaAsset
    {
        $tenant = Tenant::factory()->create();

        return MediaAsset::create([
            'tenant_id' => $tenant->id,
            'type' => MediaAssetType::Image,
            'source' => MediaSource::Upload,
            'status' => MediaStatus::Uploaded,
            'storage_disk' => 'local',
            'storage_path' => 'products/'.Str::uuid()->toString().'.jpg',
        ]);
    }

    private function jobTenantId(GenerateRenditions $job): string
    {
        return $job->tenantId;
    }

    private function stockLevelCount(string $productId): int
    {
        return DB::table('stock_levels')->where('product_id', $productId)->count();
    }
}
