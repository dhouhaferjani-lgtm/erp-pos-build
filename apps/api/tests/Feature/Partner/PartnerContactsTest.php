<?php

declare(strict_types=1);

namespace Tests\Feature\Partner;

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

class PartnerContactsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant-partner-contacts',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX789',
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
            'email' => 'partner-contacts-test@example.com',
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
    }

    public function test_get_partner_contacts(): void
    {
        $partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'ACME Corp',
            'type' => 'customer',
        ]);

        $contact1 = Contact::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'first_name' => 'Alice',
            'last_name' => 'Smith',
            'email' => 'alice@acme.com',
            'is_active' => true,
        ]);

        $contact2 = Contact::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'first_name' => 'Bob',
            'last_name' => 'Jones',
            'is_active' => true,
        ]);

        PartyContact::create([
            'contact_id' => $contact1->id,
            'party_id' => $partner->id,
            'job_title' => 'CEO',
            'is_primary' => true,
        ]);

        PartyContact::create([
            'contact_id' => $contact2->id,
            'party_id' => $partner->id,
            'job_title' => 'CTO',
            'is_primary' => false,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/partners/{$partner->id}/contacts");

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'first_name', 'last_name', 'full_name', 'email', 'phone', 'job_title', 'is_primary'],
                ],
                'meta',
            ]);
    }

    public function test_partner_contacts_returns_404_for_nonexistent_partner(): void
    {
        $fakeId = '00000000-0000-0000-0000-000000000000';

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/partners/{$fakeId}/contacts");

        $response->assertNotFound()
            ->assertJsonPath('error.code', 'PARTNER_NOT_FOUND');
    }

    public function test_partner_contacts_returns_empty_array_when_no_contacts(): void
    {
        $partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Solo Corp',
            'type' => 'customer',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/partners/{$partner->id}/contacts");

        $response->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
