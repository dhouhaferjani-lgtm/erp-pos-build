<?php

declare(strict_types=1);

namespace Tests\Feature\SmartPrompts;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\SmartPrompts\Application\Contracts\RecommendationEngineClientInterface;
use App\Modules\SmartPrompts\Domain\Enums\SmartPromptsVariant;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class SmartPromptsControllerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private RecommendationEngineClientInterface $mockClient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create([
            'vertical' => Vertical::Parapharmacy,
        ]);
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
            'smart_prompts_enabled' => true,
            'smart_prompts_variant' => SmartPromptsVariant::Inline,
        ]);
        $this->user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        $this->mockClient = $this->createMock(RecommendationEngineClientInterface::class);
        $this->app->instance(RecommendationEngineClientInterface::class, $this->mockClient);
    }

    public function test_returns_recommendations_for_parapharmacy_vertical(): void
    {
        $productId = Str::uuid()->toString();
        $recommendedId = Str::uuid()->toString();

        $this->mockClient->method('isCircuitOpen')->willReturn(false);
        $this->mockClient->method('getRecommendations')->willReturn([
            'recommendations' => [
                [
                    'product_id' => $recommendedId,
                    'product_name' => 'Hydrating Cream',
                    'score' => '0.92',
                    'reason' => 'Frequently bought together',
                    'strategy' => 'curated_relationships',
                ],
            ],
            'context' => 'cart',
            'generated_at' => '2026-03-27T12:00:00+00:00',
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/smart-prompts/recommendations', [
                'product_ids' => [$productId],
                'context' => 'cart',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.context', 'cart')
            ->assertJsonCount(1, 'data.recommendations')
            ->assertJsonPath('data.recommendations.0.product_id', $recommendedId);
    }

    public function test_returns_empty_for_unsupported_vertical(): void
    {
        $coffeeShopTenant = Tenant::factory()->create([
            'vertical' => Vertical::CoffeeShop,
        ]);
        $coffeeShopCompany = Company::factory()->create([
            'tenant_id' => $coffeeShopTenant->id,
            'smart_prompts_enabled' => true,
        ]);
        $coffeeShopUser = User::factory()->create([
            'tenant_id' => $coffeeShopTenant->id,
        ]);
        UserCompanyMembership::create([
            'user_id' => $coffeeShopUser->id,
            'company_id' => $coffeeShopCompany->id,
            'role' => 'admin',
        ]);

        $this->mockClient->method('isCircuitOpen')->willReturn(false);
        $this->mockClient->expects($this->never())->method('getRecommendations');

        Sanctum::actingAs($coffeeShopUser);

        $response = $this->withHeader('X-Company-Id', $coffeeShopCompany->id)
            ->postJson('/api/v1/smart-prompts/recommendations', [
                'product_ids' => [Str::uuid()->toString()],
            ]);

        $response->assertOk()
            ->assertJsonCount(0, 'data.recommendations');
    }

    public function test_returns_empty_when_feature_disabled(): void
    {
        $this->company->update(['smart_prompts_enabled' => false]);

        $this->mockClient->method('isCircuitOpen')->willReturn(false);
        $this->mockClient->expects($this->never())->method('getRecommendations');

        Sanctum::actingAs($this->user);

        $response = $this->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/smart-prompts/recommendations', [
                'product_ids' => [Str::uuid()->toString()],
            ]);

        $response->assertOk()
            ->assertJsonCount(0, 'data.recommendations');
    }

    public function test_returns_empty_when_circuit_open(): void
    {
        $this->mockClient->method('isCircuitOpen')->willReturn(true);
        $this->mockClient->expects($this->never())->method('getRecommendations');

        Sanctum::actingAs($this->user);

        $response = $this->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/smart-prompts/recommendations', [
                'product_ids' => [Str::uuid()->toString()],
            ]);

        $response->assertOk()
            ->assertJsonCount(0, 'data.recommendations');
    }

    public function test_requires_authentication(): void
    {
        $response = $this->postJson('/api/v1/smart-prompts/recommendations', [
            'product_ids' => [Str::uuid()->toString()],
        ]);

        $response->assertUnauthorized();
    }

    public function test_validates_product_ids_required(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/smart-prompts/recommendations', []);

        $response->assertUnprocessable()
            ->assertJsonPath('error.errors.product_ids.0', fn ($v) => is_string($v));
    }
}
