<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Document\Domain\Document;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Product;
use App\Modules\Service\Domain\Service;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Codex round-1 Finding 1: shared document Create/Update validators were
 * tenant-only (no company_id), and the shared CRUD controllers (Quote /
 * SalesOrder / Invoice / PurchaseOrder / DeliveryNote / ReturnNote) batch-
 * fetched products / services without a company predicate. A user operating
 * in Company A could submit a Company B (same-tenant) Product / Service /
 * Partner / Location / SourceDocument UUID — the validator accepted it, and
 * the unscoped batch read snapshotted the foreign-company name into the
 * Company A document line.
 *
 * Remediation:
 *   - CreateDocumentRequest validators all use ScopedExists::tenantAndCompany
 *     (or ScopedExists::company for locations which are company-only).
 *   - UpdateDocumentRequest validators tightened the same way.
 *   - All 6 controllers' Product::whereIn / Service::whereIn batch reads now
 *     scope by document tenant_id + company_id.
 */
final class SharedDocumentCrossCompanyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $companyA;

    private Company $companyB;

    private User $userA;

    private Partner $partnerA;

    private Partner $partnerB;

    private Product $productB;

    private Service $serviceB;

    protected function setUp(): void
    {
        parent::setUp();

        // Single tenant, two companies — this is the same-tenant cross-company
        // exposure Codex flagged.
        $this->tenant = Tenant::create([
            'name' => 'Shared Tenant',
            'slug' => 'shared-tenant-cross-company',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->companyA = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Company A',
            'legal_name' => 'Company A LLC',
            'tax_id' => 'TAX-A-CC',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);
        $this->companyB = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Company B',
            'legal_name' => 'Company B LLC',
            'tax_id' => 'TAX-B-CC',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->userA = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Alice',
            'email' => 'alice-cc@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->userA->assignRole('admin');

        // userA has membership in companyA only
        UserCompanyMembership::create([
            'user_id' => $this->userA->id,
            'company_id' => $this->companyA->id,
            'role' => 'admin',
        ]);

        $this->partnerA = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->companyA->id,
            'name' => 'Partner A',
            'type' => 'customer',
        ]);
        $this->partnerB = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->companyB->id,
            'name' => 'Partner B',
            'type' => 'customer',
        ]);

        $this->productB = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->companyB->id,
            'name' => 'CompanyBOnlyProductName',
        ]);
        $this->serviceB = Service::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->companyB->id,
            'name' => 'CompanyBOnlyServiceName',
        ]);
    }

    // ──────────────────────────────────────────────────────────────────
    // CreateDocumentRequest validators (Quote/SalesOrder/Invoice/PurchaseOrder
    // share this FormRequest)
    // ──────────────────────────────────────────────────────────────────

    public function test_create_quote_rejects_cross_company_partner_id(): void
    {
        $cross = $this->actingAsForCompany($this->userA, $this->companyA)
            ->postJson('/api/v1/quotes', [
                'partner_id' => $this->partnerB->id,
                'document_date' => now()->format('Y-m-d'),
                'lines' => [[
                    'description' => 'Test',
                    'quantity' => 1,
                    'unit_price' => 10,
                ]],
            ]);
        $cross->assertStatus(422);
        $errors = $this->extractErrors($cross);
        $this->assertArrayHasKey('partner_id', $errors);
    }

    public function test_create_quote_rejects_cross_company_line_product_id(): void
    {
        $cross = $this->actingAsForCompany($this->userA, $this->companyA)
            ->postJson('/api/v1/quotes', [
                'partner_id' => $this->partnerA->id,
                'document_date' => now()->format('Y-m-d'),
                'lines' => [[
                    'product_id' => $this->productB->id,
                    'description' => 'Test',
                    'quantity' => 1,
                    'unit_price' => 10,
                ]],
            ]);
        $cross->assertStatus(422);
        $errors = $this->extractErrors($cross);
        $this->assertArrayHasKey('lines.0.product_id', $errors);
    }

    public function test_create_quote_rejects_cross_company_line_service_id(): void
    {
        $cross = $this->actingAsForCompany($this->userA, $this->companyA)
            ->postJson('/api/v1/quotes', [
                'partner_id' => $this->partnerA->id,
                'document_date' => now()->format('Y-m-d'),
                'lines' => [[
                    'service_id' => $this->serviceB->id,
                    'description' => 'Test',
                    'quantity' => 1,
                    'unit_price' => 10,
                ]],
            ]);
        $cross->assertStatus(422);
        $errors = $this->extractErrors($cross);
        $this->assertArrayHasKey('lines.0.service_id', $errors);
    }

    // ──────────────────────────────────────────────────────────────────
    // Structural-SQL-log invariants — validators must filter by company_id
    // ──────────────────────────────────────────────────────────────────

    public function test_create_quote_partner_validator_query_includes_company_id_predicate(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAsForCompany($this->userA, $this->companyA)
            ->postJson('/api/v1/quotes', [
                'partner_id' => $this->partnerA->id,
                'document_date' => now()->format('Y-m-d'),
                'lines' => [[
                    'description' => 'Test',
                    'quantity' => 1,
                    'unit_price' => 10,
                ]],
            ]);

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $existsQuery = $this->findExistsQuery($log, 'partners');
        $this->assertNotNull(
            $existsQuery,
            'partners exists-validation query must be captured. Log: '
            .json_encode(array_map(static fn ($e) => $e['query'], $log)),
        );
        $this->assertStringContainsString('"tenant_id"', $existsQuery, 'partner_id validator must filter by tenant_id. SQL: '.$existsQuery);
        $this->assertStringContainsString('"company_id"', $existsQuery, 'partner_id validator must filter by company_id. SQL: '.$existsQuery);
    }

    public function test_create_quote_product_validator_query_includes_company_id_predicate(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAsForCompany($this->userA, $this->companyA)
            ->postJson('/api/v1/quotes', [
                'partner_id' => $this->partnerA->id,
                'document_date' => now()->format('Y-m-d'),
                'lines' => [[
                    'product_id' => $this->productB->id,
                    'description' => 'Test',
                    'quantity' => 1,
                    'unit_price' => 10,
                ]],
            ]);

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $existsQuery = $this->findExistsQuery($log, 'products');
        $this->assertNotNull(
            $existsQuery,
            'products exists-validation query must be captured. Log: '
            .json_encode(array_map(static fn ($e) => $e['query'], $log)),
        );
        $this->assertStringContainsString('"tenant_id"', $existsQuery, 'product_id validator must filter by tenant_id. SQL: '.$existsQuery);
        $this->assertStringContainsString('"company_id"', $existsQuery, 'product_id validator must filter by company_id. SQL: '.$existsQuery);
    }

    // ──────────────────────────────────────────────────────────────────
    // Controller batch-fetch defense (snapshot leak-prevention)
    // ──────────────────────────────────────────────────────────────────

    public function test_create_quote_controller_batch_query_includes_company_id_predicate(): void
    {
        // Use a same-company product so the validator passes; the controller
        // batch query is then exercised. The structural-SQL-log assertion
        // checks the controller's Product::query()->where(...)->whereIn shape.
        $productA = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->companyA->id,
            'name' => 'Product A',
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAsForCompany($this->userA, $this->companyA)
            ->postJson('/api/v1/quotes', [
                'partner_id' => $this->partnerA->id,
                'document_date' => now()->format('Y-m-d'),
                'lines' => [[
                    'product_id' => $productA->id,
                    'description' => 'Line 1',
                    'quantity' => 1,
                    'unit_price' => 10,
                ]],
            ]);

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        // The controller batch query targets products with `in (...)` filter.
        $batchQuery = null;
        foreach ($log as $entry) {
            $sql = (string) $entry['query'];
            if (str_contains($sql, 'from "products"') && str_contains($sql, 'in (')) {
                $batchQuery = $sql;
                break;
            }
        }

        $this->assertNotNull(
            $batchQuery,
            'products batch query must be captured. Log: '
            .json_encode(array_map(static fn ($e) => $e['query'], $log)),
        );
        $this->assertStringContainsString('"tenant_id"', $batchQuery, 'controller batch read must filter by tenant_id. SQL: '.$batchQuery);
        $this->assertStringContainsString('"company_id"', $batchQuery, 'controller batch read must filter by company_id. SQL: '.$batchQuery);
    }

    // ──────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────

    /**
     * @param  array<int, array{query: string, bindings: array<int, mixed>, time: float|null}>  $log
     */
    private function findExistsQuery(array $log, string $table): ?string
    {
        foreach ($log as $entry) {
            $sql = (string) $entry['query'];
            if (
                str_contains($sql, 'from "'.$table.'"')
                && str_contains($sql, '"id" =')
                && (str_contains($sql, 'count(*)') || str_contains($sql, 'exists'))
            ) {
                return $sql;
            }
        }

        return null;
    }

    /**
     * @param  TestResponse<\Symfony\Component\HttpFoundation\Response>  $response
     * @return array<string, mixed>
     */
    private function extractErrors(TestResponse $response): array
    {
        return $response->json('errors')
            ?? $response->json('error.errors')
            ?? [];
    }

    private function actingAsForCompany(User $user, Company $company): self
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($user->tenant_id);

        // Need to grant userA access to companyB to even reach the validator
        // when X-Company-Id is companyA — the middleware allows the request
        // because userA's membership in companyA matches the header.
        /** @var self */
        return $this->actingAs($user, 'sanctum')
            ->withHeader('X-Company-Id', $company->id);
    }
}
