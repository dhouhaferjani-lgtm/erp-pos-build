<?php

declare(strict_types=1);

namespace Tests\Feature\Replenishment;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\User;
use App\Modules\Product\Domain\Product;
use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Replenishment\Application\DTOs\CaptureRequestData;
use App\Modules\Replenishment\Application\Services\ReplenishmentCaptureService;
use App\Modules\Replenishment\Domain\Enums\ReplenishmentChannel;
use App\Modules\Replenishment\Domain\Enums\ReplenishmentStatus;
use App\Modules\Replenishment\Domain\Events\ReplenishmentRequestBumped;
use App\Modules\Replenishment\Domain\Events\ReplenishmentRequested;
use App\Modules\Replenishment\Domain\Exceptions\CrossCompanyReplayException;
use App\Modules\Replenishment\Domain\ReplenishmentRequest;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ReplenishmentCaptureServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $location;

    private Product $product;

    private User $user;

    private ReplenishmentCaptureService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->for($this->tenant)->create();
        $this->location = Location::factory()->create(['company_id' => $this->company->id]);
        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->service = new ReplenishmentCaptureService;
    }

    public function test_insert_creates_pending_row_and_emits_requested(): void
    {
        Event::fake([ReplenishmentRequested::class]);

        $row = $this->service->capture($this->data(requestedQty: '2.5', note: 'Front shelf empty'));

        $this->assertTrue($row->wasRecentlyCreated);
        $this->assertSame(ReplenishmentStatus::Pending, $row->status);
        $this->assertSame('2.5000', $row->requested_qty);
        $this->assertSame(1, $row->request_count);
        Event::assertDispatched(
            ReplenishmentRequested::class,
            fn (ReplenishmentRequested $event): bool => $event->requestId === $row->id,
        );
    }

    public function test_second_request_same_key_bumps_not_forks(): void
    {
        Event::fake([ReplenishmentRequestBumped::class]);
        $this->service->capture($this->data(requestedQty: '2', note: 'First'));

        $row = $this->service->capture($this->data(requestedQty: '3', note: 'Second'));

        $this->assertFalse($row->wasRecentlyCreated);
        $this->assertSame('5.0000', $row->requested_qty);
        $this->assertSame(2, $row->request_count);
        $this->assertSame("First\n---\nSecond", $row->note);
        $this->assertSame(1, ReplenishmentRequest::query()->count());
        Event::assertDispatched(ReplenishmentRequestBumped::class);
    }

    public function test_qty_null_merge_keeps_existing_value(): void
    {
        $row = $this->service->capture($this->data(requestedQty: null));
        $this->assertNull($row->requested_qty);

        $row = $this->service->capture($this->data(requestedQty: '3'));
        $this->assertSame('3.0000', $row->requested_qty);

        $row = $this->service->capture($this->data(requestedQty: null));
        $this->assertSame('3.0000', $row->requested_qty);
    }

    public function test_client_uuid_replay_returns_existing_without_bump(): void
    {
        $uuid = Str::uuid()->toString();
        $first = $this->service->capture($this->data(requestedQty: '2', clientRequestUuid: $uuid));

        $replayed = $this->service->capture($this->data(requestedQty: '99', clientRequestUuid: $uuid));

        $this->assertSame($first->id, $replayed->id);
        $this->assertSame('2.0000', $replayed->requested_qty);
        $this->assertSame(1, $replayed->request_count);
    }

    public function test_bump_path_uuid_replay_is_idempotent(): void
    {
        $this->service->capture($this->data(requestedQty: '2', clientRequestUuid: Str::uuid()->toString()));
        $bumpUuid = Str::uuid()->toString();
        $bumped = $this->service->capture($this->data(requestedQty: '3', clientRequestUuid: $bumpUuid));

        $replayed = $this->service->capture($this->data(requestedQty: '3', clientRequestUuid: $bumpUuid));

        $this->assertSame($bumped->id, $replayed->id);
        $this->assertSame('5.0000', $replayed->requested_qty);
        $this->assertSame(2, $replayed->request_count);
    }

    public function test_cross_company_uuid_replay_throws(): void
    {
        $uuid = Str::uuid()->toString();
        $this->service->capture($this->data(clientRequestUuid: $uuid));
        $otherCompany = Company::factory()->for($this->tenant)->create();
        $otherLocation = Location::factory()->create(['company_id' => $otherCompany->id]);
        $otherProduct = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
        ]);

        $this->expectException(CrossCompanyReplayException::class);
        $this->service->capture(new CaptureRequestData(
            tenantId: $this->tenant->id,
            companyId: $otherCompany->id,
            locationId: $otherLocation->id,
            productId: $otherProduct->id,
            variantId: null,
            requestedQty: null,
            note: null,
            requestedByUserId: $this->user->id,
            channel: ReplenishmentChannel::Pos,
            clientRequestUuid: $uuid,
        ));
    }

    public function test_closed_line_gets_fresh_row(): void
    {
        $closed = $this->service->capture($this->data());
        $closed->update(['status' => ReplenishmentStatus::Fulfilled]);

        $fresh = $this->service->capture($this->data());

        $this->assertNotSame($closed->id, $fresh->id);
        $this->assertSame(ReplenishmentStatus::Pending, $fresh->status);
        $this->assertSame(2, ReplenishmentRequest::query()->count());
    }

    public function test_variant_scoped_dedupe(): void
    {
        $variantA = $this->variant();
        $variantB = $this->variant();

        $this->service->capture($this->data(variantId: $variantA->id));
        $this->service->capture($this->data(variantId: $variantB->id));
        $row = $this->service->capture($this->data(variantId: $variantA->id));

        $this->assertSame(2, ReplenishmentRequest::query()->count());
        $this->assertSame(2, $row->request_count);
    }

    public function test_concurrent_insert_race_is_approximated_by_repeated_insert_or_bump(): void
    {
        $this->service->capture($this->data(clientRequestUuid: Str::uuid()->toString()));
        $row = $this->service->capture($this->data(clientRequestUuid: Str::uuid()->toString()));

        $this->assertSame(1, ReplenishmentRequest::query()->count());
        $this->assertSame(2, $row->request_count);
    }

    private function data(
        ?string $requestedQty = null,
        ?string $note = null,
        ?string $clientRequestUuid = null,
        ?string $variantId = null,
    ): CaptureRequestData {
        return new CaptureRequestData(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            locationId: $this->location->id,
            productId: $this->product->id,
            variantId: $variantId,
            requestedQty: $requestedQty,
            note: $note,
            requestedByUserId: $this->user->id,
            channel: ReplenishmentChannel::Web,
            clientRequestUuid: $clientRequestUuid,
        );
    }

    private function variant(): ProductVariant
    {
        return ProductVariant::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
        ]);
    }
}
