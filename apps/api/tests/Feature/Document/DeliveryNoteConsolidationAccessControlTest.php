<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\DTOs\CompanyConfig;
use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Services\CompanyConfigService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class DeliveryNoteConsolidationAccessControlTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Delivery note access tenant',
            'slug' => 'delivery-note-access-'.Str::random(8),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Mechanic,
        ]);
        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Delivery note access company',
            'legal_name' => 'Delivery note access company LLC',
            'tax_id' => 'DELIVERY-NOTE-ACCESS',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Delivery note customer',
            'type' => PartnerType::Customer,
            'email' => 'delivery-note-customer@example.test',
        ]);
    }

    public function test_role_matrix_uses_delivery_reads_and_invoice_creation_for_consolidation(): void
    {
        foreach (['operator', 'cashier', 'manager', 'viewer', 'accountant'] as $role) {
            $actor = $this->actorWithRole($role);

            $this->actingAs($actor)->getJson('/api/v1/delivery-notes/uninvoiced')
                ->assertOk()
                ->assertJsonStructure(['data']);

            $response = $this->actingAs($actor)->postJson('/api/v1/delivery-notes/consolidate-to-invoice', [
                'delivery_note_ids' => [$this->confirmedDeliveryNote('DN-'.$role)->id],
            ]);

            if (in_array($role, ['operator', 'cashier', 'manager'], true)) {
                $response->assertCreated();
            } else {
                $response->assertForbidden();
            }
        }
    }

    public function test_uninvoiced_endpoint_is_forbidden_when_sales_is_disabled_despite_delivery_read_permission(): void
    {
        $this->disableSalesModule();
        $actor = $this->actorWithRole('viewer');

        $this->actingAs($actor)->getJson('/api/v1/delivery-notes/uninvoiced')
            ->assertForbidden();
    }

    public function test_uninvoiced_endpoint_returns_only_confirmed_delivery_notes_from_the_existing_read_path(): void
    {
        $actor = $this->actorWithRole('viewer');
        $eligible = $this->confirmedDeliveryNote('DN-ELIGIBLE');
        Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::DeliveryNote,
            'status' => DocumentStatus::Draft,
            'document_number' => 'DN-DRAFT',
            'document_date' => now(),
            'currency' => 'TND',
        ]);

        $this->actingAs($actor)->getJson('/api/v1/delivery-notes/uninvoiced')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $eligible->id);
    }

    public function test_consolidation_is_forbidden_when_sales_is_disabled_despite_invoice_creation_permission(): void
    {
        $this->disableSalesModule();
        $actor = $this->actorWithRole('operator');

        $this->actingAs($actor)->postJson('/api/v1/delivery-notes/consolidate-to-invoice', [
            'delivery_note_ids' => [$this->confirmedDeliveryNote('DN-SALES-DISABLED')->id],
        ])->assertForbidden();
    }

    private function actorWithRole(string $role): User
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);

        $actor = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => ucfirst($role).' delivery-note actor',
            'email' => $role.'-'.Str::random(8).'@example.test',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $actor->assignRole($role);

        UserCompanyMembership::create([
            'user_id' => $actor->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        return $actor;
    }

    private function confirmedDeliveryNote(string $number): Document
    {
        $deliveryNote = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::DeliveryNote,
            'status' => DocumentStatus::Confirmed,
            'document_number' => $number,
            'document_date' => now(),
            'currency' => 'TND',
        ]);

        DocumentLine::create([
            'document_id' => $deliveryNote->id,
            'line_number' => 1,
            'description' => 'Delivery note line',
            'quantity' => '1.0000',
            'unit_price' => '50.000',
            'tax_rate' => '19.00',
            'line_total' => '50.000',
        ]);

        return $deliveryNote;
    }

    private function disableSalesModule(): void
    {
        $config = new CompanyConfig(
            vertical: Vertical::Mechanic,
            defaultModules: [],
            enabledExtras: [],
            compatibleExtras: [],
            allEnabledModules: [],
        );
        $service = Mockery::mock(CompanyConfigService::class);
        $service->shouldReceive('getConfigForTenant')->andReturn($config);

        $this->app->instance(CompanyConfigService::class, $service);
    }
}
