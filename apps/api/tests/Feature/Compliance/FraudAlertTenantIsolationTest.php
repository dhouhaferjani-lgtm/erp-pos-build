<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Compliance\Domain\FraudAlert;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Section 8 (api.compliance cluster) — tenant-isolation regression coverage.
 *
 * Inventory: 5 callsites in FraudAlertController:
 *   - api.compliance.001: ::show line 86 (unscoped findOrFail)
 *   - api.compliance.002: ::assign line 108 (unscoped findOrFail)
 *   - api.compliance.003: ::dismiss line 133 (unscoped findOrFail)
 *   - api.compliance.004: ::resolve line 158 (unscoped findOrFail)
 *   - api.compliance.005: ::assign line 105 ('exists:users,id' bare validator)
 *
 * Plus 7 statistics() bare-where chains the scanner missed (each
 * `FraudAlert::where('company_id', $companyId)` count) — defense-in-depth
 * fix for the cluster invariant.
 *
 * Each test passes a tenant-A user a fraud_alert id that belongs to tenant B
 * and asserts the request is rejected (404 from a scoped findOrFail). A
 * same-tenant control accompanies each cross-tenant assertion. Two
 * structural-SQL-log invariants pin the predicate shape on show() (read
 * path) and assign() (validator + read path). The user-existence validator
 * uses ScopedExists::tenant (not tenantAndCompany — User has no
 * company_id column; users are tenant-scoped, with company membership
 * managed via UserCompanyMembership).
 */
