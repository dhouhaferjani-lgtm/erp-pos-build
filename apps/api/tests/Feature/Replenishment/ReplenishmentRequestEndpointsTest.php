<?php

declare(strict_types=1);

namespace Tests\Feature\Replenishment;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\Product\Domain\Product;
use App\Modules\Replenishment\Application\DTOs\CaptureRequestData;
use App\Modules\Replenishment\Application\Services\ReplenishmentCaptureService;
use App\Modules\Replenishment\Domain\Enums\ReplenishmentChannel;
use App\Modules\Replenishment\Domain\Enums\ReplenishmentStatus;
use App\Modules\Replenishment\Domain\Exceptions\CrossCompanyReplayException;
use App\Modules\Replenishment\Domain\ReplenishmentRequest;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class ReplenishmentRequestEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private Location $shopA;

    private Location $shopB;

    private Product $product;

    private User $creator;

    private User $processor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->for($this->tenant)->create();
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        app(CompanyContext::class)->setCompanyId($this->company->id);

        $this->shopA = Location::factory()->create(['company_id' => $this->company->id, 'name' => 'Shop A']);
        $this->shopB = Location::factory()->create(['company_id' => $this->company->id, 'name' => 'Shop B']);
        $this->product = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Product',
        ]);
        $this->creator = $this->userWithMembership('creator@example.test', [$this->shopA->id], [
            'replenishment.view',
            'replenishment.create',
        ]);
        $this->processor = $this->userWithMembership('processor@example.test', null, [
            'replenishment.view',
            'replenishment.create',
            'replenishment.process',
        ]);
    }

    public function test_capture_creates_pending_line_with_snake_case_resource_and_201(): void
    {
        $response = $this->actingAs($this->creator)->postJson('/api/v1/replenishment-requests', [
            'location_id' => $this->shopA->id,
            'product_id' => $this->product->id,
            'requested_qty' => '2.5000',
            'note' => 'Shelf empty',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.location_id', $this->shopA->id)
            ->assertJsonPath('data.product_id', $this->product->id)
            ->assertJsonPath('data.requested_qty', '2.5000')
            ->assertJsonStructure(['data' => [
                'id', 'location_id', 'location_name', 'product_id', 'product_name',
                'variant_id', 'variant_name', 'requested_qty', 'request_count',
                'first_requested_at', 'last_requested_at',
            ]]);
    }

    public function test_capture_bump_returns_200_with_bumped_line(): void
    {
        $payload = [
            'location_id' => $this->shopA->id,
            'product_id' => $this->product->id,
            'requested_qty' => '2',
        ];
        $this->actingAs($this->creator)->postJson('/api/v1/replenishment-requests', $payload)->assertCreated();

        $this->actingAs($this->creator)
            ->postJson('/api/v1/replenishment-requests', [...$payload, 'requested_qty' => '3'])
            ->assertOk()
            ->assertJsonPath('data.requested_qty', '5.0000')
            ->assertJsonPath('data.request_count', 2);
    }

    public function test_capture_rejects_location_outside_allowed_location_ids(): void
    {
        $this->actingAs($this->creator)->postJson('/api/v1/replenishment-requests', [
            'location_id' => $this->shopB->id,
            'product_id' => $this->product->id,
        ])->assertForbidden();
    }

    public function test_capture_rejects_location_of_other_company(): void
    {
        $otherCompany = Company::factory()->for($this->tenant)->create();
        $otherLocation = Location::factory()->create(['company_id' => $otherCompany->id]);

        $this->actingAs($this->processor)->postJson('/api/v1/replenishment-requests', [
            'location_id' => $otherLocation->id,
            'product_id' => $this->product->id,
        ])->assertUnprocessable();
    }

    public function test_qty_regex_rejects_five_decimal_places(): void
    {
        $this->actingAs($this->creator)->postJson('/api/v1/replenishment-requests', [
            'location_id' => $this->shopA->id,
            'product_id' => $this->product->id,
            'requested_qty' => '1.00001',
        ])->assertUnprocessable()->assertJsonStructure([
            'error' => ['errors' => ['requested_qty']],
        ]);
    }

    public function test_list_open_returns_pending_and_in_progress_and_scopes_non_processors(): void
    {
        $pending = $this->capture($this->shopA, $this->creator);
        $secondProduct = Product::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
        ]);
        $inProgress = $this->capture(
            $this->shopA,
            $this->creator,
            ReplenishmentStatus::InProgress,
            $secondProduct,
        );
        $hidden = $this->capture($this->shopB, $this->processor);

        $response = $this->actingAs($this->creator)
            ->getJson('/api/v1/replenishment-requests?status=open')
            ->assertOk()
            ->assertJsonPath('meta.truncated', false);

        $ids = array_column($response->json('data'), 'id');
        $this->assertContains($pending->id, $ids);
        $this->assertContains($inProgress->id, $ids);
        $this->assertNotContains($hidden->id, $ids);
    }

    public function test_list_fulfilled_is_paginated_with_meta(): void
    {
        $fulfilled = $this->capture($this->shopA, $this->creator, ReplenishmentStatus::Fulfilled);

        $response = $this->actingAs($this->creator)
            ->getJson('/api/v1/replenishment-requests?status=fulfilled')
            ->assertOk()
            ->assertJsonPath('data.0.id', $fulfilled->id)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 25)
            ->assertJsonPath('meta.total', 1);

        $this->assertIsArray($response->json('meta'));
    }

    public function test_history_per_page_accepts_supported_values_and_rejects_others(): void
    {
        $this->capture($this->shopA, $this->creator, ReplenishmentStatus::Fulfilled);

        $this->actingAs($this->creator)
            ->getJson('/api/v1/replenishment-requests?status=fulfilled&per_page=10')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 10);

        $this->actingAs($this->creator)
            ->getJson('/api/v1/replenishment-requests?status=fulfilled&per_page=11')
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['errors' => ['per_page']]]);
    }

    public function test_cancel_own_pending_line_rejects_other_user_but_allows_processor(): void
    {
        $own = $this->capture($this->shopA, $this->creator);
        $this->actingAs($this->creator)
            ->postJson("/api/v1/replenishment-requests/{$own->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $other = $this->capture($this->shopA, $this->processor);
        $this->actingAs($this->creator)
            ->postJson("/api/v1/replenishment-requests/{$other->id}/cancel")
            ->assertForbidden();
        $this->actingAs($this->processor)
            ->postJson("/api/v1/replenishment-requests/{$other->id}/cancel")
            ->assertOk();
    }

    public function test_process_only_user_can_cancel_pending_line(): void
    {
        $processOnly = $this->userWithMembership('process-only@example.test', null, [
            'replenishment.process',
        ]);
        $request = $this->capture($this->shopA, $this->creator);

        $this->actingAs($processOnly)
            ->postJson("/api/v1/replenishment-requests/{$request->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_cross_company_replay_exception_has_global_conflict_mapping(): void
    {
        Route::get('/api/_test/replenishment-conflict', static function (): never {
            throw new CrossCompanyReplayException('00000000-0000-4000-8000-000000000000');
        });

        $this->getJson('/api/_test/replenishment-conflict')
            ->assertConflict()
            ->assertJsonPath('error.code', 'REPLENISHMENT_UUID_COMPANY_CONFLICT');
    }

    public function test_view_requires_permission_for_cashier_role(): void
    {
        $cashier = $this->userWithMembership('cashier@example.test', [$this->shopA->id]);
        $cashier->assignRole('cashier');

        $this->actingAs($cashier)
            ->getJson('/api/v1/replenishment-requests?status=open')
            ->assertForbidden();
    }

    private function userWithMembership(string $email, ?array $locations, array $permissions = []): User
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id, 'email' => $email]);
        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }
        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role' => 'manager',
            'allowed_location_ids' => $locations,
            'status' => 'active',
        ]);

        return $user;
    }

    private function capture(
        Location $location,
        User $user,
        ReplenishmentStatus $status = ReplenishmentStatus::Pending,
        ?Product $product = null,
    ): ReplenishmentRequest {
        $row = app(ReplenishmentCaptureService::class)->capture(new CaptureRequestData(
            tenantId: $this->tenant->id,
            companyId: $this->company->id,
            locationId: $location->id,
            productId: ($product ?? $this->product)->id,
            variantId: null,
            requestedQty: null,
            note: null,
            requestedByUserId: $user->id,
            channel: ReplenishmentChannel::Web,
        ));
        if ($status !== ReplenishmentStatus::Pending) {
            $row->update(['status' => $status]);
            $row->refresh();
        }

        return $row;
    }
}
