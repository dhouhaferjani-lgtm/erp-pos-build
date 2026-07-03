<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Product\Application\DTOs\EnrichedProductData;
use App\Modules\Product\Domain\EnrichmentResult;
use App\Modules\Product\Domain\Enums\EnrichmentReviewStatus;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Regression: ProductController::show eager-loads latestEnrichmentResult.
 * A latestOfMany() relation appends a MAX(<primary key>) tiebreaker, and the
 * enrichment_results primary key is a UUID — PostgreSQL has no max(uuid)
 * aggregate, so EVERY product detail request 500ed once the eager-load
 * shipped (product editor hero Phase 2). The relation must resolve the
 * latest row without aggregating the UUID key.
 */
final class ProductShowLatestEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Show Enrichment Tenant',
            'slug' => 'show-enrichment-'.Str::lower(Str::random(6)),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Show Enrichment Company',
            'legal_name' => 'Show Enrichment Company LLC',
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
            'name' => 'Show Enrichment User',
            'email' => 'show-enrichment@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => MembershipRole::Admin,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);
    }

    public function test_latest_enrichment_relation_is_not_one_of_many(): void
    {
        // The PHPUnit suite runs on SQLite, which happily aggregates any type —
        // the max(uuid) failure only reproduces on PostgreSQL. Guard the
        // regression structurally instead: one-of-many is precisely the relation
        // shape that emits the MAX(<primary key>) tiebreaker PG rejects.
        $relation = (new Product)->latestEnrichmentResult();

        $this->assertFalse(
            $relation->isOneOfMany(),
            'latestEnrichmentResult must stay an ordered hasOne (->latest()); '
            .'one-of-many aggregates MAX() over the UUID primary key, which PostgreSQL cannot do.',
        );
    }

    public function test_show_returns_latest_enrichment_result_without_uuid_aggregate(): void
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Enriched Product',
            'sku' => 'ENR-SHOW-001',
        ]);

        $trackingId = (string) Str::uuid();
        $first = $this->makeResult($product, $trackingId, version: 1, createdAt: now()->subMinute());
        $latest = $this->makeResult($product, $trackingId, version: 2, createdAt: now());

        $response = $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson("/api/v1/products/{$product->id}");

        $response->assertOk();
        $payload = $response->json('data.latest_enrichment_result');
        $this->assertNotNull($payload, 'show must expose the eager-loaded latest enrichment result');
        $this->assertSame($latest->id, $payload['id']);
        $this->assertNotSame($first->id, $payload['id']);
    }

    public function test_show_succeeds_for_product_without_enrichment_results(): void
    {
        $product = Product::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Plain Product',
            'sku' => 'ENR-SHOW-002',
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->withHeader('X-Company-Id', $this->company->id)
            ->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.latest_enrichment_result', null);
    }

    private function makeResult(Product $product, string $trackingId, int $version, \DateTimeInterface|Carbon $createdAt): EnrichmentResult
    {
        $result = EnrichmentResult::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'tracking_id' => $trackingId,
            'version' => $version,
            'status' => EnrichmentReviewStatus::PendingReview,
            'enriched_data' => new EnrichedProductData(
                name: 'Enriched Name v'.$version,
                brand: null,
                description: null,
                classification: [],
                ingredients: [],
                images: [],
                confidence_score: 80,
                enrichment_tier: 'high',
                field_confidence: null,
                enrichment_sources: null,
                assigned_barcode: null,
                assigned_barcode_type: null,
            ),
            'enrichment_quality' => 'full',
        ]);

        $result->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();

        return $result->refresh();
    }
}
