<?php

declare(strict_types=1);

namespace Tests\Feature\Replenishment;

use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Application\DTOs\InitiateTransferData;
use App\Modules\Inventory\Application\DTOs\InitiateTransferLineData;
use App\Modules\Inventory\Application\Services\StockTransferService;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Inventory\Domain\StockTransfer;
use App\Modules\Product\Domain\Product;
use App\Modules\Replenishment\Application\DTOs\CaptureRequestData;
use App\Modules\Replenishment\Application\Services\ReplenishmentCaptureService;
use App\Modules\Replenishment\Domain\Enums\ReplenishmentChannel;
use App\Modules\Replenishment\Domain\Enums\ReplenishmentFulfillmentType;
use App\Modules\Replenishment\Domain\Enums\ReplenishmentStatus;
use App\Modules\Replenishment\Domain\ReplenishmentRequest;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ReplenishmentSettlementTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $source;

    private Location $destination;

    private Location $otherDestination;

    private User $user;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->for($this->tenant)->create();
        app(CompanyContext::class)->setCompanyId($this->company->id);
        $this->source = Location::factory()->create(['company_id' => $this->company->id]);
        $this->destination = Location::factory()->create(['company_id' => $this->company->id]);
        $this->otherDestination = Location::factory()->create(['company_id' => $this->company->id]);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
    }

    public function test_initiated_transfer_settles_matching_pending_and_in_progress_lines(): void
    {
        $pending = $this->capture($this->product);
        $secondProduct = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $inProgress = $this->capture($secondProduct);
        $inProgress->update(['status' => ReplenishmentStatus::InProgress]);

        $transfer = $this->initiate([
            new InitiateTransferLineData(productId: $this->product->id, quantity: '2'),
            new InitiateTransferLineData(productId: $secondProduct->id, quantity: '1'),
        ]);

        foreach ([$pending, $inProgress] as $request) {
            $request->refresh();
            $this->assertSame(ReplenishmentStatus::Fulfilled, $request->status);
            $this->assertSame(ReplenishmentFulfillmentType::Transfer, $request->fulfillment_type);
            $this->assertSame($transfer->id, $request->fulfillment_id);
            $this->assertSame($this->user->id, $request->processed_by_user_id);
        }
    }

    public function test_settlement_matches_variant_grain_exactly(): void
    {
        $variant = ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
        ]);
        $productGrain = $this->capture($this->product);
        $variantGrain = $this->capture($this->product, $variant->id);

        $transfer = $this->initiate([
            new InitiateTransferLineData(
                productId: $this->product->id,
                quantity: '1',
                variantId: $variant->id,
            ),
        ]);

        $this->assertSame(ReplenishmentStatus::Pending, $productGrain->refresh()->status);
        $this->assertSame(ReplenishmentStatus::Fulfilled, $variantGrain->refresh()->status);
        $this->assertSame($transfer->id, $variantGrain->fulfillment_id);
    }

    public function test_settlement_ignores_other_company_and_other_destination(): void
    {
        $otherDestinationRequest = $this->capture($this->product, location: $this->otherDestination);
        $otherCompany = Company::factory()->for($this->tenant)->create();
        $otherLocation = Location::factory()->create(['company_id' => $otherCompany->id]);
        $otherProduct = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
        ]);
        $otherCompanyRequest = app(ReplenishmentCaptureService::class)->capture(new CaptureRequestData(
            tenantId: $this->tenant->id,
            companyId: $otherCompany->id,
            locationId: $otherLocation->id,
            productId: $otherProduct->id,
            variantId: null,
            requestedQty: null,
            note: null,
            requestedByUserId: $this->user->id,
            channel: ReplenishmentChannel::Web,
        ));

        $this->initiate([new InitiateTransferLineData(productId: $this->product->id, quantity: '1')]);

        $this->assertSame(ReplenishmentStatus::Pending, $otherDestinationRequest->refresh()->status);
        $this->assertSame(ReplenishmentStatus::Pending, $otherCompanyRequest->refresh()->status);
    }

    public function test_unrelated_transfer_is_a_noop(): void
    {
        $request = $this->capture($this->product);
        $otherProduct = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);

        $this->initiate([new InitiateTransferLineData(productId: $otherProduct->id, quantity: '1')]);

        $this->assertSame(ReplenishmentStatus::Pending, $request->refresh()->status);
    }

    public function test_cancelled_transfer_reopens_line(): void
    {
        $request = $this->capture($this->product);
        $transfer = $this->initiate([new InitiateTransferLineData(productId: $this->product->id, quantity: '1')]);
        $this->assertSame(ReplenishmentStatus::Fulfilled, $request->refresh()->status);

        app(StockTransferService::class)->cancel($transfer, $this->user->id, 'Vehicle breakdown');

        $request->refresh();
        $this->assertSame(ReplenishmentStatus::Pending, $request->status);
        $this->assertNull($request->fulfillment_type);
        $this->assertNull($request->fulfillment_id);
        $this->assertStringContainsString("transfer {$transfer->transfer_number} cancelled", (string) $request->note);
    }

    public function test_cancelled_transfer_reopen_collision_merges_into_new_open_line(): void
    {
        $fulfilled = $this->capture($this->product, requestedQty: '2', note: 'Original');
        $transfer = $this->initiate([new InitiateTransferLineData(productId: $this->product->id, quantity: '2')]);
        $this->assertSame(ReplenishmentStatus::Fulfilled, $fulfilled->refresh()->status);
        $newOpen = $this->capture($this->product, requestedQty: '3', note: 'Requested again');

        app(StockTransferService::class)->cancel($transfer, $this->user->id, 'Cancelled');

        $newOpen->refresh();
        $fulfilled->refresh();
        $this->assertSame(ReplenishmentStatus::Pending, $newOpen->status);
        $this->assertSame(2, $newOpen->request_count);
        $this->assertSame('5.0000', $newOpen->requested_qty);
        $this->assertStringContainsString('Original', (string) $newOpen->note);
        $this->assertSame(ReplenishmentStatus::Cancelled, $fulfilled->status);
        $this->assertSame('superseded_after_transfer_cancelled', $fulfilled->rejection_reason);
    }

    private function capture(
        Product $product,
        ?string $variantId = null,
        ?string $requestedQty = null,
        ?string $note = null,
        ?Location $location = null,
    ): ReplenishmentRequest {
        return app(ReplenishmentCaptureService::class)->capture(new CaptureRequestData(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            locationId: ($location ?? $this->destination)->id,
            productId: $product->id,
            variantId: $variantId,
            requestedQty: $requestedQty,
            note: $note,
            requestedByUserId: $this->user->id,
            channel: ReplenishmentChannel::Web,
        ));
    }

    /** @param list<InitiateTransferLineData> $lines */
    private function initiate(array $lines): StockTransfer
    {
        foreach ($lines as $line) {
            app(StockAdjustmentService::class)->receive(
                productId: $line->productId,
                locationId: $this->source->id,
                quantity: $line->quantity,
                reference: 'SEED',
                userId: $this->user->id,
                expectedCompanyId: $this->company->id,
                variantId: $line->variantId,
            );
        }

        return app(StockTransferService::class)->initiate(new InitiateTransferData(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            sourceLocationId: $this->source->id,
            destinationLocationId: $this->destination->id,
            initiatedByUserId: $this->user->id,
            lines: $lines,
        ));
    }
}
