<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Product;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Media\Domain\Enums\MediaAssetType;
use App\Modules\Media\Domain\Enums\MediaOwnerType;
use App\Modules\Media\Domain\Enums\MediaRole;
use App\Modules\Media\Domain\Enums\MediaSource;
use App\Modules\Media\Domain\Enums\MediaStatus;
use App\Modules\Media\Domain\Media\MediaAsset;
use App\Modules\Media\Domain\Media\MediaAttachment;
use App\Modules\Product\Application\Jobs\ApplyCatalogEnrichmentJob;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RunEnrichmentCommandTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Run Enrichment Tenant',
            'slug' => 'run-enrichment-'.Str::lower(Str::random(6)),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Parapharmacy,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Run Enrichment Company',
            'legal_name' => 'Run Enrichment Company LLC',
            'tax_id' => 'TAX-'.Str::upper(Str::random(8)),
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);
    }

    public function test_dispatches_for_eligible_products_on_enrichment_queue(): void
    {
        Queue::fake();

        $this->makeProduct('platform-product-001', '3017620422001');
        $this->makeProduct('platform-product-002', '3017620422002');
        // Ineligible: missing barcode.
        Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'No Barcode Product',
            'barcode' => null,
            'platform_product_id' => 'platform-product-003',
        ]);

        $this->artisan('enrichment:run', ['tenant' => $this->tenant->id, '--vertical' => 'parapharmacy'])
            ->assertExitCode(0);

        Queue::assertPushed(ApplyCatalogEnrichmentJob::class, 2);
        Queue::assertPushed(
            ApplyCatalogEnrichmentJob::class,
            fn (ApplyCatalogEnrichmentJob $job): bool => $job->queue === 'enrichment'
        );
    }

    public function test_uses_tenant_vertical_when_option_omitted(): void
    {
        Queue::fake();

        $this->makeProduct('platform-product-010', '3017620422010');

        $this->artisan('enrichment:run', ['tenant' => $this->tenant->id])
            ->assertExitCode(0);

        Queue::assertPushed(
            ApplyCatalogEnrichmentJob::class,
            fn (ApplyCatalogEnrichmentJob $job): bool => $job->vertical === Vertical::Parapharmacy->value
        );
    }

    public function test_only_missing_images_skips_products_with_primary_attachment(): void
    {
        Queue::fake();

        $withImage = $this->makeProduct('platform-product-020', '3017620422020');
        $this->makeProduct('platform-product-021', '3017620422021');

        $asset = MediaAsset::create([
            'tenant_id' => $this->tenant->id,
            'type' => MediaAssetType::Image,
            'source' => MediaSource::ExternalUrl,
            'status' => MediaStatus::Ready,
            'storage_disk' => 'url',
            'external_url' => 'https://example.test/image.jpg',
        ]);

        MediaAttachment::query()->create([
            'tenant_id' => $this->tenant->id,
            'media_asset_id' => $asset->id,
            'owner_type' => MediaOwnerType::Product,
            'owner_id' => $withImage->id,
            'role' => MediaRole::Primary,
            'sort_order' => 0,
        ]);

        $this->artisan('enrichment:run', [
            'tenant' => $this->tenant->id,
            '--vertical' => 'parapharmacy',
            '--only-missing-images' => true,
        ])->assertExitCode(0);

        Queue::assertPushed(ApplyCatalogEnrichmentJob::class, 1);
        Queue::assertPushed(
            ApplyCatalogEnrichmentJob::class,
            fn (ApplyCatalogEnrichmentJob $job): bool => $job->productId !== $withImage->id
        );
    }

    public function test_unknown_tenant_fails(): void
    {
        Queue::fake();

        $this->artisan('enrichment:run', ['tenant' => (string) Str::uuid()])
            ->assertExitCode(1);

        Queue::assertNothingPushed();
    }

    private function makeProduct(string $platformProductId, string $barcode): Product
    {
        return Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Product '.$platformProductId,
            'barcode' => $barcode,
            'platform_product_id' => $platformProductId,
        ]);
    }
}
