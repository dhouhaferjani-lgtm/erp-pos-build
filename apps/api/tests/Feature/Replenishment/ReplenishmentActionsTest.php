<?php

declare(strict_types=1);

namespace Tests\Feature\Replenishment;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Inventory\Domain\StockTransfer;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Product;
use App\Modules\Replenishment\Application\DTOs\CaptureRequestData;
use App\Modules\Replenishment\Application\Services\ReplenishmentCaptureService;
use App\Modules\Replenishment\Domain\Enums\ReplenishmentChannel;
use App\Modules\Replenishment\Domain\Enums\ReplenishmentFulfillmentType;
use App\Modules\Replenishment\Domain\Enums\ReplenishmentStatus;
use App\Modules\Replenishment\Domain\ReplenishmentRequest;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class ReplenishmentActionsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $source;

    private Location $shopA;

    private Location $shopB;

    private Product $productA;

    private Product $productB;

    private Partner $supplier;

    private User $reviewer;

    private User $processorOnly;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->for($this->tenant)->create(['currency' => 'TND']);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        app(CompanyContext::class)->setCompanyId($this->company->id);
        $this->source = Location::factory()->create(['company_id' => $this->company->id, 'type' => 'warehouse']);
        $this->shopA = Location::factory()->create(['company_id' => $this->company->id, 'type' => 'shop']);
        $this->shopB = Location::factory()->create(['company_id' => $this->company->id, 'type' => 'shop']);
        $this->productA = $this->product('A', '2.125');
        $this->productB = $this->product('B', '3.250');
        $this->supplier = Partner::factory()->supplier()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $this->reviewer = $this->user('reviewer@example.test', [
            'replenishment.process',
            'inventory.transfers.create',
            'purchase-orders.create',
        ]);
        $this->processorOnly = $this->user('processor@example.test', ['replenishment.process']);
    }

    public function test_create_transfer_groups_by_destination_and_settles_via_listener(): void
    {
        $a = $this->capture($this->shopA, $this->productA);
        $b = $this->capture($this->shopB, $this->productB);
        $this->seedStock($this->productA, '5');
        $this->seedStock($this->productB, '5');

        $response = $this->action('create-transfer', [
            'source_location_id' => $this->source->id,
            'lines' => [
                ['request_id' => $a->id, 'quantity' => '2.0000'],
                ['request_id' => $b->id, 'quantity' => '1.0000'],
            ],
        ])->assertOk();

        $this->assertCount(2, $response->json('data.transfer_ids'));
        $this->assertSame(2, StockTransfer::query()->count());
        $this->assertSame(ReplenishmentStatus::Fulfilled, $a->refresh()->status);
        $this->assertSame(ReplenishmentStatus::Fulfilled, $b->refresh()->status);
    }

    public function test_create_transfer_requires_inventory_permission(): void
    {
        $request = $this->capture($this->shopA, $this->productA);

        $this->action('create-transfer', [
            'source_location_id' => $this->source->id,
            'lines' => [['request_id' => $request->id, 'quantity' => '1']],
        ], $this->processorOnly)->assertForbidden();
    }

    public function test_create_po_to_warehouse_marks_line_in_progress_with_sourcing_link(): void
    {
        $request = $this->capture($this->shopA, $this->productA);

        $response = $this->action('create-po', $this->poPayload($request, $this->source))->assertOk();

        $request->refresh();
        $this->assertSame(ReplenishmentStatus::InProgress, $request->status);
        $this->assertSame($response->json('data.document_id'), $request->sourcing_document_id);
        $this->assertNull($request->fulfillment_id);
    }

    public function test_create_po_direct_to_shop_marks_fulfilled(): void
    {
        $request = $this->capture($this->shopA, $this->productA);

        $response = $this->action('create-po', $this->poPayload($request, $this->shopA))->assertOk();

        $request->refresh();
        $this->assertSame(ReplenishmentStatus::Fulfilled, $request->status);
        $this->assertSame(ReplenishmentFulfillmentType::PurchaseOrder, $request->fulfillment_type);
        $this->assertSame($response->json('data.document_id'), $request->fulfillment_id);
    }

    public function test_create_po_appends_to_existing_draft_for_same_supplier(): void
    {
        $firstRequest = $this->capture($this->shopA, $this->productA);
        $documentId = $this->action('create-po', $this->poPayload($firstRequest, $this->source))
            ->assertOk()->json('data.document_id');
        $secondRequest = $this->capture($this->shopB, $this->productB);

        $this->action('create-po', [
            ...$this->poPayload($secondRequest, $this->source),
            'existing_document_id' => $documentId,
        ])->assertOk()->assertJsonPath('data.document_id', $documentId);

        $document = Document::query()->findOrFail($documentId);
        $this->assertCount(2, $document->lines);
    }

    public function test_create_po_requires_purchase_orders_create_permission(): void
    {
        $request = $this->capture($this->shopA, $this->productA);

        $this->action('create-po', $this->poPayload($request, $this->source), $this->processorOnly)
            ->assertForbidden();
    }

    public function test_po_lines_are_stamped_with_request_location(): void
    {
        $request = $this->capture($this->shopA, $this->productA);
        $documentId = $this->action('create-po', $this->poPayload($request, $this->source))
            ->assertOk()->json('data.document_id');

        $line = Document::query()->findOrFail($documentId)->lines()->sole();
        $this->assertSame($this->shopA->id, $line->location_id);
    }

    public function test_po_append_continues_numbering_and_recomputes_totals(): void
    {
        $first = $this->capture($this->shopA, $this->productA);
        $documentId = $this->action('create-po', $this->poPayload($first, $this->source, '2'))
            ->assertOk()->json('data.document_id');
        $second = $this->capture($this->shopB, $this->productB);
        $this->action('create-po', [
            ...$this->poPayload($second, $this->source, '3'),
            'existing_document_id' => $documentId,
        ])->assertOk();

        $document = Document::query()->findOrFail($documentId)->load('lines');
        $this->assertSame([1, 2], $document->lines->pluck('line_number')->all());
        $subtotal = $document->lines->reduce(
            fn (string $carry, $line): string => bcadd($carry, $line->line_total, 3),
            '0.000',
        );
        $tax = $document->lines->reduce(
            fn (string $carry, $line): string => bcadd($carry, $line->tax_amount ?? '0', 3),
            '0.000',
        );
        $this->assertSame($subtotal, $document->subtotal);
        $this->assertSame($tax, $document->tax_amount);
        $this->assertSame(bcadd($subtotal, $tax, 3), $document->total);
    }

    public function test_po_append_rejects_other_company_non_draft_or_other_supplier(): void
    {
        $request = $this->capture($this->shopA, $this->productA);
        $otherSupplier = Partner::factory()->supplier()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $base = Document::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'partner_id' => $otherSupplier->id,
            'location_id' => $this->source->id,
            'type' => DocumentType::PurchaseOrder,
            'status' => DocumentStatus::Draft,
        ]);
        $this->action('create-po', [
            ...$this->poPayload($request, $this->source),
            'existing_document_id' => $base->id,
        ])->assertUnprocessable();

        $base->update(['partner_id' => $this->supplier->id, 'status' => DocumentStatus::Confirmed]);
        $this->action('create-po', [
            ...$this->poPayload($request, $this->source),
            'existing_document_id' => $base->id,
        ])->assertUnprocessable();

        $otherCompany = Company::factory()->for($this->tenant)->create();
        $base->update(['company_id' => $otherCompany->id, 'status' => DocumentStatus::Draft]);
        $this->action('create-po', [
            ...$this->poPayload($request, $this->source),
            'existing_document_id' => $base->id,
        ])->assertUnprocessable();
    }

    public function test_multi_destination_transfer_uses_distinct_group_idempotency_keys(): void
    {
        $a = $this->capture($this->shopA, $this->productA);
        $b = $this->capture($this->shopB, $this->productB);
        $this->seedStock($this->productA, '2');
        $this->seedStock($this->productB, '2');

        $this->action('create-transfer', [
            'source_location_id' => $this->source->id,
            'lines' => [
                ['request_id' => $a->id, 'quantity' => '1'],
                ['request_id' => $b->id, 'quantity' => '1'],
            ],
        ])->assertOk();

        $this->assertSame(2, StockTransfer::query()->distinct()->count('idempotency_key'));
    }

    public function test_reject_requires_reason_and_closes_lines(): void
    {
        $request = $this->capture($this->shopA, $this->productA);
        $this->action('reject', ['request_ids' => [$request->id], 'reason' => 'Not stocked'])
            ->assertOk();
        $request->refresh();
        $this->assertSame(ReplenishmentStatus::Rejected, $request->status);
        $this->assertSame('Not stocked', $request->rejection_reason);

        $second = $this->capture($this->shopA, $this->productA);
        $this->action('reject', ['request_ids' => [$second->id], 'reason' => ''])
            ->assertUnprocessable();
    }

    public function test_actions_reject_non_open_or_foreign_company_lines(): void
    {
        $closed = $this->capture($this->shopA, $this->productA);
        $closed->update(['status' => ReplenishmentStatus::Cancelled]);
        $this->action('reject', ['request_ids' => [$closed->id], 'reason' => 'No'])
            ->assertUnprocessable()
            ->assertJsonPath('error.errors.request_ids.0', $closed->id);

        $otherCompany = Company::factory()->for($this->tenant)->create();
        $otherLocation = Location::factory()->create(['company_id' => $otherCompany->id]);
        $otherProduct = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
        ]);
        $foreign = app(ReplenishmentCaptureService::class)->capture(new CaptureRequestData(
            tenantId: $this->tenant->id,
            companyId: $otherCompany->id,
            locationId: $otherLocation->id,
            productId: $otherProduct->id,
            variantId: null,
            requestedQty: null,
            note: null,
            requestedByUserId: $this->reviewer->id,
            channel: ReplenishmentChannel::Web,
        ));
        $this->action('reject', ['request_ids' => [$foreign->id], 'reason' => 'No'])
            ->assertUnprocessable()
            ->assertJsonPath('error.errors.request_ids.0', $foreign->id);
    }

    private function product(string $suffix, string $purchasePrice): Product
    {
        return Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => "Product {$suffix}",
            'purchase_price' => $purchasePrice,
            'tax_rate' => '0.00',
        ]);
    }

    /** @param list<string> $permissions */
    private function user(string $email, array $permissions): User
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id, 'email' => $email]);
        $user->givePermissionTo($permissions);
        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role' => 'manager',
            'status' => 'active',
        ]);

        return $user;
    }

    private function capture(Location $location, Product $product): ReplenishmentRequest
    {
        return app(ReplenishmentCaptureService::class)->capture(new CaptureRequestData(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            locationId: $location->id,
            productId: $product->id,
            variantId: null,
            requestedQty: '1',
            note: null,
            requestedByUserId: $this->reviewer->id,
            channel: ReplenishmentChannel::Web,
        ));
    }

    private function seedStock(Product $product, string $quantity): void
    {
        app(StockAdjustmentService::class)->receive(
            productId: $product->id,
            locationId: $this->source->id,
            quantity: $quantity,
            reference: 'SEED',
            userId: $this->reviewer->id,
            expectedCompanyId: $this->company->id,
        );
    }

    /** @return array{supplier_id: string, destination_location_id: string, lines: list<array{request_id: string, quantity: string}>} */
    private function poPayload(ReplenishmentRequest $request, Location $destination, string $quantity = '1'): array
    {
        return [
            'supplier_id' => $this->supplier->id,
            'destination_location_id' => $destination->id,
            'lines' => [['request_id' => $request->id, 'quantity' => $quantity]],
        ];
    }

    private function action(string $action, array $payload, ?User $user = null): TestResponse
    {
        return $this->actingAs($user ?? $this->reviewer)
            ->withHeader('X-Company-Id', $this->company->id)
            ->postJson("/api/v1/replenishment-requests/actions/{$action}", $payload);
    }
}
