<?php

declare(strict_types=1);

namespace Tests\Feature\Workshop\Technician;

use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Workshop\Technician\Application\DTOs\TechnicianProfileData;
use App\Modules\Workshop\Technician\Domain\TechnicianProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * End-to-end verification that `TechnicianProfileData::toArray()` masks pay / PII fields
 * based on the authenticated user's permissions.
 *
 * Routes don't exist yet (Task 12) — these tests act on the DTO with a live Sanctum
 * session so `auth()->user()` inside `toArray()` resolves the real user + perms, which
 * is exactly what the HTTP controller will do once routes land.
 */
final class PiiMaskingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);

        Permission::findOrCreate('workshop.technicians.view_pay', 'sanctum');
        Permission::findOrCreate('workshop.technicians.view_pii', 'sanctum');

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
    }

    private function makeProfile(): TechnicianProfile
    {
        return TechnicianProfile::factory()->create([
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'user_id' => User::factory()->create(['tenant_id' => $this->tenant->id])->id,
            'hourly_cost_rate' => '25.000',
            'hourly_billing_rate' => '60.000',
            'national_id' => 'ID-ABC-123',
            'personal_address' => '10 rue de la Paix, 75002 Paris',
            'personal_phone' => '+33 6 12 34 56 78',
        ]);
    }

    public function test_manager_with_both_permissions_sees_pay_and_pii(): void
    {
        $manager = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $manager->givePermissionTo(['workshop.technicians.view_pay', 'workshop.technicians.view_pii']);
        Sanctum::actingAs($manager);

        $payload = TechnicianProfileData::fromModel($this->makeProfile())->toArray();

        $this->assertArrayHasKey('hourly_cost_rate', $payload);
        $this->assertArrayHasKey('hourly_billing_rate', $payload);
        $this->assertArrayHasKey('national_id', $payload);
        $this->assertArrayHasKey('personal_address', $payload);
        $this->assertArrayHasKey('personal_phone', $payload);
        $this->assertSame('25.000', $payload['hourly_cost_rate']);
        $this->assertSame('ID-ABC-123', $payload['national_id']);
    }

    public function test_user_without_view_pay_loses_rate_keys(): void
    {
        $technician = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $technician->givePermissionTo('workshop.technicians.view_pii');
        Sanctum::actingAs($technician);

        $payload = TechnicianProfileData::fromModel($this->makeProfile())->toArray();

        $this->assertArrayNotHasKey('hourly_cost_rate', $payload);
        $this->assertArrayNotHasKey('hourly_billing_rate', $payload);
        $this->assertArrayHasKey('national_id', $payload);
    }

    public function test_user_without_view_pii_loses_pii_keys(): void
    {
        $technician = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $technician->givePermissionTo('workshop.technicians.view_pay');
        Sanctum::actingAs($technician);

        $payload = TechnicianProfileData::fromModel($this->makeProfile())->toArray();

        $this->assertArrayNotHasKey('national_id', $payload);
        $this->assertArrayNotHasKey('personal_address', $payload);
        $this->assertArrayNotHasKey('personal_phone', $payload);
        $this->assertArrayHasKey('hourly_cost_rate', $payload);
    }

    public function test_user_with_no_permissions_sees_neither(): void
    {
        $technician = User::factory()->create(['tenant_id' => $this->tenant->id]);
        Sanctum::actingAs($technician);

        $payload = TechnicianProfileData::fromModel($this->makeProfile())->toArray();

        $this->assertArrayNotHasKey('hourly_cost_rate', $payload);
        $this->assertArrayNotHasKey('hourly_billing_rate', $payload);
        $this->assertArrayNotHasKey('national_id', $payload);
        $this->assertArrayNotHasKey('personal_address', $payload);
        $this->assertArrayNotHasKey('personal_phone', $payload);
        // Non-gated field still present
        $this->assertArrayHasKey('skill_level', $payload);
        $this->assertArrayHasKey('user_display_name', $payload);
    }
}
