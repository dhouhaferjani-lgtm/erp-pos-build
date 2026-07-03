<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Procurement\Application\CreateRfqData;
use App\Modules\Procurement\Application\PurchaseQuoteRequestService;
use App\Modules\Procurement\Application\UpdateRfqData;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class PurchaseQuoteRequestServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'RFQ Service Tenant',
            'slug' => 'rfq-service-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Pharmacy,
        ]);

        $this->company = Company::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'RFQ Service Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'status' => CompanyStatus::Active,
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Serum vit C 30ml',
        ]);
    }

    public function test_create_group_fans_out_one_document_per_supplier(): void
    {
        $suppliers = [
            $this->supplier('Supplier A'),
            $this->supplier('Supplier B'),
            $this->supplier('Supplier C'),
        ];

        $created = app(PurchaseQuoteRequestService::class)->createGroup(
            new CreateRfqData(
                partnerIds: array_map(fn (Partner $partner): string => $partner->id, $suppliers),
                lines: [
                    [
                        'product_id' => $this->product->id,
                        'quantity' => '12.5000',
                        'unit_price' => null,
                        'description' => 'Serum vit C 30ml',
                    ],
                ],
                validityDate: '2026-07-31',
                notes: 'Ask suppliers for current availability.',
            ),
            $this->tenant->id,
            $this->company->id,
            $this->company->currency,
        );

        $this->assertCount(3, $created);
        $this->assertCount(3, $created->pluck('document_number')->unique());
        $this->assertCount(1, $created->map(fn (Document $document): string => (string) $document->payload['rfq']['group_id'])->unique());

        foreach ($created as $index => $rfq) {
            $this->assertSame(DocumentType::PurchaseQuoteRequest, $rfq->type);
            $this->assertSame(DocumentStatus::Draft, $rfq->status);
            $this->assertSame($suppliers[$index]->id, $rfq->partner_id);
            $this->assertStringStartsWith('DP-', $rfq->document_number);
            $this->assertSame('2026-07-31', $rfq->payload['rfq']['validity_date']);
            $this->assertSame('12.5000', $rfq->lines->first()?->quantity);
            $this->assertSame('0.000', $rfq->lines->first()?->unit_price);
        }
    }

    public function test_single_supplier_group_uses_the_same_fan_out_path(): void
    {
        $supplier = $this->supplier('Only Supplier');

        $created = app(PurchaseQuoteRequestService::class)->createGroup(
            new CreateRfqData(
                partnerIds: [$supplier->id],
                lines: [
                    [
                        'product_id' => $this->product->id,
                        'quantity' => '1.0000',
                        'unit_price' => '8.250',
                        'description' => 'Serum vit C 30ml',
                    ],
                ],
                validityDate: null,
                notes: null,
            ),
            $this->tenant->id,
            $this->company->id,
            $this->company->currency,
        );

        $this->assertCount(1, $created);
        $this->assertNotEmpty($created->first()?->payload['rfq']['group_id']);
    }

    public function test_record_response_updates_prices_and_preserves_group_id(): void
    {
        $rfq = app(PurchaseQuoteRequestService::class)->createGroup(
            new CreateRfqData(
                partnerIds: [$this->supplier('Supplier A')->id],
                lines: [
                    [
                        'product_id' => $this->product->id,
                        'quantity' => '3.0000',
                        'unit_price' => null,
                        'description' => 'Serum vit C 30ml',
                    ],
                ],
                validityDate: null,
                notes: null,
            ),
            $this->tenant->id,
            $this->company->id,
            $this->company->currency,
        )->first();

        $this->assertInstanceOf(Document::class, $rfq);
        $groupId = (string) $rfq->payload['rfq']['group_id'];

        $updated = app(PurchaseQuoteRequestService::class)->recordResponse(
            $rfq->id,
            $this->tenant->id,
            $this->company->id,
            new UpdateRfqData(
                lines: [
                    [
                        'id' => $rfq->lines->first()?->id,
                        'product_id' => $this->product->id,
                        'quantity' => '3.0000',
                        'unit_price' => '9.125',
                        'description' => 'Serum vit C 30ml',
                    ],
                ],
                validityDate: '2026-08-15',
                supplierReference: 'SUP-A-99',
                leadTimeDays: 4,
            ),
        );

        $this->assertSame(DocumentStatus::Confirmed, $updated->status);
        $this->assertSame($groupId, $updated->payload['rfq']['group_id']);
        $this->assertSame('2026-08-15', $updated->payload['rfq']['validity_date']);
        $this->assertSame('SUP-A-99', $updated->payload['rfq']['supplier_reference']);
        $this->assertSame(4, $updated->payload['rfq']['lead_time_days']);
        $this->assertIsString($updated->payload['rfq']['response_recorded_at']);
        $this->assertSame('9.125', $updated->lines->first()?->unit_price);
    }

    public function test_mark_sent_confirms_draft_and_sets_sent_at(): void
    {
        $rfq = app(PurchaseQuoteRequestService::class)->createGroup(
            new CreateRfqData(
                partnerIds: [$this->supplier('Supplier A')->id],
                lines: [
                    [
                        'product_id' => $this->product->id,
                        'quantity' => '3.0000',
                        'unit_price' => null,
                        'description' => 'Serum vit C 30ml',
                    ],
                ],
                validityDate: null,
                notes: null,
            ),
            $this->tenant->id,
            $this->company->id,
            $this->company->currency,
        )->first();

        $this->assertInstanceOf(Document::class, $rfq);

        $sent = app(PurchaseQuoteRequestService::class)->markSent($rfq->id, $this->tenant->id, $this->company->id);

        $this->assertSame(DocumentStatus::Confirmed, $sent->status);
        $this->assertIsString($sent->payload['rfq']['sent_at']);
    }

    private function supplier(string $name): Partner
    {
        return Partner::create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => $name,
            'type' => PartnerType::Supplier,
        ]);
    }
}
