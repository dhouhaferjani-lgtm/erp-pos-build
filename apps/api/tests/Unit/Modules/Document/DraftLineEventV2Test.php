<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Document;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Application\Services\DocumentLineTaxResolver;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Events\DraftLineAdded;
use App\Modules\Document\Domain\Events\DraftLineAddedV2;
use App\Modules\Document\Domain\Events\DraftLineModified;
use App\Modules\Document\Domain\Events\DraftLineModifiedV2;
use App\Modules\Document\Domain\Services\DocumentNumberingService;
use App\Modules\Document\Domain\Services\DocumentTotalsCalculator;
use App\Modules\Document\Domain\Services\DraftPersistenceService;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Product;
use App\Modules\Taxation\Domain\Services\TaxCalculationService;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use App\Shared\Contracts\ProductVariantLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TDD tests for DraftLineAddedV2 and DraftLineModifiedV2 events.
 *
 * Verifies that:
 * - addLine() dispatches DraftLineAddedV2 with description, notes, designationDefaultSnapshot
 * - modifyLine() with description change dispatches DraftLineModifiedV2 with new description
 * - modifyLine() with notes change dispatches DraftLineModifiedV2 with new notes
 * - Old V1 events are still dispatched alongside V2 (backward compatibility)
 */
class DraftLineEventV2Test extends TestCase
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
            'name' => 'Event V2 Test Tenant',
            'slug' => 'event-v2-test',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Event V2 Test Company',
            'legal_name' => 'Event V2 Test Company LLC',
            'tax_id' => 'EV2TAX123',
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
            'name' => 'Event V2 Partner',
            'type' => 'customer',
            'code' => 'EV2TST001',
        ]);

        $this->service = new DraftPersistenceService(
            new DocumentNumberingService,
            new DocumentTotalsCalculator(app(TaxCalculationService::class)),
            app(ProductVariantLookup::class),
            // Inherited red repaired in the P1 auto-save lane: the precision
            // contract added a 4th constructor argument to
            // DraftPersistenceService and this hand-wired test was never
            // updated, so all 6 cases died with an ArgumentCountError before
            // reaching an assertion.
            app(CurrencyScaleResolverInterface::class),
            // Same failure mode, second occurrence (N-1 gate r1 finding 1): the
            // draft path now resolves line tax through the shared
            // DocumentLineTaxResolver, which is a 5th constructor argument.
            // This hand-wired construction has to track the real signature or
            // every case here dies with an ArgumentCountError again.
            app(DocumentLineTaxResolver::class),
        );
    }

    #[Test]
    public function add_line_dispatches_draft_line_added_v2_with_description_notes_and_snapshot(): void
    {
        Event::fake();

        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Bosch Oil Filter',
        ]);

        $this->service->saveDraft(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            userId: 'user-v2-001',
            draftId: null,
            data: [
                'type' => DocumentType::Invoice->value,
                'partner_id' => $this->partner->id,
                'lines' => [
                    [
                        'product_id' => $product->id,
                        'description' => 'Custom label for Bosch Oil Filter',
                        'quantity' => 2,
                        'unit_price' => 12.00,
                        'notes' => 'Must use OEM Bosch reference',
                    ],
                ],
            ]
        );

        Event::assertDispatched(DraftLineAddedV2::class, function (DraftLineAddedV2 $event): bool {
            return $event->description === 'Custom label for Bosch Oil Filter'
                && $event->notes === 'Must use OEM Bosch reference'
                && $event->designationDefaultSnapshot === 'Bosch Oil Filter';
        });
    }

    #[Test]
    public function add_line_dispatches_draft_line_added_v2_with_null_notes_when_not_provided(): void
    {
        Event::fake();

        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'NGK Spark Plug',
        ]);

        $this->service->saveDraft(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            userId: 'user-v2-001',
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

        Event::assertDispatched(DraftLineAddedV2::class, function (DraftLineAddedV2 $event): bool {
            return $event->description === 'NGK Spark Plug'
                && $event->notes === null
                && $event->designationDefaultSnapshot === 'NGK Spark Plug';
        });
    }

    #[Test]
    public function modify_line_with_description_change_dispatches_draft_line_modified_v2(): void
    {
        // Create draft first (without Event::fake to persist to DB)
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Michelin Tyre',
        ]);

        $draft = $this->service->saveDraft(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            userId: 'user-v2-002',
            draftId: null,
            data: [
                'type' => DocumentType::Invoice->value,
                'partner_id' => $this->partner->id,
                'lines' => [
                    [
                        'product_id' => $product->id,
                        'quantity' => 4,
                        'unit_price' => 120.00,
                    ],
                ],
            ]
        );

        $lineId = $draft->lines->first()->id;

        Event::fake();

        // Now modify the description
        $this->service->saveDraft(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            userId: 'user-v2-002',
            draftId: $draft->id,
            data: [
                'lines' => [
                    [
                        'id' => $lineId,
                        'description' => 'Michelin Pilot Sport 4S — 225/45R18',
                        'quantity' => 4,
                        'unit_price' => 120.00,
                    ],
                ],
            ]
        );

        Event::assertDispatched(DraftLineModifiedV2::class, function (DraftLineModifiedV2 $event) use ($lineId): bool {
            return $event->lineId === $lineId
                && $event->description === 'Michelin Pilot Sport 4S — 225/45R18';
        });
    }

    #[Test]
    public function modify_line_with_notes_change_dispatches_draft_line_modified_v2(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Timing Belt Kit',
        ]);

        $draft = $this->service->saveDraft(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            userId: 'user-v2-003',
            draftId: null,
            data: [
                'type' => DocumentType::Invoice->value,
                'partner_id' => $this->partner->id,
                'lines' => [
                    [
                        'product_id' => $product->id,
                        'quantity' => 1,
                        'unit_price' => 85.00,
                        'notes' => 'Initial tech note',
                    ],
                ],
            ]
        );

        $lineId = $draft->lines->first()->id;

        Event::fake();

        $this->service->saveDraft(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            userId: 'user-v2-003',
            draftId: $draft->id,
            data: [
                'lines' => [
                    [
                        'id' => $lineId,
                        'quantity' => 1,
                        'unit_price' => 85.00,
                        'notes' => 'Replace with Gates OEM belt — torque to 45Nm',
                    ],
                ],
            ]
        );

        Event::assertDispatched(DraftLineModifiedV2::class, function (DraftLineModifiedV2 $event) use ($lineId): bool {
            return $event->lineId === $lineId
                && $event->notes === 'Replace with Gates OEM belt — torque to 45Nm';
        });
    }

    #[Test]
    public function add_line_still_dispatches_v1_draft_line_added_for_backward_compatibility(): void
    {
        Event::fake();

        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Air Filter',
        ]);

        $this->service->saveDraft(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            userId: 'user-v2-004',
            draftId: null,
            data: [
                'type' => DocumentType::Invoice->value,
                'partner_id' => $this->partner->id,
                'lines' => [
                    [
                        'product_id' => $product->id,
                        'quantity' => 1,
                        'unit_price' => 10.00,
                    ],
                ],
            ]
        );

        // Both V1 and V2 must be dispatched
        Event::assertDispatched(DraftLineAdded::class);
        Event::assertDispatched(DraftLineAddedV2::class);
    }

    #[Test]
    public function modify_line_still_dispatches_v1_draft_line_modified_for_backward_compatibility(): void
    {
        $product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Wiper Blade',
        ]);

        $draft = $this->service->saveDraft(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            userId: 'user-v2-005',
            draftId: null,
            data: [
                'type' => DocumentType::Invoice->value,
                'partner_id' => $this->partner->id,
                'lines' => [
                    [
                        'product_id' => $product->id,
                        'quantity' => 2,
                        'unit_price' => 15.00,
                    ],
                ],
            ]
        );

        $lineId = $draft->lines->first()->id;

        Event::fake();

        $this->service->saveDraft(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            userId: 'user-v2-005',
            draftId: $draft->id,
            data: [
                'lines' => [
                    [
                        'id' => $lineId,
                        'quantity' => 4,
                        'unit_price' => 15.00,
                    ],
                ],
            ]
        );

        // Both V1 and V2 must be dispatched
        Event::assertDispatched(DraftLineModified::class);
        Event::assertDispatched(DraftLineModifiedV2::class);
    }
}
