<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Compliance\Services\UninvoicedDeliveryNoteService;
use App\Modules\Document\Application\DTOs\DeliveryNoteBillingState;
use App\Modules\Document\Application\DTOs\DocumentData;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DeliveryNoteBillingLane;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalCategory;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class DeliveryNoteBillingProjectionTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Partner $partner;

    private Tenant $tenant;

    private User $user;

    private int $createdAtSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Billing projection tenant',
            'slug' => 'billing-projection-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Billing projection company',
            'legal_name' => 'Billing projection company LLC',
            'tax_id' => 'BILLING-PROJECTION-TAX',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Billing projection user',
            'email' => 'billing-projection@example.test',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);
        $this->user->givePermissionTo('deliveries.view');
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);
        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
    }

    public function test_document_data_projects_stamped_and_unstamped_billing_state(): void
    {
        $invoice = $this->document(DocumentType::Invoice, 'INV-PROJECTION');
        $stamped = $this->deliveryNote('DN-STAMPED', [
            'invoiced_at' => '2026-08-12T09:10:11+00:00',
            'invoice_id' => $invoice->id,
            'invoiced_via' => DeliveryNoteBillingLane::Consolidation->value,
        ]);
        $unstamped = $this->deliveryNote('DN-UNSTAMPED');

        $stampedData = DocumentData::fromModel($stamped, false, 3);
        $unstampedData = DocumentData::fromModel($unstamped, false, 3);

        $this->assertSame('2026-08-12T09:10:11+00:00', $stampedData->invoiced_at);
        $this->assertSame($invoice->id, $stampedData->invoiced_by_document_id);
        $this->assertSame('INV-PROJECTION', $stampedData->invoiced_by_document_number);
        $this->assertSame('consolidation', $stampedData->invoiced_via);
        $this->assertNull($unstampedData->invoiced_at);
        $this->assertNull($unstampedData->invoiced_by_document_id);
        $this->assertNull($unstampedData->invoiced_by_document_number);
        $this->assertNull($unstampedData->invoiced_via);
    }

    public function test_document_data_defaults_a_stamped_legacy_lane_and_never_leaks_cross_company_invoice_number(): void
    {
        $foreignCompany = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Foreign company',
            'legal_name' => 'Foreign company LLC',
            'tax_id' => 'FOREIGN-COMPANY-TAX',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);
        $foreignInvoice = Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $foreignCompany->id,
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Draft,
            'document_number' => 'INV-FOREIGN',
            'document_date' => '2026-08-12',
            'currency' => 'TND',
        ]);

        $data = DocumentData::fromModel($this->deliveryNote('DN-CROSS-COMPANY', [
            'invoiced_at' => '2026-08-12T09:10:11+00:00',
            'invoice_id' => $foreignInvoice->id,
        ]), false, 3);

        $this->assertSame('legacy_unknown', $data->invoiced_via);
        $this->assertSame($foreignInvoice->id, $data->invoiced_by_document_id);
        $this->assertNull($data->invoiced_by_document_number);
    }

    public function test_billing_state_is_a_three_key_payload_surface_without_invoice_number(): void
    {
        $state = DeliveryNoteBillingState::fromPayload([
            'invoiced_at' => '2026-08-12T09:10:11+00:00',
            'invoice_id' => 'invoice-123',
            'invoiced_via' => 'order_conversion',
        ]);

        $this->assertSame([
            'invoiced_at' => '2026-08-12T09:10:11+00:00',
            'invoice_id' => 'invoice-123',
            'invoiced_via' => 'order_conversion',
        ], $state->toPayloadPatch());
        $this->assertFalse((new ReflectionClass($state))->hasProperty('invoice_number'));
    }

    public function test_uninvoiced_and_invoiced_filters_are_complements_and_match_the_compliance_service_for_all_json_shapes(): void
    {
        $absent = $this->deliveryNote('DN-ABSENT');
        $jsonNull = $this->deliveryNote('DN-JSON-NULL', ['invoiced_at' => null]);
        $this->deliveryNote('DN-CONSOLIDATION', $this->stamp(DeliveryNoteBillingLane::Consolidation));
        $this->deliveryNote('DN-ORDER', $this->stamp(DeliveryNoteBillingLane::OrderConversion));
        $this->deliveryNote('DN-PRE-POST', $this->stamp(DeliveryNoteBillingLane::PrePostDelivery));

        $uninvoiced = $this->actingAs($this->user)->getJson('/api/v1/delivery-notes?uninvoiced=1');
        $invoiced = $this->actingAs($this->user)->getJson('/api/v1/delivery-notes?invoiced=1');
        $serviceRows = app(UninvoicedDeliveryNoteService::class)->getUninvoicedDeliveryNotes($this->company->id);

        $uninvoiced->assertOk();
        $invoiced->assertOk();
        $this->assertEqualsCanonicalizing([$absent->id, $jsonNull->id], array_column($uninvoiced->json('data'), 'id'));
        $this->assertEqualsCanonicalizing([$absent->id, $jsonNull->id], array_column($serviceRows, 'id'));
        $this->assertCount(3, $invoiced->json('data'));
    }

    public function test_offset_pagination_returns_the_second_twenty_five_rows_and_cursor_mode_remains_available(): void
    {
        foreach (range(51, 1) as $number) {
            $this->deliveryNote(sprintf('DN-PAGE-%03d', $number));
        }

        $offset = $this->actingAs($this->user)->getJson('/api/v1/delivery-notes?page=2');
        $cursor = $this->actingAs($this->user)->getJson('/api/v1/delivery-notes');

        $offset->assertOk()->assertJsonCount(25, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.total', 51)
            ->assertJsonPath('meta.last_page', 3);
        $this->assertSame('DN-PAGE-026', $offset->json('data.0.document_number'));
        $this->assertSame('DN-PAGE-050', $offset->json('data.24.document_number'));
        $cursor->assertOk()->assertJsonCount(25, 'data')
            ->assertJsonPath('meta.per_page', 25)
            ->assertJsonPath('meta.has_more', true)
            ->assertJsonStructure(['links' => ['next', 'prev']]);
    }

    public function test_aggregates_are_opt_in_and_page_invariant_over_the_full_filtered_set(): void
    {
        foreach (range(1, 55) as $number) {
            $this->deliveryNote(sprintf('DN-AGGREGATE-%03d', $number), [], (string) $number.'.125');
        }

        $without = $this->actingAs($this->user)->getJson('/api/v1/delivery-notes?page=1');
        $first = $this->actingAs($this->user)->getJson('/api/v1/delivery-notes?page=1&with_aggregates=1');
        $third = $this->actingAs($this->user)->getJson('/api/v1/delivery-notes?page=3&with_aggregates=1');

        $without->assertOk()->assertJsonMissingPath('aggregates');
        $first->assertOk()->assertJsonPath('aggregates.count', 55)
            ->assertJsonPath('aggregates.total', '1546.875')
            ->assertJsonPath('aggregates.currency', 'TND');
        $third->assertOk()->assertJsonPath('aggregates.count', 55)
            ->assertJsonPath('aggregates.total', '1546.875')
            ->assertJsonPath('aggregates.currency', 'TND');
        $this->assertArrayNotHasKey('total_amount', $first->json('meta'));
    }

    /** @return array{invoiced_at: string, invoice_id: string, invoiced_via: string} */
    private function stamp(DeliveryNoteBillingLane $lane): array
    {
        return [
            'invoiced_at' => '2026-08-12T09:10:11+00:00',
            'invoice_id' => 'invoice-'.$lane->value,
            'invoiced_via' => $lane->value,
        ];
    }

    private function deliveryNote(string $number, array $payload = [], string $total = '10.000'): Document
    {
        return Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => DocumentType::DeliveryNote,
            'status' => DocumentStatus::Confirmed,
            'document_number' => $number,
            'document_date' => '2026-08-12',
            'currency' => 'TND',
            'total' => $total,
            'payload' => $payload,
            'fiscal_category' => FiscalCategory::DeliveryNote,
            'fiscal_status' => FiscalStatus::Draft,
            'created_at' => CarbonImmutable::parse('2026-08-12 12:00:00')->subSeconds($this->createdAtSequence++),
            'updated_at' => CarbonImmutable::parse('2026-08-12 12:00:00'),
        ]);
    }

    private function document(DocumentType $type, string $number): Document
    {
        return Document::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'type' => $type,
            'status' => DocumentStatus::Draft,
            'document_number' => $number,
            'document_date' => '2026-08-12',
            'currency' => 'TND',
            'fiscal_category' => FiscalCategory::fromDocumentType($type),
            'fiscal_status' => FiscalStatus::Draft,
        ]);
    }
}
