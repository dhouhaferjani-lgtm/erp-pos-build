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
use App\Modules\Loyalty\Domain\Events\LoyaltyAdjusted;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * @group loyalty
 */
final class PointAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    private LoyaltyProgram $program;

    private LoyaltyMember $member;

    private Enrollment $enrollment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->grantPermission('loyalty.manage');

        $this->program = LoyaltyProgram::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Program',
            'program_type' => ProgramType::Points,
            'currency' => 'EUR',
            'status' => ProgramStatus::Active,
        ]);

        $this->member = LoyaltyMember::create([
            'tenant_id' => $this->tenant->id,
            'phone' => '+33612345678',
            'status' => MemberStatus::Active,
            'enrollment_date' => now(),
        ]);

        $this->enrollment = Enrollment::create([
            'program_id' => $this->program->id,
            'member_id' => $this->member->id,
            'current_balance' => '100.000',
            'lifetime_earned' => '200.000',
            'lifetime_redeemed' => '100.000',
            'status' => EnrollmentStatus::Active,
            'enrolled_at' => now(),
        ]);
    }

    public function test_successful_credit_adjustment(): void
    {
        Event::fake([LoyaltyAdjusted::class]);
        Sanctum::actingAs($this->user);

        $response = $this->postJson(
            "/api/v1/loyalty/members/{$this->member->id}/enrollments/{$this->enrollment->id}/adjust",
            ['points' => '50', 'reason' => 'Goodwill gesture']
        );

        $response->assertStatus(201);
        $response->assertJsonPath('data.transaction_type', 'adjust');
        $response->assertJsonPath('data.amount', '50.000');
        $response->assertJsonPath('data.balance_before', '100.000');
        $response->assertJsonPath('data.balance_after', '150.000');

        $this->enrollment->refresh();
        $this->assertEquals('150.000', $this->enrollment->current_balance);
        $this->assertEquals('250.000', $this->enrollment->lifetime_earned);

        Event::assertDispatched(LoyaltyAdjusted::class);
    }

    public function test_successful_debit_adjustment(): void
    {
        Event::fake([LoyaltyAdjusted::class]);
        Sanctum::actingAs($this->user);

        $response = $this->postJson(
            "/api/v1/loyalty/members/{$this->member->id}/enrollments/{$this->enrollment->id}/adjust",
            ['points' => '-30', 'reason' => 'Correction for duplicate earning']
        );

        $response->assertStatus(201);
        $response->assertJsonPath('data.amount', '-30.000');
        $response->assertJsonPath('data.balance_after', '70.000');

        $this->enrollment->refresh();
        $this->assertEquals('70.000', $this->enrollment->current_balance);
    }

    public function test_debit_cannot_exceed_current_balance(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson(
            "/api/v1/loyalty/members/{$this->member->id}/enrollments/{$this->enrollment->id}/adjust",
            ['points' => '-150', 'reason' => 'Over-debit attempt']
        );

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'Debit amount exceeds current balance']);
    }

    public function test_reason_is_required(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson(
            "/api/v1/loyalty/members/{$this->member->id}/enrollments/{$this->enrollment->id}/adjust",
            ['points' => '10']
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error.errors.reason.0', 'The reason field is required.');
    }

    public function test_points_cannot_be_zero(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson(
            "/api/v1/loyalty/members/{$this->member->id}/enrollments/{$this->enrollment->id}/adjust",
            ['points' => '0', 'reason' => 'Zero adjustment']
        );

        $response->assertStatus(422);
        $this->assertArrayHasKey('points', $response->json('error.errors'));
    }

    public function test_enrollment_must_be_active(): void
    {
        $this->enrollment->update(['status' => EnrollmentStatus::OptedOut]);
        Sanctum::actingAs($this->user);

        $response = $this->postJson(
            "/api/v1/loyalty/members/{$this->member->id}/enrollments/{$this->enrollment->id}/adjust",
            ['points' => '10', 'reason' => 'Test']
        );

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'Enrollment must be active to adjust points']);
    }

    public function test_loyalty_adjusted_event_is_dispatched(): void
    {
        Event::fake([LoyaltyAdjusted::class]);
        Sanctum::actingAs($this->user);

        $this->postJson(
            "/api/v1/loyalty/members/{$this->member->id}/enrollments/{$this->enrollment->id}/adjust",
            ['points' => '25', 'reason' => 'Bonus for feedback']
        );

        Event::assertDispatched(LoyaltyAdjusted::class, function (LoyaltyAdjusted $event) {
            return $event->enrollmentId === $this->enrollment->id
                && $event->points === '25'
                && $event->reason === 'Bonus for feedback'
                && $event->adjustmentType === 'credit';
        });
    }

    public function test_requires_loyalty_manage_permission(): void
    {
        // Create user without manage permission
        $viewer = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $viewer->id,
            'company_id' => $this->company->id,
            'role' => 'viewer',
        ]);
        Permission::findOrCreate('loyalty.view', 'sanctum');
        $viewer->givePermissionTo('loyalty.view');

        Sanctum::actingAs($viewer);

        $response = $this->postJson(
            "/api/v1/loyalty/members/{$this->member->id}/enrollments/{$this->enrollment->id}/adjust",
            ['points' => '10', 'reason' => 'Test']
        );

        $response->assertStatus(403);
    }

    private function grantPermission(string $permission): void
    {
        Permission::findOrCreate($permission, 'sanctum');
        $this->user->givePermissionTo($permission);
    }
}
