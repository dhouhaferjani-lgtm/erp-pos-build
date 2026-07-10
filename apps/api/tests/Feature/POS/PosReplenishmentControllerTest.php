<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Product\Domain\Product;
use App\Modules\Replenishment\Application\DTOs\CaptureRequestData;
use App\Modules\Replenishment\Application\Services\ReplenishmentCaptureService;
use App\Modules\Replenishment\Application\Services\ReplenishmentQueryService;
use App\Modules\Replenishment\Domain\Enums\ReplenishmentChannel;
use App\Modules\Replenishment\Domain\ReplenishmentRequest;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class PosReplenishmentControllerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $shop;

    private Location $otherShop;

    private Terminal $terminal;

    private Product $product;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->for($this->tenant)->create();
        $this->shop = Location::factory()->create(['company_id' => $this->company->id]);
        $this->otherShop = Location::factory()->create(['company_id' => $this->company->id]);
        $this->terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'location_id' => $this->shop->id,
        ]);
        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $this->cashier = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $this->cashier->id,
            'company_id' => $this->company->id,
            'role' => 'cashier',
            'status' => 'active',
        ]);
        $cashierId = $this->cashier->id;
        Gate::before(static fn (User $user, string $ability): ?bool => $ability === 'pos.operate_terminal'
            && $user->id === $cashierId ? true : null);
        Sanctum::actingAs($this->cashier);
    }

    public function test_create_replay_and_natural_key_bump_return_success(): void
    {
        $firstUuid = Str::uuid()->toString();
        $secondUuid = Str::uuid()->toString();
        $payload = $this->payload($firstUuid, '2');

        $first = $this->postCapture($payload)->assertCreated()->assertJsonPath('data.requested_qty', '2.0000');
        $this->postCapture([...$payload, 'requested_qty' => '99'])
            ->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'))
            ->assertJsonPath('data.request_count', 1);
        $this->postCapture([...$payload, 'client_request_uuid' => $secondUuid, 'requested_qty' => '3'])
            ->assertOk()
            ->assertJsonPath('data.requested_qty', '5.0000')
            ->assertJsonPath('data.request_count', 2);
    }

    public function test_bump_path_uuid_replay_does_not_bump_twice(): void
    {
        $this->postCapture($this->payload(Str::uuid()->toString(), '2'))->assertCreated();
        $bumpUuid = Str::uuid()->toString();
        $payload = $this->payload($bumpUuid, '3');
        $this->postCapture($payload)->assertOk();

        $this->postCapture($payload)
            ->assertOk()
            ->assertJsonPath('data.requested_qty', '5.0000')
            ->assertJsonPath('data.request_count', 2);
    }

    public function test_payload_location_id_is_ignored(): void
    {
        $this->postCapture([
            ...$this->payload(Str::uuid()->toString()),
            'location_id' => Str::uuid()->toString(),
        ])->assertCreated();

        $this->assertSame($this->shop->id, ReplenishmentRequest::query()->sole()->location_id);
    }

    public function test_unknown_terminal_returns_404(): void
    {
        $this->postCapture([
            ...$this->payload(Str::uuid()->toString()),
            'terminal_id' => Str::uuid()->toString(),
        ])->assertNotFound()->assertJsonPath('error.code', 'TERMINAL_NOT_FOUND');
    }

    public function test_terminal_of_other_company_returns_404(): void
    {
        $otherCompany = Company::factory()->for($this->tenant)->create();
        $otherLocation = Location::factory()->create(['company_id' => $otherCompany->id]);
        $otherTerminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
            'location_id' => $otherLocation->id,
        ]);

        $this->postCapture([
            ...$this->payload(Str::uuid()->toString()),
            'terminal_id' => $otherTerminal->id,
        ])->assertNotFound()->assertJsonPath('error.code', 'TERMINAL_NOT_FOUND');
    }

    public function test_pull_feed_is_scoped_to_terminal_location_and_uses_snake_case(): void
    {
        $visible = $this->captureAt($this->shop);
        $hidden = $this->captureAt($this->otherShop);

        $response = $this->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/pos/replenishment-requests?terminal_id='.$this->terminal->id)
            ->assertOk()
            ->assertJsonPath('truncated', false)
            ->assertJsonStructure(['data' => [[
                'id', 'product_id', 'variant_id', 'requested_qty', 'request_count', 'last_requested_at',
            ]], 'as_of', 'truncated']);

        $ids = array_column($response->json('data'), 'id');
        $this->assertContains($visible->id, $ids);
        $this->assertNotContains($hidden->id, $ids);
    }

    public function test_cross_company_uuid_replay_returns_409_permanent_conflict(): void
    {
        $uuid = Str::uuid()->toString();
        $this->postCapture($this->payload($uuid))->assertCreated();
        $otherCompany = Company::factory()->for($this->tenant)->create();
        $otherLocation = Location::factory()->create(['company_id' => $otherCompany->id]);
        $otherTerminal = Terminal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
            'location_id' => $otherLocation->id,
        ]);
        $otherProduct = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $otherCompany->id,
        ]);
        UserCompanyMembership::create([
            'user_id' => $this->cashier->id,
            'company_id' => $otherCompany->id,
            'role' => 'cashier',
            'status' => 'active',
        ]);

        $this->withHeader('X-Company-Id', $otherCompany->id)
            ->postJson('/api/v1/pos/replenishment-requests', [
                'client_request_uuid' => $uuid,
                'terminal_id' => $otherTerminal->id,
                'product_id' => $otherProduct->id,
            ])->assertConflict()
            ->assertJsonPath('error.code', 'REPLENISHMENT_UUID_COMPANY_CONFLICT');
    }

    public function test_pull_feed_sets_truncated_when_more_than_200_rows_match(): void
    {
        $products = Product::factory()->count(201)->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        foreach ($products as $product) {
            ReplenishmentRequest::query()->create([
                'tenant_id' => $this->tenant->id,
                'company_id' => $this->company->id,
                'location_id' => $this->shop->id,
                'product_id' => $product->id,
                'status' => 'pending',
                'source_channel' => 'pos',
                'requested_by_user_id' => $this->cashier->id,
                'first_requested_at' => now(),
                'last_requested_at' => now(),
            ]);
        }

        $response = $this->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/pos/replenishment-requests?terminal_id='.$this->terminal->id)
            ->assertOk()
            ->assertJsonPath('truncated', true);

        $this->assertCount(200, $response->json('data'));
    }

    public function test_user_without_terminal_permission_gets_403_for_store_and_index(): void
    {
        $unauthorized = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $unauthorized->id,
            'company_id' => $this->company->id,
            'role' => 'cashier',
            'status' => 'active',
        ]);
        Sanctum::actingAs($unauthorized);

        $this->postCapture($this->payload(Str::uuid()->toString()))->assertForbidden();
        $this->withHeader('X-Company-Id', $this->company->id)
            ->getJson('/api/v1/pos/replenishment-requests?terminal_id='.$this->terminal->id)
            ->assertForbidden();
    }

    public function test_query_service_reads_one_response_row_by_company(): void
    {
        $captured = $this->captureAt($this->shop);

        $row = app(ReplenishmentQueryService::class)->findForCompany(
            $this->tenant->id,
            $this->company->id,
            $captured->id,
        );

        $this->assertSame($captured->id, $row->id);
        $this->assertSame($this->product->name, $row->product_name);
    }

    /** @return array<string, string> */
    private function payload(string $uuid, string $quantity = '1'): array
    {
        return [
            'client_request_uuid' => $uuid,
            'terminal_id' => $this->terminal->id,
            'product_id' => $this->product->id,
            'requested_qty' => $quantity,
        ];
    }

    private function postCapture(array $payload): TestResponse
    {
        return $this->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/pos/replenishment-requests', $payload);
    }

    private function captureAt(Location $location): ReplenishmentRequest
    {
        return app(ReplenishmentCaptureService::class)->capture(new CaptureRequestData(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            locationId: $location->id,
            productId: $this->product->id,
            variantId: null,
            requestedQty: null,
            note: null,
            requestedByUserId: $this->cashier->id,
            channel: ReplenishmentChannel::Pos,
            clientRequestUuid: Str::uuid()->toString(),
        ));
    }
}
