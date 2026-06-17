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
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Contract test for GET /api/v1/loyalty/members/{id}.
 *
 * Locks the API response shape (DTO key set + types) against the
 * `loyaltyable` morph migration so we can swap the deprecated
 * `customer()` BelongsTo eager-load without surprising consumers.
 *
 * Specifically asserts:
 * - The `data` envelope contains exactly the LoyaltyMemberData DTO keys.
 * - Polymorphic identifiers (loyaltyable_type / loyaltyable_id) are
 *   exposed alongside the legacy customer_id column.
 * - The deprecated `customer` relation is NOT eagerly serialized into
 *   the JSON payload (DTO does not project it).
 */
final class LoyaltyMemberShowContractTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['enabled_extras' => ['Loyalty']]);
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('loyalty.view', 'sanctum');
        $this->user->givePermissionTo('loyalty.view');
    }

    public function test_show_returns_loyalty_member_data_dto_shape(): void
    {
        Sanctum::actingAs($this->user);

        $program = LoyaltyProgram::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Program',
            'program_type' => ProgramType::Points,
            'currency' => 'points',
            'status' => ProgramStatus::Active,
        ]);

        $member = LoyaltyMember::create([
            'tenant_id' => $this->tenant->id,
            'phone' => '+33612345678',
            'email' => 'member@example.com',
            'first_name' => 'Alice',
            'last_name' => 'Doe',
            'status' => MemberStatus::Active,
            'enrollment_date' => now(),
        ]);

        Enrollment::create([
            'program_id' => $program->id,
            'member_id' => $member->id,
            'current_balance' => '100.00',
            'lifetime_earned' => '100.00',
            'lifetime_redeemed' => '0.00',
            'status' => EnrollmentStatus::Active,
            'enrolled_at' => now(),
        ]);

        $response = $this->getJson("/api/v1/loyalty/members/{$member->id}");

        $response->assertOk();

        // Lock exact DTO key set inside the `data` envelope.
        $response->assertJsonStructure([
            'data' => [
                'id',
                'tenant_id',
                'customer_id',
                'loyaltyable_type',
                'loyaltyable_id',
                'phone',
                'email',
                'first_name',
                'last_name',
                'date_of_birth',
                'status',
                'enrollment_date',
                'external_id',
                'created_at',
                'updated_at',
            ],
        ]);

        $payload = $response->json('data');

        // The DTO does not project the `customer` relation onto the
        // wire format, even when it is eager-loaded server-side.
        $this->assertArrayNotHasKey('customer', $payload);

        // Polymorphic identifier columns are exposed for FE consumers.
        $this->assertArrayHasKey('loyaltyable_type', $payload);
        $this->assertArrayHasKey('loyaltyable_id', $payload);
        $this->assertArrayHasKey('customer_id', $payload);

        // Scalar field types are stable.
        $this->assertSame($member->id, $payload['id']);
        $this->assertSame('+33612345678', $payload['phone']);
        $this->assertSame('member@example.com', $payload['email']);
        $this->assertSame('Alice', $payload['first_name']);
        $this->assertSame('Doe', $payload['last_name']);
        $this->assertSame(MemberStatus::Active->value, $payload['status']);
    }
}
