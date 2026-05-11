<?php

declare(strict_types=1);

namespace Tests\Feature\Contact;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Contact\Domain\Contact;
use App\Modules\Contact\Domain\PartyContact;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Section 8 (api.contact cluster) — tenant-isolation regression coverage.
 *
 * The api.contact cluster has 2 inventoried bare-exists callsites
 * (CreateContactRequest::rules + ContactController::linkParty inline) plus
 * 5 controller bare-where chains the scanner missed:
 *   - ContactController::show / update / destroy / linkParty / unlinkParty
 * Each Contact lookup currently filters by company_id only and anchors on a
 * route-supplied id. Per the cluster invariant Codex established in Treasury
 * round-3 Finding 14, every read whose anchor came from a route param MUST
 * carry BOTH `tenant_id` AND `company_id` predicates.
 *
 * Each test passes a tenant-A user a resource id that belongs to tenant B and
 * asserts the request is rejected (typically 422 from validation, 404 from a
 * scoped lookup). A same-tenant control assertion accompanies each cross-tenant
 * assertion so a "passes-for-the-wrong-reason" never slips through.
 *
 * The two structural-SQL-log invariant tests pin the SQL shape rather than
 * just behavior — UUID uniqueness can mask data-level leaks; structural tests
 * catch them. Pattern mirrored from Treasury round-5
 * (TreasuryTenantIsolationTest::test_get_open_invoices_filters_partner_and_document_by_tenant_id).
 *
 * Cross-references the inventory at:
 *   docs/superpowers/plans/tenant-isolation-sweep-inventory.yml
 *   (api.contact.001 = CreateContactRequest, api.contact.002 = ContactController::linkParty)
 */
