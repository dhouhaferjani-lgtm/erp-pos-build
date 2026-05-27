<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Product\Domain\Product;
use App\Modules\Product\Infrastructure\Services\ProductEnrichmentQueryService;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\DTOs\PendingEnrichmentDTO;
use App\Shared\Enums\EnrichmentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductEnrichmentQueryServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private ProductEnrichmentQueryService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-enrichment-query',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Mechanic,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX123',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        $this->service = new ProductEnrichmentQueryService;
    }

    public function test_returns_pending_enrichments_as_dtos(): void
    {
        // platform_submission_id is a uuid column on PostgreSQL.
        $submissionId = (string) Str::uuid();
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'enrichment_status' => EnrichmentStatus::Pending,
            'platform_submission_id' => $submissionId,
            'updated_at' => now()->subMinutes(15),
        ]);

        $results = $this->service->findPendingEnrichments(limit: 50, staleMinutes: 10);

        $this->assertCount(1, $results);
        $dto = $results->first();
        $this->assertInstanceOf(PendingEnrichmentDTO::class, $dto);
        $this->assertSame($product->id, $dto->productId);
        $this->assertSame($submissionId, $dto->platformSubmissionId);
        $this->assertSame(EnrichmentStatus::Pending, $dto->enrichmentStatus);
    }

    public function test_includes_enriching_status(): void
    {
        Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'enrichment_status' => EnrichmentStatus::Enriching,
            'platform_submission_id' => (string) Str::uuid(),
            'updated_at' => now()->subMinutes(15),
        ]);

        $results = $this->service->findPendingEnrichments(limit: 50, staleMinutes: 10);

        $this->assertCount(1, $results);
        $this->assertSame(EnrichmentStatus::Enriching, $results->first()->enrichmentStatus);
    }

    public function test_excludes_completed_enrichments(): void
    {
        Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'enrichment_status' => EnrichmentStatus::Completed,
            'platform_submission_id' => (string) Str::uuid(),
            'updated_at' => now()->subMinutes(15),
        ]);

        $results = $this->service->findPendingEnrichments(limit: 50, staleMinutes: 10);

        $this->assertCount(0, $results);
    }

    public function test_excludes_recently_updated_products(): void
    {
        Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'enrichment_status' => EnrichmentStatus::Pending,
            'platform_submission_id' => (string) Str::uuid(),
            'updated_at' => now()->subMinutes(5), // Only 5 minutes ago
        ]);

        $results = $this->service->findPendingEnrichments(limit: 50, staleMinutes: 10);

        $this->assertCount(0, $results);
    }

    public function test_excludes_products_without_submission_id(): void
    {
        Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'enrichment_status' => EnrichmentStatus::Pending,
            'platform_submission_id' => null,
            'updated_at' => now()->subMinutes(15),
        ]);

        $results = $this->service->findPendingEnrichments(limit: 50, staleMinutes: 10);

        $this->assertCount(0, $results);
    }

    public function test_respects_limit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            Product::factory()->create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'enrichment_status' => EnrichmentStatus::Pending,
                'platform_submission_id' => (string) Str::uuid(),
                'updated_at' => now()->subMinutes(15),
            ]);
        }

        $results = $this->service->findPendingEnrichments(limit: 3, staleMinutes: 10);

        $this->assertCount(3, $results);
    }
}
