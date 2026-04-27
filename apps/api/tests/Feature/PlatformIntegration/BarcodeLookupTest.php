<?php

declare(strict_types=1);

namespace Tests\Feature\PlatformIntegration;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\PlatformIntegration\Application\Services\BarcodeLookupService;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Coverage for BarcodeLookupService against the platform's universal
 * product-lookup endpoint (POST /api/v1/products/lookup).
 *
 * Asserts the BarcodeLookupResultData DTO's actual public surface:
 *   status, barcode, product (PlatformProductData), trackingId,
 *   suggestedProduct, errorReason — all camelCase per Spatie\LaravelData.
 *
 * Also covers UPC-12 → EAN-13 left-pad normalisation, EAN-13 check-digit
 * acceptance, the in-memory cache short-circuit, and the open-circuit
 * 'platform_unavailable' early return.
 */
class BarcodeLookupTest extends TestCase
{
    use RefreshDatabase;

    /**
     * EAN-13 with a valid check digit (Staedtler — 4006381333931). Used
     * wherever the test needs the normaliser to accept the input rather
     * than reject it as 'invalid_barcode'.
     */
    private const VALID_EAN_13 = '4006381333931';

    /** UPC-12 corresponding to {@see self::VALID_UPC_13_PADDED}. */
    private const VALID_UPC_12 = '012345678905';

    /** UPC-12 left-padded to a check-digit-valid EAN-13. */
    private const VALID_UPC_13_PADDED = '0012345678905';

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Mechanic',
            'slug' => 'test-mechanic-barcode',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Mechanic,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Mechanic Company',
            'legal_name' => 'Test Mechanic Company SARL',
            'tax_id' => 'TAX-TN-002',
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
            'name' => 'Test User',
            'email' => 'barcode-test@test.tn',
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

        config(['services.platform.url' => 'https://platform.test']);
        config(['services.platform.api_key' => 'test-api-key']);

        // BarcodeLookupService persists results to the application cache so
        // its results survive across tests in the same PHPUnit process. Flush
        // here to keep each test isolated.
        Cache::flush();
    }

    public function test_it_returns_found_status_when_barcode_matches(): void
    {
        Http::fake([
            'platform.test/api/v1/products/lookup' => Http::response([
                'status' => 'found',
                'product' => [
                    'id' => '550e8400-e29b-41d4-a716-446655440000',
                    'barcode' => self::VALID_EAN_13,
                    'name' => 'Bosch Brake Pad Set',
                    'brand' => 'Bosch',
                    'description' => 'Premium ceramic brake pads',
                    'classification' => ['category' => 'brakes'],
                    'ingredients' => [],
                    'images' => [],
                    'confidence_score' => 95,
                    'enrichment_tier' => 'gold',
                ],
            ], 200),
        ]);

        /** @var BarcodeLookupService $service */
        $service = app(BarcodeLookupService::class);
        $result = $service->lookup(self::VALID_EAN_13);

        $this->assertSame('found', $result->status);
        $this->assertSame(self::VALID_EAN_13, $result->barcode);
        $this->assertNotNull($result->product);
        $this->assertSame('Bosch Brake Pad Set', $result->product->name);
        $this->assertSame('Bosch', $result->product->brand);
        $this->assertNotNull($result->suggestedProduct);
        $this->assertSame('Bosch Brake Pad Set', $result->suggestedProduct['name']);
        $this->assertNull($result->errorReason);
    }

    public function test_it_returns_not_found_when_barcode_has_no_match(): void
    {
        Http::fake([
            'platform.test/api/v1/products/lookup' => Http::response(null, 404),
        ]);

        /** @var BarcodeLookupService $service */
        $service = app(BarcodeLookupService::class);
        $result = $service->lookup(self::VALID_EAN_13);

        $this->assertSame('not_found', $result->status);
        $this->assertSame(self::VALID_EAN_13, $result->barcode);
        $this->assertNull($result->product);
        $this->assertNull($result->errorReason);
    }

    public function test_it_normalizes_upc_to_ean(): void
    {
        Http::fake([
            'platform.test/api/v1/products/lookup' => Http::response(null, 404),
        ]);

        /** @var BarcodeLookupService $service */
        $service = app(BarcodeLookupService::class);
        $result = $service->lookup(self::VALID_UPC_12);

        $this->assertSame(self::VALID_UPC_13_PADDED, $result->barcode);

        Http::assertSent(function (Request $request): bool {
            /** @var array<string, mixed> $body */
            $body = $request->data();

            return ($body['barcode'] ?? null) === self::VALID_UPC_13_PADDED;
        });
    }

    public function test_it_caches_successful_lookups(): void
    {
        Http::fake([
            'platform.test/api/v1/products/lookup' => Http::response([
                'status' => 'found',
                'product' => [
                    'id' => '550e8400-e29b-41d4-a716-446655440000',
                    'barcode' => self::VALID_EAN_13,
                    'name' => 'Bosch Brake Pad',
                    'brand' => 'Bosch',
                    'description' => null,
                    'classification' => [],
                    'ingredients' => [],
                    'images' => [],
                    'confidence_score' => 80,
                    'enrichment_tier' => null,
                ],
            ], 200),
        ]);

        /** @var BarcodeLookupService $service */
        $service = app(BarcodeLookupService::class);

        $result1 = $service->lookup(self::VALID_EAN_13);
        $this->assertSame('found', $result1->status);

        $result2 = $service->lookup(self::VALID_EAN_13);
        $this->assertSame('found', $result2->status);

        Http::assertSentCount(1);
    }

    public function test_it_returns_error_when_platform_unavailable(): void
    {
        // Open the circuit breaker by setting the cache key the
        // PlatformHttpClient checks via Cache::has(...)
        Cache::put('platform:circuit_breaker', true, 30);

        /** @var BarcodeLookupService $service */
        $service = app(BarcodeLookupService::class);
        $result = $service->lookup(self::VALID_EAN_13);

        $this->assertSame('error', $result->status);
        $this->assertSame('platform_unavailable', $result->errorReason);
    }
}
