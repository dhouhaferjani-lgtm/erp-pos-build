<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\LocationType;
use App\Modules\Company\Domain\Location;
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
use ReflectionMethod;
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
        $stamped = [];
        foreach ([
            DeliveryNoteBillingLane::Consolidation,
            DeliveryNoteBillingLane::OrderConversion,
            DeliveryNoteBillingLane::PrePostDelivery,
        ] as $lane) {
            $stamped[$lane->value] = $this->deliveryNote('DN-'.strtoupper($lane->value), [
                'invoiced_at' => '2026-08-12T09:10:11+00:00',
                'invoice_id' => $invoice->id,
                'invoiced_via' => $lane->value,
            ]);
        }
        $unstamped = $this->deliveryNote('DN-UNSTAMPED');

        foreach ($stamped as $lane => $deliveryNote) {
            $stampedData = DocumentData::fromModel($deliveryNote, false, 3);
            $this->assertSame('2026-08-12T09:10:11+00:00', $stampedData->invoiced_at);
            $this->assertSame($invoice->id, $stampedData->invoiced_by_document_id);
            $this->assertSame('INV-PROJECTION', $stampedData->invoiced_by_document_number);
            $this->assertSame($lane, $stampedData->invoiced_via);
        }

        $unstampedData = DocumentData::fromModel($unstamped, false, 3);
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

        $reflection = new ReflectionClass($state);
        $propertyNames = array_map(fn ($property): string => $property->getName(), $reflection->getProperties());
        sort($propertyNames);
        $this->assertSame(['invoice_id', 'invoiced_at', 'invoiced_via'], $propertyNames);

        $constructor = $reflection->getConstructor();
        $this->assertNotNull($constructor);
        $constructorParameters = array_map(fn ($parameter): string => $parameter->getName(), $constructor->getParameters());
        $this->assertSame(['invoiced_at', 'invoice_id', 'invoiced_via'], $constructorParameters);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $this->assertDoesNotMatchRegularExpression('/invoice.*number|number.*invoice/i', $method->getName());
        }
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

    public function test_controller_and_compliance_service_exclude_another_tenants_rows_in_shared_connection_mode(): void
    {
        $foreignTenant = Tenant::create([
            'name' => 'Foreign billing tenant',
            'slug' => 'foreign-billing-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
        $foreignCompany = Company::create([
            'tenant_id' => $foreignTenant->id,
            'name' => 'Foreign billing company',
            'legal_name' => 'Foreign billing company LLC',
            'tax_id' => 'FOREIGN-BILLING-TAX',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);
        $current = $this->deliveryNote('DN-CURRENT-TENANT');
        $foreignCompanyRow = $this->deliveryNote('DN-FOREIGN-COMPANY', [], '10.000', $foreignCompany->id, null, '2026-08-12', DocumentStatus::Confirmed, DocumentType::DeliveryNote, $foreignTenant->id);
        $mismatchedTenantRow = $this->deliveryNote('DN-MISMATCHED-TENANT', [], '10.000', $this->company->id, null, '2026-08-12', DocumentStatus::Confirmed, DocumentType::DeliveryNote, $foreignTenant->id);

        $controller = $this->actingAs($this->user)->getJson('/api/v1/delivery-notes?uninvoiced=1');
        $serviceRows = app(UninvoicedDeliveryNoteService::class)->getUninvoicedDeliveryNotes($this->company->id);

        $controller->assertOk();
        $this->assertSame([$current->id], array_column($controller->json('data'), 'id'));
        $this->assertSame([$current->id], array_column($serviceRows, 'id'));
        $this->assertNotContains($foreignCompanyRow->id, array_column($controller->json('data'), 'id'));
        $this->assertNotContains($mismatchedTenantRow->id, array_column($serviceRows, 'id'));
    }

    /**
     * M5-terminal treasury F-1 / tenancy F-T1.
     *
     * `documents.payload` is free-form JSONB and the M1C backfill
     * (`2026_08_18_000002_create_delivery_note_billing_marks_table.php`, counter
     * `unparseable_invoice_id`) documents non-UUID `invoice_id` values as EXISTING in
     * the field — it neutralises the marker row but deliberately leaves the dirty
     * payload in place. Binding that value into the `documents.id` PostgreSQL `uuid`
     * key raises 22P02, which is a 500 on the DN read surfaces (and, because the value
     * poisons the transaction, on everything after it).
     *
     * Contract asserted here, mirroring the migration's own `safeInvoiceId()`:
     * an unparseable `invoice_id` is treated as ABSENT (no resolved invoice number),
     * while `invoiced_at` and the lane are PRESERVED so the row still reads as billed
     * — legacy/unresolved, never a 500.
     */
    public function test_a_non_uuid_payload_invoice_id_reads_as_unresolved_instead_of_500ing_the_list_and_detail_surfaces(): void
    {
        $dirtyShapes = [
            'DN-DIRTY-DOCNUM' => 'INV-2024-001',
            'DN-DIRTY-BLANK' => ' ',
            'DN-DIRTY-NUMERIC' => 123,
        ];

        $dirty = [];
        foreach ($dirtyShapes as $number => $invoiceId) {
            $dirty[$number] = $this->deliveryNote($number, [
                'invoiced_at' => '2026-08-12T09:10:11+00:00',
                'invoice_id' => $invoiceId,
                'invoiced_via' => DeliveryNoteBillingLane::Consolidation->value,
            ]);
        }

        // (a) the LIST surface — one dirty row must not take out the page for every
        //     user in the tenant.
        $list = $this->actingAs($this->user)->getJson('/api/v1/delivery-notes?page=1');
        $list->assertOk();
        $this->assertEqualsCanonicalizing(
            array_map(static fn (Document $document): string => $document->id, array_values($dirty)),
            array_column($list->json('data'), 'id'),
        );
        foreach ($list->json('data') as $row) {
            $this->assertNull($row['invoiced_by_document_number']);
            $this->assertSame('2026-08-12T09:10:11+00:00', $row['invoiced_at']);
            $this->assertSame(DeliveryNoteBillingLane::Consolidation->value, $row['invoiced_via']);
        }

        // (b) the DETAIL surface.
        foreach ($dirty as $document) {
            $detail = $this->actingAs($this->user)->getJson('/api/v1/delivery-notes/'.$document->id);
            $detail->assertOk()
                ->assertJsonPath('data.invoiced_by_document_number', null)
                ->assertJsonPath('data.invoiced_at', '2026-08-12T09:10:11+00:00')
                ->assertJsonPath('data.invoiced_via', DeliveryNoteBillingLane::Consolidation->value);
        }

        // The invoiced/uninvoiced complement is driven by `invoiced_at`, so the lane
        // stays on the billed side of the filter despite the unresolvable id.
        $invoiced = $this->actingAs($this->user)->getJson('/api/v1/delivery-notes?invoiced=1');
        $invoiced->assertOk();
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
        $location = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Aggregate location',
            'code' => 'AGG',
            'type' => LocationType::Warehouse,
            'is_default' => true,
            'is_active' => true,
        ]);
        $otherLocation = Location::create([
            'company_id' => $this->company->id,
            'name' => 'Excluded aggregate location',
            'code' => 'EXCLUDED-AGG',
            'type' => LocationType::Warehouse,
            'is_default' => false,
            'is_active' => true,
        ]);
        $otherPartner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        foreach (range(1, 55) as $number) {
            $this->deliveryNote(sprintf('DN-AGGREGATE-%03d', $number), [], (string) $number.'.125', $this->company->id, $location->id);
        }

        // Each fixture would corrupt the hand-derived 55-row / 1546.875 total if
        // the row and aggregate queries ever drift on one of these filters.
        $this->deliveryNote('DN-OTHER-PARTNER', [], '100.000', $this->company->id, $location->id, '2026-08-12', DocumentStatus::Confirmed, DocumentType::DeliveryNote, null, $otherPartner->id);
        $this->deliveryNote('DN-INVOICED', $this->stamp(DeliveryNoteBillingLane::Consolidation), '200.000', $this->company->id, $location->id);
        $this->deliveryNote('DN-OTHER-LOCATION', [], '300.000', $this->company->id, $otherLocation->id);
        $this->deliveryNote('DN-OUTSIDE-DATE', [], '400.000', $this->company->id, $location->id, '2026-08-10');
        $this->deliveryNote('INV-NOT-A-DN', [], '500.000', $this->company->id, $location->id, '2026-08-12', DocumentStatus::Confirmed, DocumentType::Invoice);
        $this->deliveryNote('DN-DRAFT', [], '600.000', $this->company->id, $location->id, '2026-08-12', DocumentStatus::Draft);

        $query = http_build_query([
            'partner_id' => $this->partner->id,
            'uninvoiced' => 1,
            'location_id' => $location->id,
            'date_from' => '2026-08-12',
            'date_to' => '2026-08-12',
            'status' => DocumentStatus::Confirmed->value,
            'with_aggregates' => 1,
        ]);

        $without = $this->actingAs($this->user)->getJson('/api/v1/delivery-notes?page=1');
        $first = $this->actingAs($this->user)->getJson('/api/v1/delivery-notes?page=1&'.$query);
        $third = $this->actingAs($this->user)->getJson('/api/v1/delivery-notes?page=3&'.$query);

        $without->assertOk()->assertJsonMissingPath('aggregates');
        $first->assertOk()->assertJsonPath('aggregates.count', 55)
            ->assertJsonPath('aggregates.total', '1546.875')
            ->assertJsonPath('aggregates.currency', 'TND')
            ->assertJsonPath('meta.total', 55);
        $third->assertOk()->assertJsonPath('aggregates.count', 55)
            ->assertJsonPath('aggregates.total', '1546.875')
            ->assertJsonPath('aggregates.currency', 'TND');
        $this->assertArrayNotHasKey('total_amount', $first->json('meta'));
    }

    public function test_index_returns_foreign_currency_rows_but_aggregates_only_company_currency_rows(): void
    {
        $companyCurrency = $this->deliveryNote('DN-TND', [], '10.125');
        $foreignCurrency = $this->deliveryNote('DN-EUR', [], '99.875', currency: 'EUR');

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/delivery-notes?page=1&with_aggregates=1');

        $response->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('aggregates.count', 1)
            ->assertJsonPath('aggregates.total', '10.125')
            ->assertJsonPath('aggregates.currency', 'TND');
        $this->assertEqualsCanonicalizing(
            [$companyCurrency->id, $foreignCurrency->id],
            array_column($response->json('data'), 'id'),
        );
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

    /** @param array<string, mixed> $payload */
    private function deliveryNote(
        string $number,
        array $payload = [],
        string $total = '10.000',
        ?string $companyId = null,
        ?string $locationId = null,
        string $documentDate = '2026-08-12',
        DocumentStatus $status = DocumentStatus::Confirmed,
        DocumentType $type = DocumentType::DeliveryNote,
        ?string $tenantId = null,
        ?string $partnerId = null,
        string $currency = 'TND',
    ): Document {
        return Document::create([
            'tenant_id' => $tenantId ?? $this->tenant->id,
            'company_id' => $companyId ?? $this->company->id,
            'partner_id' => $partnerId ?? $this->partner->id,
            'location_id' => $locationId,
            'type' => $type,
            'status' => $status,
            'document_number' => $number,
            'document_date' => $documentDate,
            'currency' => $currency,
            'total' => $total,
            'payload' => $payload,
            'fiscal_category' => FiscalCategory::fromDocumentType($type),
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
