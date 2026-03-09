<?php

declare(strict_types=1);

namespace Tests\Feature\Contact;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
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

class PartyContactLinkingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private Contact $contact;

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant-linking',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX456',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'linking-test@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $this->user->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->contact = Contact::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'is_active' => true,
        ]);

        $this->partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'ACME Corp',
            'type' => 'customer',
        ]);
    }

    public function test_link_contact_to_party(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/contacts/{$this->contact->id}/link-party", [
                'party_id' => $this->partner->id,
                'job_title' => 'Manager',
                'is_primary' => true,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.first_name', 'John');

        $this->assertDatabaseHas('party_contacts', [
            'contact_id' => $this->contact->id,
            'party_id' => $this->partner->id,
            'job_title' => 'Manager',
            'is_primary' => true,
        ]);
    }

    public function test_unlink_contact_from_party(): void
    {
        PartyContact::create([
            'contact_id' => $this->contact->id,
            'party_id' => $this->partner->id,
            'is_primary' => false,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/contacts/{$this->contact->id}/unlink-party/{$this->partner->id}");

        $response->assertNoContent();

        $this->assertDatabaseMissing('party_contacts', [
            'contact_id' => $this->contact->id,
            'party_id' => $this->partner->id,
        ]);
    }

    public function test_primary_contact_constraint(): void
    {
        $contact2 = Contact::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'first_name' => 'Jane',
            'is_active' => true,
        ]);

        // Link first contact as primary
        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/contacts/{$this->contact->id}/link-party", [
                'party_id' => $this->partner->id,
                'is_primary' => true,
            ])
            ->assertCreated();

        // Link second contact as primary — should clear first
        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/contacts/{$contact2->id}/link-party", [
                'party_id' => $this->partner->id,
                'is_primary' => true,
            ])
            ->assertCreated();

        // First contact should no longer be primary
        $this->assertDatabaseHas('party_contacts', [
            'contact_id' => $this->contact->id,
            'party_id' => $this->partner->id,
            'is_primary' => false,
        ]);

        // Second contact should be primary
        $this->assertDatabaseHas('party_contacts', [
            'contact_id' => $contact2->id,
            'party_id' => $this->partner->id,
            'is_primary' => true,
        ]);
    }

    public function test_duplicate_link_prevention(): void
    {
        // Link contact to party
        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/contacts/{$this->contact->id}/link-party", [
                'party_id' => $this->partner->id,
            ])
            ->assertCreated();

        // Try to link same contact to same party again — should fail with unique constraint
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/contacts/{$this->contact->id}/link-party", [
                'party_id' => $this->partner->id,
            ]);

        // Should get a 500 or 422 due to unique constraint violation
        $this->assertTrue(
            in_array($response->status(), [422, 500], true),
            "Expected 422 or 500, got {$response->status()}"
        );
    }
}
