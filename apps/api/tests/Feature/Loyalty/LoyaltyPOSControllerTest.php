<?php

declare(strict_types=1);

namespace Tests\Feature\Loyalty;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Loyalty\Domain\Entities\Enrollment;
use App\Modules\Loyalty\Domain\Entities\LoyaltyMember;
use App\Modules\Loyalty\Domain\Entities\LoyaltyProgram;
use App\Modules\Loyalty\Domain\Entities\Reward;
use App\Modules\Loyalty\Domain\Enums\EnrollmentStatus;
use App\Modules\Loyalty\Domain\Enums\MemberStatus;
use App\Modules\Loyalty\Domain\Enums\ProgramStatus;
use App\Modules\Loyalty\Domain\Enums\ProgramType;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class LoyaltyPOSControllerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Company $companyA;

    private User $userA;

    private Tenant $tenantB;

    private Company $companyB;

    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        // Tenant A
        $this->tenantA = Tenant::factory()->create(['enabled_extras' => ['Loyalty']]);
        $this->companyA = Company::factory()->create(['tenant_id' => $this->tenantA->id]);
        $this->userA = User::factory()->create(['tenant_id' => $this->tenantA->id]);
        UserCompanyMembership::create([
            'user_id' => $this->userA->id,
            'company_id' => $this->companyA->id,
            'role' => 'admin',
        ]);

        // Tenant B
        $this->tenantB = Tenant::factory()->create(['enabled_extras' => ['Loyalty']]);
        $this->companyB = Company::factory()->create(['tenant_id' => $this->tenantB->id]);
        $this->userB = User::factory()->create(['tenant_id' => $this->tenantB->id]);
        UserCompanyMembership::create([
            'user_id' => $this->userB->id,
            'company_id' => $this->companyB->id,
            'role' => 'admin',
        ]);

        // Grant POS permissions
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantA->id);
        Permission::findOrCreate('pos.operate_terminal', 'sanctum');
        $this->userA->givePermissionTo('pos.operate_terminal');

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantB->id);
        Permission::findOrCreate('pos.operate_terminal', 'sanctum');
        $this->userB->givePermissionTo('pos.operate_terminal');
    }

    public function test_member_lookup_returns_member_with_enrollments(): void
    {
        Sanctum::actingAs($this->userA);

        $program = $this->createProgram($this->tenantA);
        $member = $this->createMember($this->tenantA, '+33612345678');
        $this->createEnrollment($member, $program, '150.00');

        $response = $this->postJson('/api/v1/loyalty/pos/member-lookup', [
            'phone' => '+33612345678',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.member.phone', '+33612345678');
        $response->assertJsonCount(1, 'data.enrollments');
    }

    public function test_member_lookup_normalizes_phone(): void
    {
        Sanctum::actingAs($this->userA);

        $this->createMember($this->tenantA, '+33612345678');

        $response = $this->postJson('/api/v1/loyalty/pos/member-lookup', [
            'phone' => '+33 6 12 34 56 78',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.member.phone', '+33612345678');
    }

    public function test_member_lookup_returns_404_for_unknown_phone(): void
    {
        Sanctum::actingAs($this->userA);

        $response = $this->postJson('/api/v1/loyalty/pos/member-lookup', [
            'phone' => '+33600000000',
        ]);

        $response->assertStatus(404);
    }

    public function test_tenant_isolation_member_lookup(): void
    {
        // Create member in tenant B
        $this->createMember($this->tenantB, '+33699887766');

        // User A should NOT see tenant B's member
        Sanctum::actingAs($this->userA);
        $response = $this->postJson('/api/v1/loyalty/pos/member-lookup', [
            'phone' => '+33699887766',
        ]);

        $response->assertStatus(404);
    }

    public function test_rewards_lists_available_rewards_for_enrollment(): void
    {
        Sanctum::actingAs($this->userA);

        $program = $this->createProgram($this->tenantA);
        $member = $this->createMember($this->tenantA, '+33612345678');
        $enrollment = $this->createEnrollment($member, $program, '500.00');
        $this->createReward($program, 'Free Coffee', 100);
        $this->createReward($program, 'Free Meal', 1000);

        $response = $this->getJson("/api/v1/loyalty/pos/rewards/{$enrollment->id}");

        $response->assertOk();
        // Only Free Coffee should be affordable (500 >= 100, 500 < 1000)
        $response->assertJsonCount(1, 'data.rewards');
        $response->assertJsonPath('data.current_balance', '500.000');
    }

    public function test_rewards_endpoint_scoped_to_tenant(): void
    {
        // Create enrollment in tenant A
        $program = $this->createProgram($this->tenantA);
        $member = $this->createMember($this->tenantA, '+33612345678');
        $enrollment = $this->createEnrollment($member, $program, '500.00');

        // User B should NOT be able to access tenant A's enrollment rewards
        Sanctum::actingAs($this->userB);
        $response = $this->getJson("/api/v1/loyalty/pos/rewards/{$enrollment->id}");

        $response->assertStatus(404);
    }

    public function test_unauthenticated_user_cannot_access_pos_loyalty(): void
    {
        $response = $this->postJson('/api/v1/loyalty/pos/member-lookup', [
            'phone' => '+33600000000',
        ]);

        $response->assertStatus(401);
    }

    public function test_user_without_pos_permission_cannot_access(): void
    {
        // Create a user without POS permission
        $noPermUser = User::factory()->create(['tenant_id' => $this->tenantA->id]);
        UserCompanyMembership::create([
            'user_id' => $noPermUser->id,
            'company_id' => $this->companyA->id,
            'role' => 'viewer',
        ]);
        Sanctum::actingAs($noPermUser);

        $response = $this->postJson('/api/v1/loyalty/pos/member-lookup', [
            'phone' => '+33600000000',
        ]);

        $response->assertStatus(403);
    }

    public function test_preview_earning_returns_points(): void
    {
        Sanctum::actingAs($this->userA);

        $program = $this->createProgram($this->tenantA);
        $member = $this->createMember($this->tenantA, '+33612345678');
        $enrollment = $this->createEnrollment($member, $program, '100.00');

        $response = $this->postJson('/api/v1/loyalty/pos/preview-earning', [
            'enrollment_id' => $enrollment->id,
            'amount' => '50.00',
            'items' => [],
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['data' => ['points_to_earn']]);

        // points_to_earn is a non-fiscal display preview; the API contract
        // exposes it as a numeric value (frontend types it as `number`),
        // even though the service computes it as a canonical bcmath string.
        $this->assertIsNumeric($response->json('data.points_to_earn'));
        $this->assertIsNotString($response->json('data.points_to_earn'));
    }

    /**
     * Session B lane Q-3, treasury gate r1 finding F-2.
     *
     * `POST /loyalty/pos/redeem` has been dead since birth (created_at NOT NULL
     * with no default → 500 on every call). Hardening the service resurrected
     * it, and a live endpoint here is WORSE than a dead one: the response
     * (TransactionData) carries no `reward_value` / `points_spent`, the sole
     * client fabricates that type, and the only mounted consumer discards the
     * value — so a cashier tap would debit real points and deliver no discount.
     * The endpoint stays explicitly refused until a wiring lane ships a
     * consumable response AND a consumer that applies it.
     */
    public function test_redeem_endpoint_refuses_until_the_client_contract_is_wired(): void
    {
        Sanctum::actingAs($this->userA);

        $program = $this->createProgram($this->tenantA);
        $member = $this->createMember($this->tenantA, '+33612345699');
        $enrollment = $this->createEnrollment($member, $program, '150.00');
        $reward = $this->createReward($program, 'Free coffee', 10);

        $response = $this->postJson('/api/v1/loyalty/pos/redeem', [
            'enrollment_id' => $enrollment->id,
            'reward_id' => $reward->id,
        ]);

        $response->assertStatus(501);
        $response->assertJsonPath('error.code', 'LOYALTY_REDEMPTION_NOT_WIRED');

        // Nothing may be debited and no ledger row written.
        $this->assertDatabaseCount('loyalty_transactions', 0);
        $enrollment->refresh();
        $this->assertSame(0, bccomp((string) $enrollment->current_balance, '150', 3));
        $this->assertSame(0, bccomp((string) $enrollment->lifetime_redeemed, '0', 3));
        $this->assertNull($enrollment->last_transaction_at);
    }

    /**
     * The refusal is NOT a blanket short-circuit: validation still runs first,
     * so a malformed request is still a 422 and the endpoint's shape is
     * unchanged for a wiring lane to pick up.
     */
    public function test_redeem_endpoint_still_validates_before_refusing(): void
    {
        Sanctum::actingAs($this->userA);

        $response = $this->postJson('/api/v1/loyalty/pos/redeem', [
            'enrollment_id' => 'not-a-uuid',
            'reward_id' => 'not-a-uuid',
        ]);

        $response->assertStatus(422);
    }

    private function createProgram(Tenant $tenant): LoyaltyProgram
    {
        return LoyaltyProgram::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Program',
            'program_type' => ProgramType::Points,
            'currency' => 'points',
            'status' => ProgramStatus::Active,
        ]);
    }

    private function createMember(Tenant $tenant, string $phone): LoyaltyMember
    {
        return LoyaltyMember::create([
            'tenant_id' => $tenant->id,
            'phone' => LoyaltyMember::normalizePhone($phone),
            'status' => MemberStatus::Active,
            'enrollment_date' => now(),
        ]);
    }

    private function createEnrollment(
        LoyaltyMember $member,
        LoyaltyProgram $program,
        string $balance = '0.00',
    ): Enrollment {
        return Enrollment::create([
            'program_id' => $program->id,
            'member_id' => $member->id,
            'current_balance' => $balance,
            'lifetime_earned' => $balance,
            'lifetime_redeemed' => '0.00',
            'status' => EnrollmentStatus::Active,
            'enrolled_at' => now(),
        ]);
    }

    private function createReward(LoyaltyProgram $program, string $name, int $pointsCost): Reward
    {
        return Reward::create([
            'program_id' => $program->id,
            'name' => $name,
            'points_cost' => (string) $pointsCost,
            'reward_type' => 'discount_amount',
            'reward_value' => '5.00',
            'is_active' => true,
        ]);
    }
}
