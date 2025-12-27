<?php

declare(strict_types=1);

namespace Tests\Feature\Broadcasting;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductChannelAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_subscribe_to_product_channel_in_their_tenant_and_company(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => UserStatus::Active,
        ]);

        // Create company membership
        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'tenant_id' => $tenant->id,
            'role' => MembershipRole::Viewer,
        ]);

        $product = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
        ]);

        $this->actingAs($user, 'sanctum');

        $canSubscribe = $user->canAccessChannel(
            $tenant->id,
            $company->id,
            $product->id
        );

        $this->assertTrue($canSubscribe);
    }

    public function test_user_cannot_subscribe_to_channel_in_different_tenant(): void
    {
        $tenant1 = Tenant::factory()->create();
        $tenant2 = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant2->id]);
        $user = User::factory()->create([
            'tenant_id' => $tenant1->id,
            'status' => UserStatus::Active,
        ]);

        $product = Product::factory()->create([
            'tenant_id' => $tenant2->id,
            'company_id' => $company->id,
        ]);

        $this->actingAs($user, 'sanctum');

        $canSubscribe = $user->canAccessChannel(
            $tenant2->id,
            $company->id,
            $product->id
        );

        $this->assertFalse($canSubscribe);
    }

    public function test_user_cannot_subscribe_to_channel_for_company_they_are_not_member_of(): void
    {
        $tenant = Tenant::factory()->create();
        $company1 = Company::factory()->create(['tenant_id' => $tenant->id]);
        $company2 = Company::factory()->create(['tenant_id' => $tenant->id]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => UserStatus::Active,
        ]);

        // User is member of company1 only
        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company1->id,
            'tenant_id' => $tenant->id,
            'role' => MembershipRole::Viewer,
        ]);

        $product = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company2->id,
        ]);

        $this->actingAs($user, 'sanctum');

        $canSubscribe = $user->canAccessChannel(
            $tenant->id,
            $company2->id,
            $product->id
        );

        $this->assertFalse($canSubscribe);
    }

    public function test_inactive_user_cannot_subscribe_to_channel(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => UserStatus::Suspended,
        ]);

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'tenant_id' => $tenant->id,
            'role' => MembershipRole::Viewer,
        ]);

        $product = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
        ]);

        $this->actingAs($user, 'sanctum');

        $canSubscribe = $user->canAccessChannel(
            $tenant->id,
            $company->id,
            $product->id
        );

        $this->assertFalse($canSubscribe);
    }
}
