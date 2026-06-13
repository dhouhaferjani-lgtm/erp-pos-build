<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Catalog\Media;

use App\Modules\Catalog\Domain\Enums\MediaRole;
use App\Modules\Catalog\Domain\Enums\MediaSource;
use App\Modules\Catalog\Domain\Enums\MediaStatus;
use App\Modules\Catalog\Domain\Media\MediaAsset;
use App\Modules\Catalog\Domain\Media\MediaAttachment;
use App\Modules\Company\Domain\Company;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\ProductImagePlaceholderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlaceholderSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_external_url_primary_attachments_and_is_rerunnable(): void
    {
        // Create a tenant, company and product so the seeder has something to work with
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $product = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
        ]);

        $this->seed(ProductImagePlaceholderSeeder::class);
        $this->seed(ProductImagePlaceholderSeeder::class); // idempotent

        $asset = MediaAsset::where('source', MediaSource::ExternalUrl)->first();
        self::assertNotNull($asset);
        self::assertSame(MediaStatus::Ready, $asset->status);
        self::assertTrue(MediaAttachment::where('role', MediaRole::Primary)->exists());
    }
}
