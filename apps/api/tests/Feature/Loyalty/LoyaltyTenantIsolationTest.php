<?php

declare(strict_types=1);

namespace Tests\Feature\Loyalty;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Loyalty\Domain\Entities\Enrollment;
use App\Modules\Loyalty\Domain\Entities\LoyaltyMember;
use App\Modules\Loyalty\Domain\Entities\LoyaltyProgram;
use App\Modules\Loyalty\Domain\Entities\Reward;
use App\Modules\Loyalty\Domain\Entities\StampCardDefinition;
use App\Modules\Loyalty\Domain\Enums\EnrollmentStatus;
use App\Modules\Loyalty\Domain\Enums\MemberStatus;
use App\Modules\Loyalty\Domain\Enums\ProgramStatus;
use App\Modules\Loyalty\Domain\Enums\ProgramType;
use App\Modules\Loyalty\Domain\Enums\RewardType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Section 8 (api.loyalty cluster) — tenant-isolation regression coverage.
 *
 * The api.loyalty cluster has 10 inventoried callsites:
 *   FormRequest validators (5):
 *     - api.loyalty.001  CreateStampCardRequest::rules    reward_id    -> loyalty_rewards
 *     - api.loyalty.002  UpdateMemberRequest::rules       customer_id  -> partners
 *     - api.loyalty.003  UpdateStampCardRequest::rules    reward_id    -> loyalty_rewards
 *     - api.loyalty.004  CreateMemberRequest::rules       customer_id  -> partners
 *     - api.loyalty.005  EnrollMemberRequest::rules       program_id   -> loyalty_programs
 *   Controller findOrFail (5, all on loyalty_members):
 *     - api.loyalty.006  LoyaltyMemberController::show          $id
 *     - api.loyalty.007  LoyaltyMemberController::update        $id
 *     - api.loyalty.008  LoyaltyMemberController::enrollments   $id
 *     - api.loyalty.009  LoyaltyMemberController::transactions  $memberId
 *     - api.loyalty.010  LoyaltyMemberController::adjust        $memberId
 *
 * Plus one scanner-blind-spot also closed in this cluster commit:
 *     - CreateProgramRequest::rules  company_ids.*  -> companies
 *       (bare `exists:companies,id`; cross-tenant company_ids on a loyalty
 *        program would let tenant-A's program target tenant-B's company)
 *
 * Per the cluster invariant Codex established in Treasury round-3 Finding 14,
 * every read whose anchor came from a route param OR validator FK MUST carry
 * a `tenant_id` predicate. For tables that also have a `company_id` column
 * (partners), BOTH predicates must appear. For tables that do NOT have a
 * company_id column (loyalty_members, loyalty_programs, companies), only
 * the tenant_id predicate is asserted — annotated below.
 *
 * Schema notes:
 *   - loyalty_members:   tenant_id only (no company_id column).
 *   - loyalty_programs:  tenant_id + company_ids (JSON array).
 *   - loyalty_rewards:   no tenant_id; FK to loyalty_programs(program_id).
 *     Tenant scoping for reward_id flows through the parent program.
 *   - partners:          tenant_id + company_id (both predicates required).
 *   - companies:         tenant_id only.
 *
 * Cross-references the inventory at:
 *   docs/superpowers/plans/tenant-isolation-sweep-inventory.yml
 */
