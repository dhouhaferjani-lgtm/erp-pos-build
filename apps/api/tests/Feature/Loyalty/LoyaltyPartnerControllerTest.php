<?php

declare(strict_types=1);

namespace Tests\Feature\Loyalty;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Loyalty\Domain\Entities\Enrollment;
use App\Modules\Loyalty\Domain\Entities\LoyaltyMember;
use App\Modules\Loyalty\Domain\Entities\LoyaltyProgram;
use App\Modules\Loyalty\Domain\Enums\EnrollmentStatus;
use App\Modules\Loyalty\Domain\Enums\MemberStatus;
use App\Modules\Loyalty\Domain\Enums\ProgramStatus;
use App\Modules\Loyalty\Domain\Enums\ProgramType;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class LoyaltyPartnerControllerTest extends TestCase
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

        // Tenant A (Loyalty enabled)
        $this->tenantA = Tenant::factory()->create(['enabled_extras' => ['Loyalty']]);
        $this->companyA = Company::factory()->create(['tenant_id' => $this->tenantA->id]);
        $this->userA = User::factory()->create(['tenant_id' => $this->tenantA->id]);
        UserCompanyMembership::create([
            'user_id' => $this->userA->id,
            'company_id' => $this->companyA->id,
            'role' => 'admin',
        ]);

        // Tenant B (Loyalty enabled)
        $this->tenantB = Tenant::factory()->create(['enabled_extras' => ['Loyalty']]);
        $this->companyB = Company::factory()->create(['tenant_id' => $this->tenantB->id]);
        $this->userB = User::factory()->create(['tenant_id' => $this->tenantB->id]);
        UserCompanyMembership::create([
            'user_id' => $this->userB->id,
            'company_id' => $this->companyB->id,
            'role' => 'admin',
        ]);

        // Grant only the narrow loyalty.enroll permission to each acting user.
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantA->id);
        Permission::firstOrCreate(['name' => 'loyalty.enroll', 'guard_name' => 'sanctum']);
        $this->userA->givePermissionTo('loyalty.enroll');

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenantB->id);
        Permission::firstOrCreate(['name' => 'loyalty.enroll', 'guard_name' => 'sanctum']);
        $this->userB->givePermissionTo('loyalty.enroll');
    }

    public function test_summary_returns_not_a_member_for_unenrolled_partner(): void
    {
        Sanctum::actingAs($this->userA);

        $partnerId = $this->insertPartner($this->tenantA, $this->companyA);

        $response = $this->getJson("/api/v1/loyalty/partners/{$partnerId}");

        $response->assertOk();
        $response->assertJsonPath('data.is_member', false);
        $response->assertJsonPath('data.enrollments', []);
    }

    public function test_summary_returns_member_with_enrollments_and_balance(): void
    {
        Sanctum::actingAs($this->userA);

        $partnerId = $this->insertPartner($this->tenantA, $this->companyA);
        $program = $this->createProgram($this->tenantA, 'Pharma Rewards');
        $member = $this->createMember($this->tenantA, '+21620111222', $partnerId);
        $this->createEnrollment($member, $program, '12.000');

        $response = $this->getJson("/api/v1/loyalty/partners/{$partnerId}");

        $response->assertOk();
        $response->assertJsonPath('data.is_member', true);
        $response->assertJsonPath('data.member_id', $member->id);
        $response->assertJsonCount(1, 'data.enrollments');
        $response->assertJsonPath('data.enrollments.0.balance', '12.000');
        $response->assertJsonPath('data.enrollments.0.program_name', 'Pharma Rewards');
        $response->assertJsonPath('data.enrollments.0.status', EnrollmentStatus::Active->value);

        // Rule 19: balances are strings end-to-end.
        self::assertIsString($response->json('data.enrollments.0.balance'));
    }

    public function test_enroll_creates_member_and_enrollment_with_phone(): void
    {
        Sanctum::actingAs($this->userA);

        $partnerId = $this->insertPartner($this->tenantA, $this->companyA, 'Amina');
        $this->createProgram($this->tenantA);

        $response = $this->postJson("/api/v1/loyalty/partners/{$partnerId}/enroll", [
            'phone' => '+216 20 123 456',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.is_member', true);
        $response->assertJsonCount(1, 'data.enrollments');

        $this->assertDatabaseHas('loyalty_members', [
            'tenant_id' => $this->tenantA->id,
            'customer_id' => $partnerId,
            'phone' => '+21620123456',
        ]);

        $memberId = LoyaltyMember::where('tenant_id', $this->tenantA->id)
            ->where('customer_id', $partnerId)->value('id');
        $this->assertDatabaseHas('loyalty_enrollments', [
            'member_id' => $memberId,
            'status' => EnrollmentStatus::Active->value,
        ]);
    }

    public function test_enroll_is_idempotent_on_second_call(): void
    {
        Sanctum::actingAs($this->userA);

        $partnerId = $this->insertPartner($this->tenantA, $this->companyA, 'Amina');
        $this->createProgram($this->tenantA);
        $payload = ['phone' => '+21620123456'];

        $this->postJson("/api/v1/loyalty/partners/{$partnerId}/enroll", $payload)->assertStatus(201);
        $this->postJson("/api/v1/loyalty/partners/{$partnerId}/enroll", $payload)->assertStatus(201);

        self::assertSame(
            1,
            LoyaltyMember::where('tenant_id', $this->tenantA->id)->where('customer_id', $partnerId)->count(),
        );
        $memberId = LoyaltyMember::where('tenant_id', $this->tenantA->id)
            ->where('customer_id', $partnerId)->value('id');
        self::assertSame(1, Enrollment::where('member_id', $memberId)->count());
    }

    public function test_enroll_404s_on_unknown_partner_and_invalid_uuid(): void
    {
        Sanctum::actingAs($this->userA);
        $this->createProgram($this->tenantA);

        $this->postJson('/api/v1/loyalty/partners/'.Str::uuid()->toString().'/enroll', [
            'phone' => '+21620123456',
        ])->assertStatus(404);

        $this->postJson('/api/v1/loyalty/partners/not-a-uuid/enroll', [
            'phone' => '+21620123456',
        ])->assertStatus(404);

        $this->getJson('/api/v1/loyalty/partners/not-a-uuid')->assertStatus(404);
    }

    public function test_enroll_422s_when_no_active_program(): void
    {
        Sanctum::actingAs($this->userA);

        $partnerId = $this->insertPartner($this->tenantA, $this->companyA);

        $this->postJson("/api/v1/loyalty/partners/{$partnerId}/enroll", [
            'phone' => '+21620123456',
        ])->assertStatus(422);
    }

    public function test_endpoints_require_loyalty_enroll_permission(): void
    {
        $noPermUser = User::factory()->create(['tenant_id' => $this->tenantA->id]);
        UserCompanyMembership::create([
            'user_id' => $noPermUser->id,
            'company_id' => $this->companyA->id,
            'role' => 'viewer',
        ]);
        Sanctum::actingAs($noPermUser);

        $partnerId = $this->insertPartner($this->tenantA, $this->companyA);

        $this->getJson("/api/v1/loyalty/partners/{$partnerId}")->assertStatus(403);
        $this->postJson("/api/v1/loyalty/partners/{$partnerId}/enroll", [
            'phone' => '+21620123456',
        ])->assertStatus(403);
    }

    public function test_module_gate_blocks_tenant_without_loyalty_extra(): void
    {
        $tenant = Tenant::factory()->create(['enabled_extras' => []]);
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'role' => 'admin',
        ]);
        // Grant loyalty.enroll so ONLY the module gate can produce the 403.
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        Permission::firstOrCreate(['name' => 'loyalty.enroll', 'guard_name' => 'sanctum']);
        $user->givePermissionTo('loyalty.enroll');
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/loyalty/partners/'.Str::uuid()->toString())->assertStatus(403);
        $this->postJson('/api/v1/loyalty/partners/'.Str::uuid()->toString().'/enroll', [
            'phone' => '+21620123456',
        ])->assertStatus(403);
    }

    public function test_cross_tenant_partner_is_not_reachable(): void
    {
        // Partner + member live under tenant B.
        $partnerId = $this->insertPartner($this->tenantB, $this->companyB);
        $program = $this->createProgram($this->tenantB);
        $member = $this->createMember($this->tenantB, '+21620999888', $partnerId);
        $this->createEnrollment($member, $program, '5.000');

        // Acting as tenant A user with loyalty.enroll — the partner id is not
        // reachable on tenant A's scope.
        Sanctum::actingAs($this->userA);

        $this->getJson("/api/v1/loyalty/partners/{$partnerId}")->assertStatus(404);
        $this->postJson("/api/v1/loyalty/partners/{$partnerId}/enroll", [
            'phone' => '+21620999888',
        ])->assertStatus(404);
    }

    private function insertPartner(
        Tenant $tenant,
        Company $company,
        string $name = 'Test Partner',
        ?string $phone = null,
    ): string {
        $id = Str::uuid()->toString();
        DB::table('partners')->insert([
            'id' => $id,
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'name' => $name,
            'type' => 'customer',
            'phone' => $phone,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function createProgram(Tenant $tenant, string $name = 'Test Program'): LoyaltyProgram
    {
        return LoyaltyProgram::create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'program_type' => ProgramType::Points,
            'currency' => 'points',
            'status' => ProgramStatus::Active,
        ]);
    }

    private function createMember(Tenant $tenant, string $phone, string $partnerId): LoyaltyMember
    {
        return LoyaltyMember::create([
            'tenant_id' => $tenant->id,
            'loyaltyable_type' => 'partner',
            'loyaltyable_id' => $partnerId,
            'customer_id' => $partnerId,
            'phone' => LoyaltyMember::normalizePhone($phone),
            'status' => MemberStatus::Active,
            'enrollment_date' => now(),
        ]);
    }

    private function createEnrollment(
        LoyaltyMember $member,
        LoyaltyProgram $program,
        string $balance = '0.000',
    ): Enrollment {
        return Enrollment::create([
            'program_id' => $program->id,
            'member_id' => $member->id,
            'current_balance' => $balance,
            'lifetime_earned' => $balance,
            'lifetime_redeemed' => '0.000',
            'status' => EnrollmentStatus::Active,
            'enrolled_at' => now(),
        ]);
    }
}
