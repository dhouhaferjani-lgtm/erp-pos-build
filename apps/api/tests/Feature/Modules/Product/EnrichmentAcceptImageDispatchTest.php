<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Product;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Product\Application\DTOs\EnrichedProductData;
use App\Modules\Product\Application\Jobs\PersistEnrichmentImagesJob;
use App\Modules\Product\Application\Services\EnrichmentReviewService;
use App\Modules\Product\Domain\EnrichmentResult;
use App\Modules\Product\Domain\Enums\EnrichmentReviewStatus;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class EnrichmentAcceptImageDispatchTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private EnrichmentReviewService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Enrichment Accept Image Dispatch',
            'slug' => 'enrichment-accept-image-dispatch-'.Str::lower(Str::random(6)),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Parapharmacy,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Enrichment Accept Image Dispatch Company',
            'legal_name' => 'Enrichment Accept Image Dispatch Company LLC',
            'tax_id' => 'TAX-'.Str::upper(Str::random(8)),
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Reviewer',
            'email' => 'reviewer@example.tn',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
        $this->service = $this->app->make(EnrichmentReviewService::class);
    }

    public function test_accept_auto_dispatches_when_accepted_fields_empty(): void
    {
        Bus::fake();
        $result = $this->makePendingResultWithImages(['https://pharma-shop.tn/a.jpg']);

        $this->service->accept($result, [], $this->user->id);

        Bus::assertDispatched(PersistEnrichmentImagesJob::class);
    }

    public function test_accept_dispatches_when_images_field_included(): void
    {
        Bus::fake();
        $result = $this->makePendingResultWithImages(['https://pharma-shop.tn/a.jpg']);

        $this->service->accept($result, ['name', 'images'], $this->user->id);

        Bus::assertDispatched(PersistEnrichmentImagesJob::class, fn (PersistEnrichmentImagesJob $j): bool => $j->productId === $result->product_id
            && $j->tenantId === $result->tenant_id
            && $j->images !== []);
    }

    public function test_accept_skips_images_when_fields_present_and_exclude_images(): void
    {
        Bus::fake();
        $result = $this->makePendingResultWithImages(['https://pharma-shop.tn/a.jpg']);

        $this->service->accept($result, ['name'], $this->user->id);

        Bus::assertNotDispatched(PersistEnrichmentImagesJob::class);
    }

    /**
     * @param  list<string>  $imageUrls
     */
    private function makePendingResultWithImages(array $imageUrls): EnrichmentResult
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Original Name',
        ]);

        $images = array_map(
            static fn (string $url): array => ['url' => $url, 'thumbnail' => null, 'type' => null],
            $imageUrls,
        );

        return EnrichmentResult::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'tracking_id' => (string) Str::uuid(),
            'status' => EnrichmentReviewStatus::PendingReview,
            'enriched_data' => new EnrichedProductData(
                name: 'Enriched Name',
                brand: null,
                description: null,
                classification: [],
                ingredients: [],
                images: $images,
                confidence_score: 85,
                enrichment_tier: 'high',
                field_confidence: null,
                enrichment_sources: null,
                assigned_barcode: null,
                assigned_barcode_type: null,
            ),
            'enrichment_quality' => 'full',
        ]);
    }
}
