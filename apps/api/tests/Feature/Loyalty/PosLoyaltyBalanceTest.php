<?php

declare(strict_types=1);

namespace Tests\Feature\Loyalty;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Loyalty\Domain\Entities\LoyaltyMember;
use App\Modules\Loyalty\Domain\Entities\LoyaltyProgram;
use App\Modules\Loyalty\Domain\Enums\ProgramStatus;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class PosLoyaltyBalanceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Company $company;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        // Mirror LoyaltyPOSControllerTest::setUp() exactly for the auth/tenant/permission bootstrap.
        $this->tenant = Tenant::factory()->create(['enabled_extras' => ['Loyalty']]);
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create(['user_id' => $this->user->id, 'company_id' => $this->company->id, 'role' => 'admin']);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('pos.operate_terminal', 'sanctum');
        $this->user->givePermissionTo('pos.operate_terminal');
        Sanctum::actingAs($this->user);
    }

    private function activeProgram(string $rate = '2'): LoyaltyProgram
    {
        $program = LoyaltyProgram::factory()->create(['tenant_id' => $this->tenant->id, 'status' => ProgramStatus::Active]);
        \App\Modules\Loyalty\Domain\Entities\EarningRule::factory()->create([
            'program_id' => $program->id,
            'rule_type' => \App\Modules\Loyalty\Domain\Enums\EarningRuleType::Spend,
            'reward_value' => $rate,
            'is_active' => true,
            'conditions' => [],
        ]);

        return $program;
    }

    public function test_creates_member_and_enrollment_for_new_customer_with_phone(): void
    {
        $this->activeProgram('2');
        $partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => 'customer',
        ]);
        $partnerId = (string) $partner->id;

        $res = $this->postJson('/api/v1/loyalty/pos/balance', [
            'partner_id' => $partnerId, 'phone' => '+21620123456', 'name' => 'Amina',
        ]);

        $res->assertOk()
            ->assertJsonPath('data.enrolled', true)
            ->assertJsonPath('data.balance', '0.000')
            ->assertJsonPath('data.rate', '2.0000'); // EarningRule.reward_value decimal:4 cast
        $this->assertDatabaseHas('loyalty_members', ['tenant_id' => $this->tenant->id, 'loyaltyable_id' => $partnerId, 'customer_id' => $partnerId]);
    }

    public function test_repeat_call_does_not_duplicate_member_or_enrollment(): void
    {
        $this->activeProgram();
        $partner = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => 'customer',
        ]);
        $partnerId = (string) $partner->id;
        $payload = ['partner_id' => $partnerId, 'phone' => '+21620123456', 'name' => 'Amina'];

        $this->postJson('/api/v1/loyalty/pos/balance', $payload)->assertOk();
        $this->postJson('/api/v1/loyalty/pos/balance', $payload)->assertOk()->assertJsonPath('data.enrolled', true);

        self::assertSame(1, LoyaltyMember::where('tenant_id', $this->tenant->id)->where('loyaltyable_id', $partnerId)->count());
        $memberId = LoyaltyMember::where('tenant_id', $this->tenant->id)->where('loyaltyable_id', $partnerId)->value('id');
        self::assertSame(1, \App\Modules\Loyalty\Domain\Entities\Enrollment::where('member_id', $memberId)->count());
    }

    public function test_no_phone_returns_not_enrolled_and_creates_nothing(): void
    {
        $this->activeProgram();
        $partnerId = (string) Str::uuid();

        $this->postJson('/api/v1/loyalty/pos/balance', ['partner_id' => $partnerId])
            ->assertOk()->assertJsonPath('data.enrolled', false)->assertJsonPath('data.balance', '0.000');

        self::assertSame(0, LoyaltyMember::where('tenant_id', $this->tenant->id)->count());
    }

    public function test_no_active_program_returns_not_enrolled(): void
    {
        $this->postJson('/api/v1/loyalty/pos/balance', ['partner_id' => (string) Str::uuid(), 'phone' => '+21620123456'])
            ->assertOk()->assertJsonPath('data.enrolled', false);
    }

    public function test_phone_match_repoints_member_to_attaching_customer(): void
    {
        $this->activeProgram('2');

        // A different partner — the one the phone-matched member currently points at.
        $other = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => 'customer',
        ]);
        // The attaching customer (partner A).
        $partnerA = Partner::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'type' => 'customer',
        ]);

        // Seed a member with phone P pointing at $other (not $partnerA).
        LoyaltyMember::factory()->create([
            'tenant_id' => $this->tenant->id,
            'loyaltyable_type' => 'partner',
            'loyaltyable_id' => (string) $other->id,
            'customer_id' => (string) $other->id,
            'phone' => '+21699000001',
        ]);

        $partnerId = (string) $partnerA->id;

        $res = $this->postJson('/api/v1/loyalty/pos/balance', [
            'partner_id' => $partnerId,
            'phone' => '+21699000001',
            'name' => 'Rim',
        ]);

        $res->assertOk()->assertJsonPath('data.enrolled', true);

        // The member must now point at $partnerA so MemberResolver / SaleEarningService resolve correctly.
        $this->assertDatabaseHas('loyalty_members', [
            'tenant_id' => $this->tenant->id,
            'loyaltyable_id' => $partnerId,
            'customer_id' => $partnerId,
        ]);
    }

    public function test_403_when_loyalty_module_disabled(): void
    {
        $t = Tenant::factory()->create(['enabled_extras' => []]);
        $c = Company::factory()->create(['tenant_id' => $t->id]);
        $u = User::factory()->create(['tenant_id' => $t->id]);
        UserCompanyMembership::create(['user_id' => $u->id, 'company_id' => $c->id, 'role' => 'admin']);
        app(PermissionRegistrar::class)->setPermissionsTeamId($t->id);
        Permission::findOrCreate('pos.operate_terminal', 'sanctum');
        $u->givePermissionTo('pos.operate_terminal'); // isolate: only module:Loyalty can 403
        Sanctum::actingAs($u);

        $this->postJson('/api/v1/loyalty/pos/balance', ['partner_id' => (string) Str::uuid(), 'phone' => '+21620123456'])
            ->assertForbidden();
    }
}
