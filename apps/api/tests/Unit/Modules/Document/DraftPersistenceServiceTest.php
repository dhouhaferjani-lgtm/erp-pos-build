<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Document;

use App\Modules\Catalog\Infrastructure\Adapters\EloquentProductVariantLookup;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Services\DocumentTotalsCalculator;
use App\Modules\Document\Domain\Services\DraftPersistenceService;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Product;
use App\Modules\Service\Domain\Service;
use App\Modules\Taxation\Domain\Services\TaxCalculationService;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Unit tests for DraftPersistenceService snapshot capture behaviour.
 *
 * Verifies that:
 * - designation_default_snapshot is always set from the product/service name (server-side)
 * - description defaults to the product/service name but can be overridden
 * - notes are persisted from $lineData
 * - a client-supplied designation_default_snapshot in $lineData is ignored
 */
class DraftPersistenceServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Partner $partner;

    private DraftPersistenceService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Test Tenant DPS',
            'slug' => 'test-dps',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Company DPS',
            'legal_name' => 'Test Company DPS LLC',
            'tax_id' => 'DPSTAX123',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->partner = Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Draft Test Partner',
            'type' => 'customer',
            'code' => 'DPTST001',
        ]);

        $this->service = new DraftPersistenceService(
            new DocumentTotalsCalculator(app(TaxCalculationService::class)),
            new EloquentProductVariantLookup,
            app(CurrencyScaleResolverInterface::class),
        );
    }

    #[Test]
    public function adding_draft_line_from_product_captures_snapshot_and_description(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Oil Filter',
        ]);

        $draft = $this->service->saveDraft(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            userId: 'user-001',
            draftId: null,
            data: [
                'type' => DocumentType::Invoice->value,
                'partner_id' => $this->partner->id,
                'lines' => [
                    [
                        'product_id' => $product->id,
                        'quantity' => 1,
                        'unit_price' => 15.00,
                    ],
                ],
            ]
        );

        $line = $draft->lines->first();

        $this->assertNotNull($line, 'Draft must have at least one line');
        $this->assertSame('Oil Filter', $line->designation_default_snapshot);
        $this->assertSame('Oil Filter', $line->description);
    }

    #[Test]
    public function custom_description_overrides_default_but_snapshot_stays_product_name(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Brake Pad',
        ]);

        $draft = $this->service->saveDraft(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            userId: 'user-001',
            draftId: null,
            data: [
                'type' => DocumentType::Invoice->value,
                'partner_id' => $this->partner->id,
                'lines' => [
                    [
                        'product_id' => $product->id,
                        'description' => 'Custom brake description',
                        'quantity' => 2,
                        'unit_price' => 40.00,
                    ],
                ],
            ]
        );

        $line = $draft->lines->first();

        $this->assertNotNull($line);
        // Snapshot must ALWAYS be product name (server-side truth)
        $this->assertSame('Brake Pad', $line->designation_default_snapshot);
        // Description must respect the override
        $this->assertSame('Custom brake description', $line->description);
    }

    #[Test]
    public function adding_draft_line_from_service_captures_service_name_as_snapshot(): void
    {
        $svc = Service::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Oil Change Service',
        ]);

        $draft = $this->service->saveDraft(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            userId: 'user-001',
            draftId: null,
            data: [
                'type' => DocumentType::Invoice->value,
                'partner_id' => $this->partner->id,
                'lines' => [
                    [
                        'service_id' => $svc->id,
                        'quantity' => 1,
                        'unit_price' => 50.00,
                    ],
                ],
            ]
        );

        $line = $draft->lines->first();

        $this->assertNotNull($line);
        $this->assertSame('Oil Change Service', $line->designation_default_snapshot);
    }

    #[Test]
    public function notes_are_persisted_from_line_data(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Timing Belt',
        ]);

        $draft = $this->service->saveDraft(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            userId: 'user-001',
            draftId: null,
            data: [
                'type' => DocumentType::Invoice->value,
                'partner_id' => $this->partner->id,
                'lines' => [
                    [
                        'product_id' => $product->id,
                        'quantity' => 1,
                        'unit_price' => 80.00,
                        'notes' => 'Replace with OEM part only',
                    ],
                ],
            ]
        );

        $line = $draft->lines->first();

        $this->assertNotNull($line);
        $this->assertSame('Replace with OEM part only', $line->notes);
    }

    #[Test]
    public function client_supplied_snapshot_in_line_data_is_ignored(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Air Filter',
        ]);

        $draft = $this->service->saveDraft(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            userId: 'user-001',
            draftId: null,
            data: [
                'type' => DocumentType::Invoice->value,
                'partner_id' => $this->partner->id,
                'lines' => [
                    [
                        'product_id' => $product->id,
                        'quantity' => 1,
                        'unit_price' => 12.00,
                        // Client tries to tamper with the snapshot
                        'designation_default_snapshot' => 'Tampered snapshot value',
                    ],
                ],
            ]
        );

        $line = $draft->lines->first();

        $this->assertNotNull($line);
        // Server must ignore client-supplied snapshot and use product name
        $this->assertSame('Air Filter', $line->designation_default_snapshot);
        $this->assertNotSame('Tampered snapshot value', $line->designation_default_snapshot);
    }

    #[Test]
    public function modifying_line_description_persists_new_description(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Spark Plug',
        ]);

        // First create a draft
        $draft = $this->service->saveDraft(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            userId: 'user-001',
            draftId: null,
            data: [
                'type' => DocumentType::Invoice->value,
                'partner_id' => $this->partner->id,
                'lines' => [
                    [
                        'product_id' => $product->id,
                        'quantity' => 4,
                        'unit_price' => 8.50,
                    ],
                ],
            ]
        );

        $lineId = $draft->lines->first()->id;

        // Now modify the description via update
        $updated = $this->service->saveDraft(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            userId: 'user-001',
            draftId: $draft->id,
            data: [
                'lines' => [
                    [
                        'id' => $lineId,
                        'description' => 'NGK Spark Plug - Iridium',
                        'quantity' => 4,
                        'unit_price' => 8.50,
                    ],
                ],
            ]
        );

        $updated->refresh();
        $updated->load('lines');
        $line = $updated->lines->firstWhere('id', $lineId);

        $this->assertNotNull($line);
        $this->assertSame('NGK Spark Plug - Iridium', $line->description);
        // Snapshot remains from original product (immutable reference point)
        $this->assertSame('Spark Plug', $line->designation_default_snapshot);
    }

    #[Test]
    public function test_whitespace_only_description_is_not_persisted(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Wheel Bearing',
        ]);

        // Create draft with real description
        $draft = $this->service->saveDraft(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            userId: 'user-001',
            draftId: null,
            data: [
                'type' => DocumentType::Invoice->value,
                'partner_id' => $this->partner->id,
                'lines' => [
                    [
                        'product_id' => $product->id,
                        'quantity' => 1,
                        'unit_price' => 25.00,
                    ],
                ],
            ]
        );

        $lineId = $draft->lines->first()->id;
        $originalDescription = $draft->lines->first()->description;

        // Fake events so Spatie event-sourcing does not interfere with the
        // assertion (stored_events writes are irrelevant to the trim guard test).
        Event::fake();

        // Attempt to overwrite with whitespace-only string
        $this->service->saveDraft(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            userId: 'user-001',
            draftId: $draft->id,
            data: [
                'lines' => [
                    [
                        'id' => $lineId,
                        'description' => '   ',
                        'quantity' => 1,
                        'unit_price' => 25.00,
                    ],
                ],
            ]
        );

        // Use a raw DB query to bypass any Eloquent caching
        $rawDescription = DB::table('document_lines')
            ->where('id', $lineId)
            ->value('description');

        // Whitespace-only description must NOT overwrite the existing description
        $this->assertSame($originalDescription, $rawDescription, 'Whitespace-only input must not overwrite existing description');
        $this->assertNotSame('', $rawDescription, 'description column must never contain empty string');
    }

    #[Test]
    public function test_description_exceeding_500_chars_is_truncated_in_autosave(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Long Description Product',
        ]);

        $longDescription = str_repeat('A', 600);

        $draft = $this->service->saveDraft(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            userId: 'user-001',
            draftId: null,
            data: [
                'type' => DocumentType::Invoice->value,
                'partner_id' => $this->partner->id,
                'lines' => [
                    [
                        'product_id' => $product->id,
                        'description' => $longDescription,
                        'quantity' => 1,
                        'unit_price' => 10.00,
                    ],
                ],
            ]
        );

        $line = $draft->lines->first();

        $this->assertNotNull($line, 'Draft must have at least one line');
        $this->assertSame(500, mb_strlen((string) $line->description), 'Description exceeding 500 chars must be truncated to exactly 500 chars');
    }

    #[Test]
    public function test_line_total_is_bcmath_not_float_product(): void
    {
        // qty 3 × unit_price 0.3335 = 1.0005 exactly.
        // Float path: (string)(3 * 0.3335) = "1.0005" →
        //   Eloquent decimal:3 HALF_UP → "1.001" (wrong — rounds up the half digit).
        // BCmath path: bcformat(bcmul('3','0.3335', scale+1), scale) →
        //   truncates 1.0005 to "1.000" at EUR scale 2 → stored "1.00" →
        //   Eloquent decimal:3 returns "1.000" (correct).
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Precision Test Product',
        ]);

        $draft = $this->service->saveDraft(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            userId: 'user-precision',
            draftId: null,
            data: [
                'type' => DocumentType::Invoice->value,
                'partner_id' => $this->partner->id,
                'lines' => [
                    [
                        'product_id' => $product->id,
                        'quantity' => '3',
                        'unit_price' => '0.3335',
                    ],
                ],
            ]
        );

        $line = $draft->lines->first();
        $this->assertNotNull($line, 'Draft must have at least one line');
        // BCmath truncation: 3 × 0.3335 = 1.0005 → truncated at EUR scale 2 → "1.00"
        // → Eloquent decimal:3 cast → "1.000"
        $this->assertSame('1.000', $line->line_total);
    }

    #[Test]
    public function modifying_line_notes_persists_new_notes(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Coolant',
        ]);

        $draft = $this->service->saveDraft(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            userId: 'user-001',
            draftId: null,
            data: [
                'type' => DocumentType::Invoice->value,
                'partner_id' => $this->partner->id,
                'lines' => [
                    [
                        'product_id' => $product->id,
                        'quantity' => 2,
                        'unit_price' => 18.00,
                        'notes' => 'Initial note',
                    ],
                ],
            ]
        );

        $lineId = $draft->lines->first()->id;

        $updated = $this->service->saveDraft(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            userId: 'user-001',
            draftId: $draft->id,
            data: [
                'lines' => [
                    [
                        'id' => $lineId,
                        'quantity' => 2,
                        'unit_price' => 18.00,
                        'notes' => 'Updated: use Prestone long-life formula',
                    ],
                ],
            ]
        );

        $updated->refresh();
        $updated->load('lines');
        $line = $updated->lines->firstWhere('id', $lineId);

        $this->assertNotNull($line);
        $this->assertSame('Updated: use Prestone long-life formula', $line->notes);
    }
}