final class FraudAlertTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private Company $companyA;

    private Company $companyB;

    private User $userA;

    private User $userB;

    private FraudAlert $alertA;

    private FraudAlert $alertB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::create([
            'name' => 'Tenant A',
            'slug' => 'tenant-a-fraud-iso',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
        $this->tenantB = Tenant::create([
            'name' => 'Tenant B',
            'slug' => 'tenant-b-fraud-iso',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->companyA = Company::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Company A',
            'legal_name' => 'Company A LLC',
            'tax_id' => 'TAX-A-FRAUD',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);
        $this->companyB = Company::create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Company B',
            'legal_name' => 'Company B LLC',
            'tax_id' => 'TAX-B-FRAUD',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantA->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantB->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->userA = User::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Alice',
            'email' => 'alice-fraud-iso@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantA->id);
        $this->userA->assignRole('admin');

        $this->userB = User::create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Bob',
            'email' => 'bob-fraud-iso@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantB->id);
        $this->userB->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->userA->id,
            'company_id' => $this->companyA->id,
            'role' => 'admin',
        ]);
        UserCompanyMembership::create([
            'user_id' => $this->userB->id,
            'company_id' => $this->companyB->id,
            'role' => 'admin',
        ]);

        $this->alertA = FraudAlert::create([
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'user_id' => $this->userA->id,
            'alert_type' => 'abandoned_drafts',
            'severity' => 'warning',
            'description' => 'Tenant A alert',
            'detected_at' => now(),
            'status' => 'open',
        ]);
        $this->alertB = FraudAlert::create([
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'user_id' => $this->userB->id,
            'alert_type' => 'abandoned_drafts',
            'severity' => 'warning',
            'description' => 'Tenant B alert',
            'detected_at' => now(),
            'status' => 'open',
        ]);
    }

    // ──────────────────────────────────────────────────────────────────
    // findOrFail-anchored route handlers (api.compliance.001-004)
    // ──────────────────────────────────────────────────────────────────

    public function test_show_rejects_cross_tenant_alert_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->getJson("/api/v1/fraud-alerts/{$this->alertB->id}");
        $cross->assertStatus(404);

        $same = $this->actingAsForTenant($this->userA, $this->companyA)
            ->getJson("/api/v1/fraud-alerts/{$this->alertA->id}");
        $same->assertStatus(200);
    }

    public function test_assign_rejects_cross_tenant_alert_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/fraud-alerts/{$this->alertB->id}/assign", [
                'assigned_to' => $this->userA->id,
            ]);
        $cross->assertStatus(404);

        $freshB = $this->alertB->fresh();
        $this->assertNotNull($freshB);
        $this->assertNull(
            $freshB->assigned_to,
            'Cross-tenant alert must NOT have been assigned.',
        );

        $same = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/fraud-alerts/{$this->alertA->id}/assign", [
                'assigned_to' => $this->userA->id,
            ]);
        $same->assertStatus(200);
    }

    public function test_dismiss_rejects_cross_tenant_alert_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/fraud-alerts/{$this->alertB->id}/dismiss", [
                'notes' => 'cross-tenant attempt',
            ]);
        $cross->assertStatus(404);

        $freshB = $this->alertB->fresh();
        $this->assertNotNull($freshB);
        $this->assertSame(
            'open',
            $freshB->status,
            'Cross-tenant alert status must remain unchanged.',
        );
    }

    public function test_resolve_rejects_cross_tenant_alert_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/fraud-alerts/{$this->alertB->id}/resolve", [
                'notes' => 'cross-tenant attempt',
            ]);
        $cross->assertStatus(404);

        $freshB = $this->alertB->fresh();
        $this->assertNotNull($freshB);
        $this->assertSame(
            'open',
            $freshB->status,
            'Cross-tenant alert status must remain unchanged.',
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // assigned_to validator (api.compliance.005 — bare exists:users,id)
    // ──────────────────────────────────────────────────────────────────

    public function test_assign_rejects_cross_tenant_assigned_to_user_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/fraud-alerts/{$this->alertA->id}/assign", [
                'assigned_to' => $this->userB->id,
            ]);
        $cross->assertStatus(422);
        $cross->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('assigned_to', $cross->json('error.errors') ?? []);

        $same = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/fraud-alerts/{$this->alertA->id}/assign", [
                'assigned_to' => $this->userA->id,
            ]);
        $same->assertStatus(200);
    }

    // ──────────────────────────────────────────────────────────────────
    // Structural-SQL-log invariants
    // ──────────────────────────────────────────────────────────────────

    public function test_show_query_includes_tenant_and_company_predicates(): void
    {
        \DB::enableQueryLog();

        $this->actingAsForTenant($this->userA, $this->companyA)
            ->getJson("/api/v1/fraud-alerts/{$this->alertA->id}")
            ->assertStatus(200);

        $log = \DB::getQueryLog();
        \DB::disableQueryLog();

        $alertQuery = null;
        foreach ($log as $entry) {
            $sql = (string) $entry['query'];
            if (
                str_contains($sql, 'from "fraud_alerts"')
                && str_contains($sql, '"id" =')
                && ! str_contains($sql, 'count(*)')
            ) {
                $alertQuery = $sql;
                break;
            }
        }

        $this->assertNotNull(
            $alertQuery,
            'FraudAlert lookup query must be captured. Log: '.json_encode(array_map(static fn ($e) => $e['query'], $log)),
        );
        $this->assertStringContainsString(
            '"tenant_id"',
            $alertQuery,
            'FraudAlert route-anchored lookup must filter by tenant_id (cluster invariant: BOTH predicates on every read anchored on a route param). Got SQL: '.$alertQuery,
        );
        $this->assertStringContainsString(
            '"company_id"',
            $alertQuery,
            'FraudAlert route-anchored lookup must also filter by company_id. Got SQL: '.$alertQuery,
        );
    }

    public function test_assign_validator_query_filters_user_by_tenant(): void
    {
        \DB::enableQueryLog();

        $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/fraud-alerts/{$this->alertA->id}/assign", [
                'assigned_to' => $this->userA->id,
            ])
            ->assertStatus(200);

        $log = \DB::getQueryLog();
        \DB::disableQueryLog();

        // The ScopedExists::tenant validator runs an `exists` query on users
        // scoped by tenant_id. Users do NOT carry company_id (users are
        // tenant-scoped; company membership flows through UserCompanyMembership).
        $usersValidationQuery = null;
        foreach ($log as $entry) {
            $sql = (string) $entry['query'];
            if (
                str_contains($sql, 'from "users"')
                && str_contains($sql, '"id" =')
                && (str_contains($sql, 'exists') || str_contains($sql, 'count(*)'))
            ) {
                $usersValidationQuery = $sql;
                break;
            }
        }

        $this->assertNotNull(
            $usersValidationQuery,
            'Users exists-validation query must be captured. Log: '.json_encode(array_map(static fn ($e) => $e['query'], $log)),
        );
        $this->assertStringContainsString(
            '"tenant_id"',
            $usersValidationQuery,
            'assign assigned_to validator must filter users by tenant_id. Got SQL: '.$usersValidationQuery,
        );
    }

    /**
     * Authenticate $user and pin the company context header to $company.
     */
    private function actingAsForTenant(User $user, Company $company): self
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($user->tenant_id);

        /** @var self */
        return $this->actingAs($user, 'sanctum')
            ->withHeader('X-Company-Id', $company->id);
    }
}
