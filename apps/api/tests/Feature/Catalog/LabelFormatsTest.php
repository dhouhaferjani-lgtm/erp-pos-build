<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Task A2 — GET /api/v1/labels/formats.
 *
 * Real DB (RefreshDatabase) + seeded permissions; the full middleware/auth
 * stack runs.
 */
class LabelFormatsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->for($this->tenant)->create(['currency' => 'TND']);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->user = User::factory()->for($this->tenant)->create();
        $this->user->givePermissionTo(['catalog.labels.print']);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        $this->actingAs($this->user);
    }

    public function test_returns_200_with_format_registry(): void
    {
        $resp = $this->getJson('/api/v1/labels/formats');

        $resp->assertOk();
        $resp->assertJsonStructure([
            'data' => [
                ['key', 'label', 'label_width_mm', 'label_height_mm', 'rows', 'cols'],
            ],
        ]);

        $keys = array_column($resp->json('data'), 'key');
        $this->assertContains('avery_l7160', $keys);
        $this->assertContains('label_4x6', $keys);
        $this->assertContains('grid_custom', $keys);
    }

    public function test_no_permission_is_403(): void
    {
        $other = User::factory()->for($this->tenant)->create();
        UserCompanyMembership::create([
            'user_id' => $other->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        $this->actingAs($other)
            ->getJson('/api/v1/labels/formats')
            ->assertStatus(403);
    }
}
