<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Application\Listeners\SendEnrichmentNotificationListener;
use App\Modules\Identity\Application\Notifications\EnrichmentCompletedNotification;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Events\EnrichmentResultReadyEvent;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SendEnrichmentNotificationListenerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-notification',
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

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_sends_notification_to_users_with_permission(): void
    {
        Notification::fake();

        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Enrichment Viewer',
            'email' => 'viewer@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);

        $user->givePermissionTo('enrichment.view');

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role' => 'manager',
            'status' => 'active',
        ]);

        $event = new EnrichmentResultReadyEvent(
            enrichmentResultId: 'enrich-001',
            companyId: $this->company->id,
            productId: 'prod-001',
            productName: 'Brake Pads',
            enrichmentQuality: 'full',
            assignedBarcode: '3017620422003',
        );

        $listener = new SendEnrichmentNotificationListener;
        $listener->handle($event);

        Notification::assertSentTo($user, EnrichmentCompletedNotification::class);
    }

    public function test_does_not_notify_users_without_permission(): void
    {
        Notification::fake();

        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'No Permission User',
            'email' => 'noperm@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);

        // No enrichment.view permission
        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role' => 'manager',
            'status' => 'active',
        ]);

        $event = new EnrichmentResultReadyEvent(
            enrichmentResultId: 'enrich-002',
            companyId: $this->company->id,
            productId: 'prod-002',
            productName: 'Oil Filter',
            enrichmentQuality: 'partial',
            assignedBarcode: null,
        );

        $listener = new SendEnrichmentNotificationListener;
        $listener->handle($event);

        Notification::assertNothingSent();
    }

    public function test_does_not_notify_users_from_other_company(): void
    {
        Notification::fake();

        $otherCompany = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Other Company',
            'legal_name' => 'Other LLC',
            'tax_id' => 'TAX456',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        $user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Other Company User',
            'email' => 'other@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);

        $user->givePermissionTo('enrichment.view');

        // Membership in OTHER company, not the one from the event
        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $otherCompany->id,
            'role' => 'manager',
            'status' => 'active',
        ]);

        $event = new EnrichmentResultReadyEvent(
            enrichmentResultId: 'enrich-003',
            companyId: $this->company->id, // Different company
            productId: 'prod-003',
            productName: 'Air Filter',
            enrichmentQuality: 'full',
            assignedBarcode: null,
        );

        $listener = new SendEnrichmentNotificationListener;
        $listener->handle($event);

        Notification::assertNothingSent();
    }
}
