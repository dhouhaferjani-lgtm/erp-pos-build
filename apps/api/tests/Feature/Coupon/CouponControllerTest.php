<?php

declare(strict_types=1);

namespace Tests\Feature\Coupon;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Coupon\Domain\Entities\Coupon;
use App\Modules\Coupon\Domain\Enums\CouponStatus;
use App\Modules\Coupon\Domain\Enums\CouponType;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class CouponControllerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
    }

    public function test_index_lists_coupons_for_company(): void
    {
        $this->grantPermission('coupons.view');
        Sanctum::actingAs($this->user);

        $this->createCoupon(['code' => 'SUMMER10']);
        $this->createCoupon(['code' => 'WINTER20']);

        $response = $this->getJson('/api/v1/coupons');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
    }

    public function test_store_creates_coupon_with_manage_permission(): void
    {
        $this->grantPermission('coupons.manage');
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/coupons', [
            'name' => 'Summer Sale',
            'code' => 'summer10',
            'type' => CouponType::Standard->value,
            'discount_type' => 'percentage',
            'discount_value' => '10',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.code', 'SUMMER10');
    }

    public function test_store_rejects_without_manage_permission(): void
    {
        $this->grantPermission('coupons.view');
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/coupons', [
            'name' => 'Summer Sale',
            'code' => 'SUMMER10',
            'type' => CouponType::Standard->value,
            'discount_type' => 'percentage',
            'discount_value' => '10',
        ]);

        $response->assertStatus(403);
    }

    public function test_show_returns_coupon(): void
    {
        $this->grantPermission('coupons.view');
        Sanctum::actingAs($this->user);
        $coupon = $this->createCoupon(['code' => 'SHOW10', 'name' => 'Show Test']);

        $response = $this->getJson("/api/v1/coupons/{$coupon->id}");

        $response->assertOk();
        $response->assertJsonPath('data.name', 'Show Test');
    }

    public function test_update_modifies_coupon(): void
    {
        $this->grantPermission('coupons.manage');
        Sanctum::actingAs($this->user);
        $coupon = $this->createCoupon(['code' => 'UPDATE10']);

        $response = $this->patchJson("/api/v1/coupons/{$coupon->id}", [
            'name' => 'Updated Coupon',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'Updated Coupon');
    }

    public function test_delete_removes_coupon(): void
    {
        $this->grantPermission('coupons.manage');
        Sanctum::actingAs($this->user);
        $coupon = $this->createCoupon(['code' => 'DELETE10']);

        $response = $this->deleteJson("/api/v1/coupons/{$coupon->id}");

        $response->assertStatus(204);
        $this->assertSoftDeleted('coupons', ['id' => $coupon->id]);
    }

    public function test_validate_returns_valid_for_active_coupon(): void
    {
        $this->grantPermission('pos.operate_terminal');
        Sanctum::actingAs($this->user);
        $this->createCoupon([
            'code' => 'VALID10',
            'discount_type' => 'percentage',
            'discount_value' => '10',
        ]);

        $response = $this->postJson('/api/v1/coupons/validate', [
            'code' => 'VALID10',
            'subtotal' => '100.00',
            'items' => [],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.valid', true);
    }

    public function test_validate_returns_invalid_for_expired_coupon(): void
    {
        $this->grantPermission('pos.operate_terminal');
        Sanctum::actingAs($this->user);
        $this->createCoupon([
            'code' => 'EXPIRED10',
            'status' => CouponStatus::Expired,
        ]);

        $response = $this->postJson('/api/v1/coupons/validate', [
            'code' => 'EXPIRED10',
            'subtotal' => '100.00',
            'items' => [],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('data.valid', false);
    }

    public function test_revoke_transitions_coupon_to_revoked(): void
    {
        $this->grantPermission('coupons.manage');
        Sanctum::actingAs($this->user);
        $coupon = $this->createCoupon(['code' => 'REVOKE10']);

        $response = $this->postJson("/api/v1/coupons/{$coupon->id}/revoke");

        $response->assertOk();
        $this->assertDatabaseHas('coupons', [
            'id' => $coupon->id,
            'status' => CouponStatus::Revoked->value,
        ]);
    }

    public function test_reactivate_transitions_revoked_coupon_to_active(): void
    {
        $this->grantPermission('coupons.manage');
        Sanctum::actingAs($this->user);
        $coupon = $this->createCoupon([
            'code' => 'REACT10',
            'status' => CouponStatus::Revoked,
        ]);

        $response = $this->postJson("/api/v1/coupons/{$coupon->id}/reactivate");

        $response->assertOk();
        $this->assertDatabaseHas('coupons', [
            'id' => $coupon->id,
            'status' => CouponStatus::Active->value,
        ]);
    }

    public function test_unauthenticated_user_cannot_access_coupons(): void
    {
        $response = $this->getJson('/api/v1/coupons');

        $response->assertStatus(401);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createCoupon(array $overrides = []): Coupon
    {
        return Coupon::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Coupon',
            'code' => 'TEST'.random_int(1000, 9999),
            'type' => CouponType::Standard,
            'status' => CouponStatus::Active,
            'is_single_use' => false,
            'use_count' => 0,
            'discount_type' => 'percentage',
            'discount_value' => '10',
            'is_exclusive' => false,
            'stacking_group' => 'coupons',
        ], $overrides));
    }

    private function grantPermission(string $permission): void
    {
        Permission::findOrCreate($permission, 'sanctum');
        $this->user->givePermissionTo($permission);
    }
}
