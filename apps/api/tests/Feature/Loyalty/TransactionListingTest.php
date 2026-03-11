<?php

declare(strict_types=1);

namespace Tests\Feature\Loyalty;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Loyalty\Domain\Entities\Enrollment;
use App\Modules\Loyalty\Domain\Entities\LoyaltyMember;
use App\Modules\Loyalty\Domain\Entities\LoyaltyProgram;
use App\Modules\Loyalty\Domain\Entities\Transaction;
use App\Modules\Loyalty\Domain\Enums\EnrollmentStatus;
use App\Modules\Loyalty\Domain\Enums\MemberStatus;
use App\Modules\Loyalty\Domain\Enums\ProgramStatus;
use App\Modules\Loyalty\Domain\Enums\ProgramType;
use App\Modules\Loyalty\Domain\Enums\TransactionType;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * @group loyalty
 */
final class TransactionListingTest extends TestCase
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
        $this->grantPermission('loyalty.view');

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
            'lifetime_earned' => '100.000',
            'lifetime_redeemed' => '0.000',
            'status' => EnrollmentStatus::Active,
            'enrolled_at' => now(),
        ]);
    }

    public function test_paginated_transaction_listing(): void
    {
        // Create some transactions
        for ($i = 0; $i < 3; $i++) {
            Transaction::create([
                'enrollment_id' => $this->enrollment->id,
                'transaction_type' => TransactionType::Earn,
                'amount' => '10.000',
                'balance_before' => (string) ($i * 10),
                'balance_after' => (string) (($i + 1) * 10),
                'description' => "Earn transaction {$i}",
                'created_at' => now()->subMinutes($i),
            ]);
        }

        Sanctum::actingAs($this->user);

        $response = $this->getJson(
            "/api/v1/loyalty/members/{$this->member->id}/enrollments/{$this->enrollment->id}/transactions"
        );

        $response->assertOk();
        $response->assertJsonCount(3, 'data');
        $response->assertJsonStructure([
            'data' => [['id', 'enrollment_id', 'transaction_type', 'amount', 'balance_before', 'balance_after', 'description', 'created_at']],
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
        ]);
        $response->assertJsonPath('meta.total', 3);
    }

    public function test_member_enrollment_ownership_validation(): void
    {
        // Create a different member
        $otherMember = LoyaltyMember::create([
            'tenant_id' => $this->tenant->id,
            'phone' => '+33699999999',
            'status' => MemberStatus::Active,
            'enrollment_date' => now(),
        ]);

        Sanctum::actingAs($this->user);

        // Try to access enrollment of member A using member B's ID
        $response = $this->getJson(
            "/api/v1/loyalty/members/{$otherMember->id}/enrollments/{$this->enrollment->id}/transactions"
        );

        $response->assertStatus(404);
    }

    public function test_empty_transactions_returns_empty_paginated_response(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson(
            "/api/v1/loyalty/members/{$this->member->id}/enrollments/{$this->enrollment->id}/transactions"
        );

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
        $response->assertJsonPath('meta.total', 0);
    }

    public function test_transactions_are_ordered_latest_first(): void
    {
        $tx1 = Transaction::create([
            'enrollment_id' => $this->enrollment->id,
            'transaction_type' => TransactionType::Earn,
            'amount' => '10.000',
            'balance_before' => '0.000',
            'balance_after' => '10.000',
            'description' => 'First',
            'created_at' => now()->subHour(),
        ]);

        $tx2 = Transaction::create([
            'enrollment_id' => $this->enrollment->id,
            'transaction_type' => TransactionType::Earn,
            'amount' => '20.000',
            'balance_before' => '10.000',
            'balance_after' => '30.000',
            'description' => 'Second',
            'created_at' => now(),
        ]);

        Sanctum::actingAs($this->user);

        $response = $this->getJson(
            "/api/v1/loyalty/members/{$this->member->id}/enrollments/{$this->enrollment->id}/transactions"
        );

        $response->assertOk();
        $data = $response->json('data');
        $this->assertEquals($tx2->id, $data[0]['id']);
        $this->assertEquals($tx1->id, $data[1]['id']);
    }

    public function test_respects_per_page_parameter(): void
    {
        for ($i = 0; $i < 5; $i++) {
            Transaction::create([
                'enrollment_id' => $this->enrollment->id,
                'transaction_type' => TransactionType::Earn,
                'amount' => '10.000',
                'balance_before' => '0.000',
                'balance_after' => '10.000',
                'description' => "Transaction {$i}",
                'created_at' => now()->subMinutes($i),
            ]);
        }

        Sanctum::actingAs($this->user);

        $response = $this->getJson(
            "/api/v1/loyalty/members/{$this->member->id}/enrollments/{$this->enrollment->id}/transactions?per_page=2"
        );

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('meta.total', 5);
        $response->assertJsonPath('meta.last_page', 3);
    }

    private function grantPermission(string $permission): void
    {
        Permission::findOrCreate($permission, 'sanctum');
        $this->user->givePermissionTo($permission);
    }
}