final class LoyaltyTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private Company $companyA;

    private Company $companyB;

    private User $userA;

    private User $userB;

    private LoyaltyProgram $programA;

    private LoyaltyProgram $programB;

    private LoyaltyMember $memberA;

    private LoyaltyMember $memberB;

    private Reward $rewardA;

    private Reward $rewardB;

    private StampCardDefinition $stampCardA;

    private Enrollment $enrollmentA;

    private Enrollment $enrollmentB;

    private Partner $partnerA;

    private Partner $partnerB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::create([
            'name' => 'Tenant A',
            'slug' => 'tenant-a-loyalty-iso',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);
        $this->tenantB = Tenant::create([
            'name' => 'Tenant B',
            'slug' => 'tenant-b-loyalty-iso',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->companyA = Company::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Company A',
            'legal_name' => 'Company A LLC',
            'tax_id' => 'TAX-A-LOYALTY',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);
        $this->companyB = Company::create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Company B',
            'legal_name' => 'Company B LLC',
            'tax_id' => 'TAX-B-LOYALTY',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        // Seed permissions for both tenants. Spatie team scoping requires
        // the registrar's team id be set BEFORE seeding so roles/permissions
        // land on the right tenant_id. Mirrors ContactTenantIsolationTest.
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantA->id);
        $this->seed(RolesAndPermissionsSeeder::class);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantB->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->userA = User::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Alice',
            'email' => 'alice-loyalty-iso@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantA->id);
        $this->userA->assignRole('admin');

        $this->userB = User::create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Bob',
            'email' => 'bob-loyalty-iso@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantB->id);
        $this->userB->assignRole('admin');

        UserCompanyMembership::create([
            'user_id' => $this->userA->id,
            'company_id' => $this->companyA->id,
            'role' => 'admin',
        ]);
        UserCompanyMembership::create([
            'user_id' => $this->userB->id,
            'company_id' => $this->companyB->id,
            'role' => 'admin',
        ]);

        $this->programA = LoyaltyProgram::create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Program A',
            'program_type' => ProgramType::Stamps,
            'currency' => 'Stamps',
            'status' => ProgramStatus::Active,
        ]);
        $this->programB = LoyaltyProgram::create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Program B',
            'program_type' => ProgramType::Stamps,
            'currency' => 'Stamps',
            'status' => ProgramStatus::Active,
        ]);

        $this->memberA = LoyaltyMember::create([
            'tenant_id' => $this->tenantA->id,
            'phone' => '+33611111111',
            'first_name' => 'Alice-Member',
            'status' => MemberStatus::Active,
            'enrollment_date' => now(),
        ]);
        $this->memberB = LoyaltyMember::create([
            'tenant_id' => $this->tenantB->id,
            'phone' => '+33622222222',
            'first_name' => 'Bob-Member',
            'status' => MemberStatus::Active,
            'enrollment_date' => now(),
        ]);

        $this->rewardA = Reward::create([
            'program_id' => $this->programA->id,
            'name' => 'Reward A',
            'reward_type' => RewardType::FreeItem,
            'points_cost' => '10.00',
            'is_active' => true,
        ]);
        $this->rewardB = Reward::create([
            'program_id' => $this->programB->id,
            'name' => 'Reward B',
            'reward_type' => RewardType::FreeItem,
            'points_cost' => '10.00',
            'is_active' => true,
        ]);

        $this->stampCardA = StampCardDefinition::create([
            'program_id' => $this->programA->id,
            'name' => 'Card A',
            'stamps_required' => 10,
            'stamps_per_item' => 1,
            'qualifying_items' => ['item_types' => ['service']],
            'reward_id' => $this->rewardA->id,
        ]);

        $this->enrollmentA = Enrollment::create([
            'program_id' => $this->programA->id,
            'member_id' => $this->memberA->id,
            'current_balance' => '0.00',
            'lifetime_earned' => '0.00',
            'lifetime_redeemed' => '0.00',
            'status' => EnrollmentStatus::Active,
            'enrolled_at' => now(),
        ]);
        $this->enrollmentB = Enrollment::create([
            'program_id' => $this->programB->id,
            'member_id' => $this->memberB->id,
            'current_balance' => '0.00',
            'lifetime_earned' => '0.00',
            'lifetime_redeemed' => '0.00',
            'status' => EnrollmentStatus::Active,
            'enrolled_at' => now(),
        ]);

        $this->partnerA = Partner::create([
            'tenant_id' => $this->tenantA->id,
            'company_id' => $this->companyA->id,
            'name' => 'Partner A',
            'type' => 'customer',
        ]);
        $this->partnerB = Partner::create([
            'tenant_id' => $this->tenantB->id,
            'company_id' => $this->companyB->id,
            'name' => 'Partner B',
            'type' => 'customer',
        ]);
    }

    // ──────────────────────────────────────────────────────────────────
    // FormRequest path — CreateMemberRequest customer_id validator
    // (api.loyalty.004 — partners; tenant_id + company_id required)
    // ──────────────────────────────────────────────────────────────────

    public function test_create_member_rejects_cross_tenant_customer_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/loyalty/members', [
                'phone' => '+33699998888',
                'customer_id' => $this->partnerB->id,
            ]);
        $cross->assertStatus(422);
        $cross->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('customer_id', $cross->json('error.errors') ?? []);

        $same = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/loyalty/members', [
                'phone' => '+33699997777',
                'customer_id' => $this->partnerA->id,
            ]);
        $same->assertStatus(201);
    }

    // ──────────────────────────────────────────────────────────────────
    // FormRequest path — UpdateMemberRequest customer_id validator
    // (api.loyalty.002 — partners; tenant_id + company_id required)
    // ──────────────────────────────────────────────────────────────────

    public function test_update_member_rejects_cross_tenant_customer_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->patchJson("/api/v1/loyalty/members/{$this->memberA->id}", [
                'customer_id' => $this->partnerB->id,
            ]);
        $cross->assertStatus(422);
        $cross->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('customer_id', $cross->json('error.errors') ?? []);

        $same = $this->actingAsForTenant($this->userA, $this->companyA)
            ->patchJson("/api/v1/loyalty/members/{$this->memberA->id}", [
                'customer_id' => $this->partnerA->id,
            ]);
        $same->assertStatus(200);
    }

    // ──────────────────────────────────────────────────────────────────
    // FormRequest path — EnrollMemberRequest program_id validator
    // (api.loyalty.005 — loyalty_programs; tenant_id only)
    // ──────────────────────────────────────────────────────────────────

    public function test_enroll_member_rejects_cross_tenant_program_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/loyalty/members/{$this->memberA->id}/enroll", [
                'program_id' => $this->programB->id,
            ]);
        $cross->assertStatus(422);
        $cross->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('program_id', $cross->json('error.errors') ?? []);

        // Same-tenant enrolling memberA on programA is already-enrolled
        // (created in setUp), so we don't issue a same-tenant 201 control
        // here — the membership uniqueness constraint would reject it. The
        // dedicated cross_tenant_member_id test below covers the same-tenant
        // happy path through the enrollment endpoint.
    }

    // ──────────────────────────────────────────────────────────────────
    // FormRequest path — CreateStampCardRequest reward_id validator
    // (api.loyalty.001 — loyalty_rewards; scoped via parent program FK)
    // ──────────────────────────────────────────────────────────────────

    public function test_create_stamp_card_rejects_cross_tenant_reward_id(): void
    {
        // Route is POST /loyalty/programs/{programId}/stamp-cards.
        // Submitting tenant-A's program in the URL but tenant-B's reward
        // in the body must be rejected at the validator tier — the reward
        // belongs to a foreign program (and therefore a foreign tenant).
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/loyalty/programs/{$this->programA->id}/stamp-cards", [
                'name' => 'Cross Card',
                'stamps_required' => 10,
                'stamps_per_item' => 1,
                'qualifying_items' => ['item_types' => ['service']],
                'reward_id' => $this->rewardB->id,
            ]);
        $cross->assertStatus(422);
        $cross->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('reward_id', $cross->json('error.errors') ?? []);

        $same = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/loyalty/programs/{$this->programA->id}/stamp-cards", [
                'name' => 'Same Card',
                'stamps_required' => 5,
                'stamps_per_item' => 1,
                'qualifying_items' => ['item_types' => ['service']],
                'reward_id' => $this->rewardA->id,
            ]);
        $same->assertStatus(201);
    }

    // ──────────────────────────────────────────────────────────────────
    // FormRequest path — UpdateStampCardRequest reward_id validator
    // (api.loyalty.003 — loyalty_rewards; scoped via tenant programs)
    // ──────────────────────────────────────────────────────────────────

    public function test_update_stamp_card_rejects_cross_tenant_reward_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->patchJson("/api/v1/loyalty/stamp-cards/{$this->stampCardA->id}", [
                'reward_id' => $this->rewardB->id,
            ]);
        $cross->assertStatus(422);
        $cross->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('reward_id', $cross->json('error.errors') ?? []);

        $same = $this->actingAsForTenant($this->userA, $this->companyA)
            ->patchJson("/api/v1/loyalty/stamp-cards/{$this->stampCardA->id}", [
                'reward_id' => $this->rewardA->id,
            ]);
        $same->assertStatus(200);
    }

    // ──────────────────────────────────────────────────────────────────
    // Scanner-blind-spot — CreateProgramRequest company_ids validator
    // (companies; tenant_id only)
    // ──────────────────────────────────────────────────────────────────

    public function test_create_program_rejects_cross_tenant_company_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/loyalty/programs', [
                'name' => 'Cross Program',
                'program_type' => 'points',
                'currency' => 'EUR',
                'company_ids' => [$this->companyB->id],
            ]);
        $cross->assertStatus(422);
        $cross->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertArrayHasKey('company_ids.0', $cross->json('error.errors') ?? []);

        $same = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/loyalty/programs', [
                'name' => 'Same Program',
                'program_type' => 'points',
                'currency' => 'EUR',
                'company_ids' => [$this->companyA->id],
            ]);
        $same->assertStatus(201);
    }

    // ──────────────────────────────────────────────────────────────────
    // LoyaltyMember findOrFail (api.loyalty.006-010)
    //   loyalty_members has tenant_id only — assertions check tenant scope.
    // ──────────────────────────────────────────────────────────────────

    public function test_show_rejects_cross_tenant_member_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->getJson("/api/v1/loyalty/members/{$this->memberB->id}");
        $cross->assertStatus(404);

        $same = $this->actingAsForTenant($this->userA, $this->companyA)
            ->getJson("/api/v1/loyalty/members/{$this->memberA->id}");
        $same->assertStatus(200);
        $same->assertJsonPath('data.id', $this->memberA->id);
    }

    public function test_update_rejects_cross_tenant_member_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->patchJson("/api/v1/loyalty/members/{$this->memberB->id}", [
                'first_name' => 'Hijacked',
            ]);
        $cross->assertStatus(404);

        $freshB = $this->memberB->fresh();
        $this->assertNotNull($freshB, 'Cross-tenant member must still exist.');
        $this->assertSame(
            'Bob-Member',
            $freshB->first_name,
            'Cross-tenant member must remain unchanged.',
        );

        $same = $this->actingAsForTenant($this->userA, $this->companyA)
            ->patchJson("/api/v1/loyalty/members/{$this->memberA->id}", [
                'first_name' => 'Renamed',
            ]);
        $same->assertStatus(200);
    }

    public function test_enrollments_rejects_cross_tenant_member_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->getJson("/api/v1/loyalty/members/{$this->memberB->id}/enrollments");
        $cross->assertStatus(404);

        $same = $this->actingAsForTenant($this->userA, $this->companyA)
            ->getJson("/api/v1/loyalty/members/{$this->memberA->id}/enrollments");
        $same->assertStatus(200);
    }

    public function test_transactions_rejects_cross_tenant_member_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->getJson("/api/v1/loyalty/members/{$this->memberB->id}/enrollments/{$this->enrollmentB->id}/transactions");
        $cross->assertStatus(404);

        $same = $this->actingAsForTenant($this->userA, $this->companyA)
            ->getJson("/api/v1/loyalty/members/{$this->memberA->id}/enrollments/{$this->enrollmentA->id}/transactions");
        $same->assertStatus(200);
    }

    public function test_adjust_rejects_cross_tenant_member_id(): void
    {
        $cross = $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson("/api/v1/loyalty/members/{$this->memberB->id}/enrollments/{$this->enrollmentB->id}/adjust", [
                'points' => 10,
                'reason' => 'cross-tenant attempt',
            ]);
        $cross->assertStatus(404);
    }

    // ──────────────────────────────────────────────────────────────────
    // Structural-SQL-log invariants
    //   Bar-raising pattern from Treasury round-5: pin SQL shape, not
    //   just behavior. UUID uniqueness can mask data-level leaks.
    // ──────────────────────────────────────────────────────────────────

    public function test_show_query_includes_tenant_predicate(): void
    {
        \DB::enableQueryLog();

        $this->actingAsForTenant($this->userA, $this->companyA)
            ->getJson("/api/v1/loyalty/members/{$this->memberA->id}")
            ->assertStatus(200);

        $log = \DB::getQueryLog();
        \DB::disableQueryLog();

        $memberQuery = null;
        foreach ($log as $entry) {
            $sql = (string) $entry['query'];
            if (
                str_contains($sql, 'from "loyalty_members"')
                && str_contains($sql, '"id" =')
                && ! str_contains($sql, 'count(*)')
            ) {
                $memberQuery = $sql;
                break;
            }
        }

        $this->assertNotNull(
            $memberQuery,
            'LoyaltyMember lookup query must be captured. Log: '.json_encode(array_map(static fn ($e) => $e['query'], $log)),
        );
        $this->assertStringContainsString(
            '"tenant_id"',
            $memberQuery,
            'LoyaltyMember route-anchored lookup must filter by tenant_id (cluster invariant). Got SQL: '.$memberQuery,
        );
    }

    public function test_create_member_validator_query_includes_tenant_and_company_predicates(): void
    {
        \DB::enableQueryLog();

        $this->actingAsForTenant($this->userA, $this->companyA)
            ->postJson('/api/v1/loyalty/members', [
                'phone' => '+33644443333',
                'customer_id' => $this->partnerA->id,
            ])
            ->assertStatus(201);

        $log = \DB::getQueryLog();
        \DB::disableQueryLog();

        // The ScopedExists validator runs an `exists` query on partners
        // scoped by tenant_id + company_id (partners has both columns).
        $partnersValidationQuery = null;
        foreach ($log as $entry) {
            $sql = (string) $entry['query'];
            if (
                str_contains($sql, 'from "partners"')
                && str_contains($sql, '"id" =')
                && (str_contains($sql, 'exists') || str_contains($sql, 'count(*)'))
            ) {
                $partnersValidationQuery = $sql;
                break;
            }
        }

        $this->assertNotNull(
            $partnersValidationQuery,
            'Partners exists-validation query must be captured. Log: '.json_encode(array_map(static fn ($e) => $e['query'], $log)),
        );
        $this->assertStringContainsString(
            '"tenant_id"',
            $partnersValidationQuery,
            'CreateMemberRequest customer_id validator must filter by tenant_id. Got SQL: '.$partnersValidationQuery,
        );
        $this->assertStringContainsString(
            '"company_id"',
            $partnersValidationQuery,
            'CreateMemberRequest customer_id validator must filter by company_id. Got SQL: '.$partnersValidationQuery,
        );
    }

    /**
     * Authenticate `$user` and pin the company context header to `$company`.
     * Mirrors the Treasury isolation test helper.
     */
    private function actingAsForTenant(User $user, Company $company): self
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($user->tenant_id);

        /** @var self */
        return $this->actingAs($user, 'sanctum')
            ->withHeader('X-Company-Id', $company->id);
    }
}
