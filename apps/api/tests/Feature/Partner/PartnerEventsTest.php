<?php

declare(strict_types=1);

namespace Tests\Feature\Partner;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Events\PartnerCreated;
use App\Modules\Partner\Domain\Events\PartnerDeleted;
use App\Modules\Partner\Domain\Events\PartnerUpdated;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PartnerEventsTest extends TestCase
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
            'slug' => 'test-tenant',
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
            'email' => 'user@example.com',
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

    public function test_partner_created_event_dispatched_on_store(): void
    {
        Event::fake([PartnerCreated::class]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/partners', [
                'name' => 'ACME Corporation',
                'type' => 'customer',
                'email' => 'contact@acme.com',
                'phone' => '+33123456789',
            ]);

        $response->assertCreated();

        Event::assertDispatched(PartnerCreated::class, function (PartnerCreated $event): bool {
            return $event->name === 'ACME Corporation'
                && $event->type === 'customer'
                && $event->email === 'contact@acme.com'
                && $event->phone === '+33123456789'
                && $event->tenantId === $this->tenant->id
                && $event->companyId === $this->company->id
                && $event->getEventName() === 'partner.created';
        });
    }

    public function test_partner_updated_event_dispatched_on_update(): void
    {
        $partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Original Name',
            'type' => PartnerType::Customer,
            'email' => 'original@example.com',
        ]);

        Event::fake([PartnerUpdated::class]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/v1/partners/{$partner->id}", [
                'name' => 'Updated Name',
                'email' => 'updated@example.com',
            ]);

        $response->assertOk();

        Event::assertDispatched(PartnerUpdated::class, function (PartnerUpdated $event) use ($partner): bool {
            return $event->partnerId === $partner->id
                && $event->tenantId === $this->tenant->id
                && $event->companyId === $this->company->id
                && isset($event->changes['name'])
                && isset($event->changes['email'])
                && $event->getEventName() === 'partner.updated';
        });
    }

    public function test_partner_deleted_event_dispatched_on_destroy(): void
    {
        $partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Partner To Delete',
            'type' => PartnerType::Customer,
        ]);

        Event::fake([PartnerDeleted::class]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/partners/{$partner->id}");

        $response->assertNoContent();

        Event::assertDispatched(PartnerDeleted::class, function (PartnerDeleted $event) use ($partner): bool {
            return $event->partnerId === $partner->id
                && $event->tenantId === $this->tenant->id
                && $event->companyId === $this->company->id
                && $event->getEventName() === 'partner.deleted';
        });
    }
}
