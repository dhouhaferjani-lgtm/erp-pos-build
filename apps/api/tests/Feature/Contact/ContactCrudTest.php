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
use Tests\Traits\AssertsApiValidation;

class ContactCrudTest extends TestCase
{
    use AssertsApiValidation;
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant-contact',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
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

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test User',
            'email' => 'contact-test@example.com',
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

    public function test_create_contact_with_minimum_fields(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/contacts', [
                'first_name' => 'John',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.first_name', 'John')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'first_name',
                    'last_name',
                    'full_name',
                    'email',
                    'phone',
                    'is_active',
                    'created_at',
                ],
                'meta' => ['timestamp', 'request_id'],
            ]);

        $this->assertDatabaseHas('contacts', [
            'first_name' => 'John',
            'company_id' => $this->company->id,
            'tenant_id' => $this->tenant->id,
        ]);
    }

    public function test_create_contact_with_all_fields(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/contacts', [
                'first_name' => 'Jane',
                'last_name' => 'Doe',
                'email' => 'jane@example.com',
                'phone' => '+33612345678',
                'mobile' => '+33698765432',
                'date_of_birth' => '1990-05-15',
                'gender' => 'female',
                'national_id' => 'NID12345',
                'notes' => 'VIP customer',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.first_name', 'Jane')
            ->assertJsonPath('data.last_name', 'Doe')
            ->assertJsonPath('data.full_name', 'Jane Doe')
            ->assertJsonPath('data.email', 'jane@example.com')
            ->assertJsonPath('data.phone', '+33612345678')
            ->assertJsonPath('data.gender', 'female')
            ->assertJsonPath('data.notes', 'VIP customer');
    }

    public function test_create_contact_with_party_linking(): void
    {
        $partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'ACME Corp',
            'type' => 'customer',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/contacts', [
                'first_name' => 'Bob',
                'last_name' => 'Smith',
                'party_id' => $partner->id,
                'job_title' => 'CEO',
                'is_primary' => true,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.first_name', 'Bob');

        $contactId = $response->json('data.id');

        $this->assertDatabaseHas('party_contacts', [
            'contact_id' => $contactId,
            'party_id' => $partner->id,
            'job_title' => 'CEO',
            'is_primary' => true,
        ]);
    }

    public function test_update_contact(): void
    {
        $contact = Contact::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'first_name' => 'Original',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/contacts/{$contact->id}", [
                'first_name' => 'Updated',
                'last_name' => 'Name',
                'email' => 'updated@example.com',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.first_name', 'Updated')
            ->assertJsonPath('data.last_name', 'Name')
            ->assertJsonPath('data.email', 'updated@example.com');
    }

    public function test_delete_contact(): void
    {
        $contact = Contact::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'first_name' => 'ToDelete',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/contacts/{$contact->id}");

        $response->assertNoContent();

        $this->assertSoftDeleted('contacts', ['id' => $contact->id]);
    }

    public function test_list_contacts(): void
    {
        Contact::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'first_name' => 'Alice',
            'is_active' => true,
        ]);
        Contact::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'first_name' => 'Bob',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/contacts');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'first_name', 'full_name', 'is_active'],
                ],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);
    }

    public function test_list_contacts_with_search_filter(): void
    {
        Contact::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'first_name' => 'Alice',
            'last_name' => 'Wonder',
            'is_active' => true,
        ]);
        Contact::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'first_name' => 'Bob',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/contacts?search=Alice');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.first_name', 'Alice');
    }

    public function test_list_contacts_with_party_id_filter(): void
    {
        $partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'ACME Corp',
            'type' => 'customer',
        ]);

        $linkedContact = Contact::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'first_name' => 'Linked',
            'is_active' => true,
        ]);
        PartyContact::create([
            'contact_id' => $linkedContact->id,
            'party_id' => $partner->id,
            'is_primary' => false,
        ]);

        Contact::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'first_name' => 'Unlinked',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/contacts?party_id={$partner->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.first_name', 'Linked');
    }

    public function test_show_contact(): void
    {
        $contact = Contact::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'first_name' => 'Detail',
            'last_name' => 'Test',
            'email' => 'detail@example.com',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/contacts/{$contact->id}");

        $response->assertOk()
            ->assertJsonPath('data.first_name', 'Detail')
            ->assertJsonPath('data.last_name', 'Test')
            ->assertJsonPath('data.email', 'detail@example.com')
            ->assertJsonStructure([
                'data' => ['id', 'first_name', 'last_name', 'full_name', 'parties'],
                'meta',
            ]);
    }

    public function test_validation_errors_missing_first_name(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/contacts', []);

        $this->assertApiValidationErrors($response, ['first_name']);
    }

    public function test_validation_errors_invalid_email(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/contacts', [
                'first_name' => 'Test',
                'email' => 'not-an-email',
            ]);

        $this->assertApiValidationErrors($response, ['email']);
    }

    public function test_validation_errors_invalid_gender(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/contacts', [
                'first_name' => 'Test',
                'gender' => 'invalid',
            ]);

        $this->assertApiValidationErrors($response, ['gender']);
    }

    public function test_create_rejects_future_date_of_birth(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/contacts', [
                'first_name' => 'Test',
                'date_of_birth' => '2999-01-01',
            ]);

        $this->assertApiValidationErrors($response, ['date_of_birth']);
    }

    public function test_update_rejects_future_date_of_birth(): void
    {
        $contact = Contact::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'first_name' => 'Existing',
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/contacts/{$contact->id}", [
                'date_of_birth' => '2999-01-01',
            ]);

        $this->assertApiValidationErrors($response, ['date_of_birth']);
    }

    public function test_permission_denied_without_create_permission(): void
    {
        $viewerUser = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Viewer',
            'email' => 'viewer-contact@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        $viewerUser->assignRole('viewer');

        UserCompanyMembership::create([
            'user_id' => $viewerUser->id,
            'company_id' => $this->company->id,
            'role' => 'viewer',
        ]);

        $response = $this->actingAs($viewerUser, 'sanctum')
            ->postJson('/api/v1/contacts', [
                'first_name' => 'Test',
            ]);

        $response->assertForbidden();
    }

    public function test_not_found_for_nonexistent_contact(): void
    {
        $fakeId = '00000000-0000-0000-0000-000000000000';

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/contacts/{$fakeId}");

        $response->assertNotFound()
            ->assertJsonPath('error.code', 'CONTACT_NOT_FOUND');
    }
}
