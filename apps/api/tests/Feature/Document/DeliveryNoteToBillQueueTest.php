<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\LocationType;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class DeliveryNoteToBillQueueTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Location $locationA;

    private Location $locationB;

    private Partner $periodicPartner;

    private Partner $standardPartner;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-08-19 12:00:00');

        $this->tenant = Tenant::create([
            'name' => 'To-bill queue tenant',
            'slug' => 'to-bill-queue-'.Str::random(8),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'To-bill queue company',
            'legal_name' => 'To-bill queue company LLC',
            'tax_id' => 'TO-BILL-QUEUE',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);
        $this->locationA = $this->location('Tunis', 'TUN', true);
        $this->locationB = $this->location('Sfax', 'SFX');

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'To-bill queue viewer',
            'email' => 'to-bill-queue-'.Str::random(8).'@example.test',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo('deliveries.view');
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
            'allowed_location_ids' => null,
        ]);
        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->periodicPartner = $this->partner('Atlas Periodic', 'ATLAS', PartnerType::Customer, true);
        $this->standardPartner = $this->partner('Bizerte Retail', 'BIZ', PartnerType::Both);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_summary_is_customer_only_location_scoped_group_paginated_and_oldest_first(): void
    {
        $oldest = $this->deliveryNote($this->periodicPartner, $this->locationA, 'DN-OLD-1', '2026-05-01', '100.000');
        $this->deliveryNote($this->periodicPartner, $this->locationA, 'DN-OLD-2', '2026-06-01', '50.000');
        $this->deliveryNote($this->standardPartner, $this->locationA, 'DN-NEW', '2026-08-09', '25.000');

        $this->deliveryNote($this->periodicPartner, $this->locationB, 'DN-WRONG-LOCATION', '2026-04-01', '900.000');
        $supplier = $this->partner('Supplier only', 'SUP', PartnerType::Supplier);
        $this->deliveryNote($supplier, $this->locationA, 'DN-SUPPLIER', '2026-03-01', '800.000');
        $this->deliveryNote($this->standardPartner, $this->locationA, 'DN-DRAFT', '2026-02-01', '700.000', DocumentStatus::Draft);
        $this->deliveryNote($this->standardPartner, $this->locationA, 'DN-INVOICED', '2026-01-01', '600.000', DocumentStatus::Confirmed, [
            'invoiced_at' => '2026-08-01T12:00:00+00:00',
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/delivery-notes/uninvoiced?'.http_build_query([
            'location_id' => $this->locationA->id,
            'page' => 1,
            'per_page' => 1,
        ]));

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.partner_id', $this->periodicPartner->id)
            ->assertJsonPath('data.0.partner_name', 'Atlas Periodic')
            ->assertJsonPath('data.0.partner_code', 'ATLAS')
            ->assertJsonPath('data.0.delivery_note_count', 2)
            ->assertJsonPath('data.0.total', '150.000')
            ->assertJsonPath('data.0.currency', 'TND')
            ->assertJsonPath('data.0.oldest_document_date', '2026-05-01')
            ->assertJsonPath('data.0.aging_bucket', '90_plus')
            ->assertJsonPath('data.0.is_periodic', true)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('scope.location_id', $this->locationA->id)
            ->assertJsonPath('scope.can_view_all_locations', true)
            ->assertJsonPath('summary.grand_total', '175.000')
            ->assertJsonPath('summary.grand_count', 2)
            ->assertJsonPath('summary.currency', 'TND');

        $this->assertDatabaseHas('documents', ['id' => $oldest->id]);
        $this->assertSame([
            ['bucket' => '0_30', 'count' => 1, 'total' => '25.000'],
            ['bucket' => '31_60', 'count' => 0, 'total' => '0.000'],
            ['bucket' => '61_90', 'count' => 0, 'total' => '0.000'],
            ['bucket' => '90_plus', 'count' => 1, 'total' => '150.000'],
        ], $response->json('summary.buckets'));
    }

    public function test_summary_filters_partner_date_and_periodic_groups_over_the_whole_result(): void
    {
        $this->deliveryNote($this->periodicPartner, $this->locationA, 'DN-PERIODIC-OLD', '2026-06-01', '100.000');
        $this->deliveryNote($this->periodicPartner, $this->locationA, 'DN-PERIODIC-IN-RANGE', '2026-08-01', '40.000');
        $this->deliveryNote($this->standardPartner, $this->locationA, 'DN-STANDARD', '2026-08-02', '25.000');

        $response = $this->actingAs($this->user)->getJson('/api/v1/delivery-notes/uninvoiced?'.http_build_query([
            'location_id' => $this->locationA->id,
            'date_from' => '2026-07-01',
            'date_to' => '2026-08-10',
            'partner_search' => 'atlas',
            'periodic_only' => 1,
        ]));

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.partner_id', $this->periodicPartner->id)
            ->assertJsonPath('data.0.delivery_note_count', 1)
            ->assertJsonPath('data.0.total', '40.000')
            ->assertJsonPath('summary.grand_total', '40.000')
            ->assertJsonPath('summary.grand_count', 1);
    }

    public function test_expanded_partner_rows_are_lazy_offset_paginated_oldest_first_and_reconcile(): void
    {
        $oldest = $this->deliveryNote($this->periodicPartner, $this->locationA, 'DN-EXPAND-OLD', '2026-05-01', '100.000');
        $newest = $this->deliveryNote($this->periodicPartner, $this->locationA, 'DN-EXPAND-NEW', '2026-06-01', '50.000');
        $this->deliveryNote($this->periodicPartner, $this->locationB, 'DN-EXPAND-WRONG-LOCATION', '2026-04-01', '900.000');

        $query = http_build_query(['location_id' => $this->locationA->id, 'page' => 1, 'per_page' => 1]);
        $summary = $this->actingAs($this->user)->getJson('/api/v1/delivery-notes/uninvoiced?'.http_build_query([
            'location_id' => $this->locationA->id,
            'page' => 1,
            'per_page' => 25,
        ]));
        $pageOne = $this->actingAs($this->user)->getJson("/api/v1/delivery-notes/uninvoiced/{$this->periodicPartner->id}?{$query}");
        $pageTwo = $this->actingAs($this->user)->getJson("/api/v1/delivery-notes/uninvoiced/{$this->periodicPartner->id}?".http_build_query([
            'location_id' => $this->locationA->id,
            'page' => 2,
            'per_page' => 1,
        ]));

        $pageOne->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $oldest->id)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('summary.count', 2)
            ->assertJsonPath('summary.total', '150.000')
            ->assertJsonPath('summary.currency', 'TND');
        $pageTwo->assertOk()
            ->assertJsonPath('data.0.id', $newest->id)
            ->assertJsonPath('meta.current_page', 2);
        $this->assertSame($summary->json('data.0.delivery_note_count'), $pageOne->json('summary.count'));
        $this->assertSame($summary->json('data.0.total'), $pageOne->json('summary.total'));
    }

    public function test_location_and_filter_validation_never_silently_widens_the_queue(): void
    {
        $foreignCompany = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Foreign queue company',
            'legal_name' => 'Foreign queue company LLC',
            'tax_id' => 'FOREIGN-QUEUE',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);
        $foreignLocation = Location::create([
            'company_id' => $foreignCompany->id,
            'name' => 'Foreign location',
            'code' => 'FOR',
            'type' => LocationType::Shop,
            'is_default' => true,
            'is_active' => true,
        ]);

        $this->actingAs($this->user)->getJson("/api/v1/delivery-notes/uninvoiced?location_id={$foreignLocation->id}")
            ->assertUnprocessable();
        $this->actingAs($this->user)->getJson('/api/v1/delivery-notes/uninvoiced?location_id=not-a-uuid')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonPath('error.errors.location_id.0', 'The location id field must be a valid UUID.');
        $this->actingAs($this->user)->getJson('/api/v1/delivery-notes/uninvoiced?partner_search=a')
            ->assertUnprocessable();

        UserCompanyMembership::query()
            ->where('user_id', $this->user->id)
            ->where('company_id', $this->company->id)
            ->update(['allowed_location_ids' => json_encode([$this->locationA->id], JSON_THROW_ON_ERROR)]);

        $this->actingAs($this->user)->getJson('/api/v1/delivery-notes/uninvoiced?location_id=all')
            ->assertUnprocessable();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function deliveryNote(
        Partner $partner,
        Location $location,
        string $number,
        string $date,
        string $total,
        DocumentStatus $status = DocumentStatus::Confirmed,
        array $payload = [],
    ): Document {
        return Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $partner->id,
            'location_id' => $location->id,
            'type' => DocumentType::DeliveryNote,
            'status' => $status,
            'document_number' => $number,
            'document_date' => $date,
            'currency' => 'TND',
            'subtotal' => $total,
            'tax_amount' => '0.000',
            'total' => $total,
            'payload' => $payload,
        ]);
    }

    private function location(string $name, string $code, bool $isDefault = false): Location
    {
        return Location::create([
            'company_id' => $this->company->id,
            'name' => $name,
            'code' => $code,
            'type' => LocationType::Shop,
            'is_default' => $isDefault,
            'is_active' => true,
        ]);
    }

    private function partner(string $name, string $code, PartnerType $type, bool $periodic = false): Partner
    {
        return Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => $name,
            'code' => $code,
            'type' => $type,
            'invoice_consolidation' => $periodic,
        ]);
    }
}
