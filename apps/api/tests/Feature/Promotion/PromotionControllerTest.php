<?php

declare(strict_types=1);

namespace Tests\Feature\Promotion;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Promotion\Domain\Entities\Promotion;
use App\Modules\Promotion\Domain\Enums\DiscountAppliesTo;
use App\Modules\Promotion\Domain\Enums\DiscountType;
use App\Modules\Promotion\Domain\Enums\PromotionStatus;
use App\Modules\Promotion\Domain\Enums\PromotionType;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class PromotionControllerTest extends TestCase
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

    public function test_index_requires_promotions_view_permission(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/v1/promotions');

        $response->assertStatus(403);
    }

    public function test_index_returns_promotions_with_permission(): void
    {
        $this->grantPermission('promotions.view');
        Sanctum::actingAs($this->user);

        $this->createPromotion(['name' => 'Happy Hour']);

        $response = $this->getJson('/api/v1/promotions');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }

    public function test_show_requires_promotions_view_permission(): void
    {
        Sanctum::actingAs($this->user);
        $promotion = $this->createPromotion();

        $response = $this->getJson("/api/v1/promotions/{$promotion->id}");

        $response->assertStatus(403);
    }

    public function test_show_returns_promotion_with_permission(): void
    {
        $this->grantPermission('promotions.view');
        Sanctum::actingAs($this->user);
        $promotion = $this->createPromotion(['name' => 'Test Promo']);

        $response = $this->getJson("/api/v1/promotions/{$promotion->id}");

        $response->assertOk();
        $response->assertJsonPath('data.name', 'Test Promo');
    }

    public function test_store_creates_promotion_with_manage_permission(): void
    {
        $this->grantPermission('promotions.manage');
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/promotions', [
            'name' => 'New Promo',
            'type' => PromotionType::HappyHour->value,
            'discount_type' => DiscountType::Percentage->value,
            'discount_value' => '10',
            'applies_to' => DiscountAppliesTo::Transaction->value,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.name', 'New Promo');
        $response->assertJsonPath('data.status', PromotionStatus::Draft->value);
    }

    public function test_store_requires_promotions_manage_permission(): void
    {
        $this->grantPermission('promotions.view');
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/promotions', [
            'name' => 'New Promo',
            'type' => PromotionType::HappyHour->value,
            'discount_type' => DiscountType::Percentage->value,
            'discount_value' => '10',
            'applies_to' => DiscountAppliesTo::Transaction->value,
        ]);

        $response->assertStatus(403);
    }

    public function test_activate_transitions_draft_to_active(): void
    {
        $this->grantPermission('promotions.manage');
        Sanctum::actingAs($this->user);
        $promotion = $this->createPromotion(['status' => PromotionStatus::Draft]);

        $response = $this->postJson("/api/v1/promotions/{$promotion->id}/activate");

        $response->assertOk();
        $response->assertJsonPath('data.status', PromotionStatus::Active->value);
    }

    public function test_pause_transitions_active_to_paused(): void
    {
        $this->grantPermission('promotions.manage');
        Sanctum::actingAs($this->user);
        $promotion = $this->createPromotion(['status' => PromotionStatus::Active]);

        $response = $this->postJson("/api/v1/promotions/{$promotion->id}/pause");

        $response->assertOk();
        $response->assertJsonPath('data.status', PromotionStatus::Paused->value);
    }

    public function test_archive_transitions_to_archived(): void
    {
        $this->grantPermission('promotions.manage');
        Sanctum::actingAs($this->user);
        $promotion = $this->createPromotion(['status' => PromotionStatus::Paused]);

        $response = $this->postJson("/api/v1/promotions/{$promotion->id}/archive");

        $response->assertOk();
        $response->assertJsonPath('data.status', PromotionStatus::Archived->value);
    }

    public function test_cannot_update_archived_promotion(): void
    {
        $this->grantPermission('promotions.manage');
        Sanctum::actingAs($this->user);
        $promotion = $this->createPromotion(['status' => PromotionStatus::Archived]);

        $response = $this->patchJson("/api/v1/promotions/{$promotion->id}", [
            'name' => 'Updated',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'PROMOTION_ARCHIVED');
    }

    public function test_cannot_delete_active_promotion(): void
    {
        $this->grantPermission('promotions.manage');
        Sanctum::actingAs($this->user);
        $promotion = $this->createPromotion(['status' => PromotionStatus::Active]);

        $response = $this->deleteJson("/api/v1/promotions/{$promotion->id}");

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'PROMOTION_ACTIVE');
    }

    public function test_delete_draft_promotion_succeeds(): void
    {
        $this->grantPermission('promotions.manage');
        Sanctum::actingAs($this->user);
        $promotion = $this->createPromotion(['status' => PromotionStatus::Draft]);

        $response = $this->deleteJson("/api/v1/promotions/{$promotion->id}");

        $response->assertStatus(204);
        $this->assertSoftDeleted('promotions', ['id' => $promotion->id]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createPromotion(array $overrides = []): Promotion
    {
        return Promotion::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'name' => 'Test Promotion',
            'type' => PromotionType::HappyHour,
            'status' => PromotionStatus::Draft,
            'priority' => 0,
            'is_exclusive' => false,
            'stacking_group' => 'default',
            'conditions' => [],
            'discount_type' => DiscountType::Percentage,
            'discount_value' => '10',
            'applies_to' => DiscountAppliesTo::Transaction,
            'usage_count' => 0,
        ], $overrides));
    }

    private function grantPermission(string $permission): void
    {
        Permission::findOrCreate($permission, 'sanctum');
        $this->user->givePermissionTo($permission);
    }
}
