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
use App\Shared\Domain\Enums\SkinType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class SkinTypeValidationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create([
            'vertical' => Vertical::Parapharmacy,
        ]);
        $this->company = Company::factory()->create([
            'tenant_id' => $tenant->id,
            'smart_prompts_enabled' => true,
            'smart_prompts_variant' => SmartPromptsVariant::Inline,
        ]);
        $this->user = User::factory()->create([
            'tenant_id' => $tenant->id,
        ]);
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        $mockClient = $this->createMock(RecommendationEngineClientInterface::class);
        $mockClient->method('isCircuitOpen')->willReturn(false);
        $mockClient->method('getRecommendations')->willReturn([
            'recommendations' => [],
            'context' => 'cart',
            'generated_at' => now()->toIso8601String(),
        ]);
        $this->app->instance(RecommendationEngineClientInterface::class, $mockClient);
    }

    public function test_accepts_all_shared_skin_type_values(): void
    {
        Sanctum::actingAs($this->user);

        foreach (SkinType::cases() as $skinType) {
            $response = $this->withHeader('X-Company-Id', $this->company->id)
                ->postJson('/api/v1/smart-prompts/recommendations', [
                    'product_ids' => [Str::uuid()->toString()],
                    'skin_type' => $skinType->value,
                ]);

            $response->assertOk(
                "Expected 200 for skin_type={$skinType->value}"
            );
        }
    }

    public function test_rejects_invalid_skin_type(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/smart-prompts/recommendations', [
                'product_ids' => [Str::uuid()->toString()],
                'skin_type' => 'zzz',
            ]);

        $response->assertUnprocessable();
    }

    public function test_null_skin_type_is_accepted(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->withHeader('X-Company-Id', $this->company->id)
            ->postJson('/api/v1/smart-prompts/recommendations', [
                'product_ids' => [Str::uuid()->toString()],
                'skin_type' => null,
            ]);

        $response->assertOk();
    }
}