final class ContactTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private Company $companyA;

    private Company $companyB;

    private User $userA;

    private User $userB;

    private Contact $contactA;

    private Contact $contactB;

    private Partner $partnerA;

    private Partner $partnerB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::create([
            'name' => 'Tenant A',
            'slug' => 'tenant-a-contact-iso',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
        $this->tenantB = Tenant::create([
            'name' => 'Tenant B',
            'slug' => 'tenant-b-contact-iso',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->companyA = Company::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Company A',
            'legal_name' => 'Company A LLC',
            'tax_id' => 'TAX-A-CONTACT',
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
            'tax_id' => 'TAX-B-CONTACT',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        // Seed permissions for both tenants. Spatie team scoping requires
        // the registrar's team id be set BEFORE seeding so roles/permissions
        // land on the right tenant_id.
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantA->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantB->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->userA = User::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Alice',
            'email' => 'alice-contact-iso@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantA->id);
        $this->userA->assignRole('admin');

        $this->userB = User::create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Bob',
            'email' => 'bob-contact-iso@example.com',
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

        $this->contactA = Contact::create([
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'first_name' => 'Alice-Contact',
            'is_active' => true,
        ]);
        $this->contactB = Contact::create([
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'first_name' => 'Bob-Contact',
            'is_active' => true,
        ]);

        $this->partnerA = Partner::create([
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'name' => 'Partner A',
            'type' => 'customer',
        ]);
        $this->partnerB = Partner::create([
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'name' => 'Partner B',
            'type' => 'customer',
        ]);
    }

    // ──────────────────────────────────────────────────────────────────
    // FormRequest path — CreateContactRequest party_id validator
    // (api.contact.001)
    // ──────────────────────────────────────────────────────────────────

    public function test_store_rejects_cross_tenant_party_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/contacts', [
                'first_name' => 'Cross-Tenant',
                'party_id' => $this->partnerB->id,
            ]);
        $cross->assertStatus(422);
        $cross->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('party_id', $cross->json('error.errors') ?? []);

        $same = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/contacts', [
                'first_name' => 'Same-Tenant',
                'party_id' => $this->partnerA->id,
            ]);
        $same->assertStatus(201);
    }

    // ──────────────────────────────────────────────────────────────────
    // Inline-validate path — ContactController::linkParty party_id validator
    // (api.contact.002)
    // ──────────────────────────────────────────────────────────────────

    public function test_link_party_rejects_cross_tenant_party_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/contacts/{$this->contactA->id}/link-party", [
                'party_id' => $this->partnerB->id,
            ]);
        $cross->assertStatus(422);
        $cross->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('party_id', $cross->json('error.errors') ?? []);

        $same = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/contacts/{$this->contactA->id}/link-party", [
                'party_id' => $this->partnerA->id,
            ]);
        $same->assertStatus(201);
    }

    // ──────────────────────────────────────────────────────────────────
    // Bare-where blind spots — Contact route-anchored lookups
    // ──────────────────────────────────────────────────────────────────

    public function test_show_rejects_cross_tenant_contact_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->getJson("/api/v1/contacts/{$this->contactB->id}");
        $cross->assertStatus(404);
        $cross->assertJsonPath('error.code', 'CONTACT_NOT_FOUND');

        $same = $this->actingAsForTenant($this->userA, $this->companyA)
            ->getJson("/api/v1/contacts/{$this->contactA->id}");
        $same->assertStatus(200);
        $same->assertJsonPath('data.id', $this->contactA->id);
    }

    public function test_update_rejects_cross_tenant_contact_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->patchJson("/api/v1/contacts/{$this->contactB->id}", [
                'first_name' => 'Hijacked',
            ]);
        $cross->assertStatus(404);
        $cross->assertJsonPath('error.code', 'CONTACT_NOT_FOUND');

        $freshB = $this->contactB->fresh();
        $this->assertNotNull($freshB, 'Cross-tenant contact must still exist.');
        $this->assertSame(
            'Bob-Contact',
            $freshB->first_name,
            'Cross-tenant contact must remain unchanged.',
        );

        $same = $this->actingAsForTenant($this->userA, $this->companyA)
            ->patchJson("/api/v1/contacts/{$this->contactA->id}", [
                'first_name' => 'Renamed',
            ]);
        $same->assertStatus(200);
    }

    public function test_destroy_rejects_cross_tenant_contact_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->deleteJson("/api/v1/contacts/{$this->contactB->id}");
        $cross->assertStatus(404);
        $cross->assertJsonPath('error.code', 'CONTACT_NOT_FOUND');

        $this->assertNotNull(
            $this->contactB->fresh(),
            'Cross-tenant contact must NOT have been soft-deleted.',
        );

        $same = $this->actingAsForTenant($this->userA, $this->companyA)
            ->deleteJson("/api/v1/contacts/{$this->contactA->id}");
        $same->assertStatus(204);
    }

    public function test_link_party_rejects_cross_tenant_contact_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/contacts/{$this->contactB->id}/link-party", [
                'party_id' => $this->partnerA->id,
            ]);
        $cross->assertStatus(404);
        $cross->assertJsonPath('error.code', 'CONTACT_NOT_FOUND');

        $this->assertDatabaseMissing('party_contacts', [
            'contact_id' => $this->contactB->id,
        ]);
    }

    public function test_unlink_party_rejects_cross_tenant_contact_id(): void
    {
        // Pre-seed a same-tenant party_contact link for tenant-B's contact so
        // the cross-tenant unlink request would have something to delete IF
        // the lookup were unscoped. The failure mode the test guards against:
        // tenant-A's user reaching into tenant-B and deleting tenant-B's
        // join row.
        PartyContact::create([
            'contact_id' => $this->contactB->id,
            'party_id' => $this->partnerB->id,
            'is_primary' => false,
        ]);

        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->deleteJson("/api/v1/contacts/{$this->contactB->id}/unlink-party/{$this->partnerB->id}");
        $cross->assertStatus(404);
        $cross->assertJsonPath('error.code', 'CONTACT_NOT_FOUND');

        $this->assertDatabaseHas('party_contacts', [
            'contact_id' => $this->contactB->id,
            'party_id' => $this->partnerB->id,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────
    // Structural-SQL-log invariants
    //   The runtime SQL anchor cluster invariant: BOTH tenant_id AND
    //   company_id literals must appear in the WHERE clause for any read
    //   whose anchor came from a route param. This is the bar-raising
    //   pattern from Treasury round-5 — pins the SQL shape, not just behavior.
    // ──────────────────────────────────────────────────────────────────

    public function test_show_query_includes_tenant_and_company_predicates(): void
    {
        \DB::enableQueryLog();

        $this->actingAsForTenant($this->userA, $this->companyA)
            ->getJson("/api/v1/contacts/{$this->contactA->id}")
            ->assertStatus(200);

        $log = \DB::getQueryLog();
        \DB::disableQueryLog();

        $contactQuery = null;
        foreach ($log as $entry) {
            $sql = (string) $entry['query'];
            if (
                str_contains($sql, 'from "contacts"')
                && str_contains($sql, '"id" =')
                && ! str_contains($sql, 'count(*)')
            ) {
                $contactQuery = $sql;
                break;
            }
        }

        $this->assertNotNull(
            $contactQuery,
            'Contact lookup query must be captured. Log: '.json_encode(array_map(static fn ($e) => $e['query'], $log)),
        );
        $this->assertStringContainsString(
            '"tenant_id"',
            $contactQuery,
            'Contact route-anchored lookup must filter by tenant_id (cluster invariant: BOTH predicates on every read anchored on a route param). Got SQL: '.$contactQuery,
        );
        $this->assertStringContainsString(
            '"company_id"',
            $contactQuery,
            'Contact route-anchored lookup must also filter by company_id. Got SQL: '.$contactQuery,
        );
    }

    public function test_link_party_validator_query_includes_tenant_and_company_predicates(): void
    {
        \DB::enableQueryLog();

        $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/contacts/{$this->contactA->id}/link-party", [
                'party_id' => $this->partnerA->id,
            ])
            ->assertStatus(201);

        $log = \DB::getQueryLog();
        \DB::disableQueryLog();

        // The ScopedExists validator runs an `exists` query on partners
        // scoped by tenant_id + company_id.
        $partnersValidationQuery = null;
        foreach ($log as $entry) {
            $sql = (string) $entry['query'];
            if (
                str_contains($sql, 'from "partners"')
                && str_contains($sql, '"id" =')
                && (str_contains($sql, 'exists') || str_contains($sql, 'count(*)'))
            ) {
                $partnersValidationQuery = $sql;
                break;
            }
        }

        $this->assertNotNull(
            $partnersValidationQuery,
            'Partners exists-validation query must be captured. Log: '.json_encode(array_map(static fn ($e) => $e['query'], $log)),
        );
        $this->assertStringContainsString(
            '"tenant_id"',
            $partnersValidationQuery,
            'linkParty party_id validator must filter by tenant_id. Got SQL: '.$partnersValidationQuery,
        );
        $this->assertStringContainsString(
            '"company_id"',
            $partnersValidationQuery,
            'linkParty party_id validator must filter by company_id. Got SQL: '.$partnersValidationQuery,
        );
    }

    /**
     * Authenticate `$user` and pin the company context header to `$company`.
     * Mirrors the Treasury isolation test helper.
     */
    private function actingAsForTenant(User $user, Company $company): self
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($user->tenant_id);

        /** @var self */
        return $this->actingAs($user, 'sanctum')
            ->withHeader('X-Company-Id', $company->id);
    }
}
