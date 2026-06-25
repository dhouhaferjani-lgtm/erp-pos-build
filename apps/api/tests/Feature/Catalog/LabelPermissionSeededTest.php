<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Task A0 — the `catalog.labels.print` permission must be seeded and granted
 * to the manager role (variant label printing foundation).
 *
 * Real DB (RefreshDatabase) + seeded permissions (RolesAndPermissionsSeeder),
 * mirroring ProductVariantApiTest's seeding pattern.
 */
class LabelPermissionSeededTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_catalog_labels_print_permission_is_seeded(): void
    {
        $this->assertTrue(
            Permission::where('name', 'catalog.labels.print')
                ->where('guard_name', 'sanctum')
                ->exists(),
            'Expected catalog.labels.print permission to be seeded.'
        );
    }

    public function test_manager_role_has_catalog_labels_print(): void
    {
        $manager = Role::findByName('manager', 'sanctum');

        $this->assertTrue(
            $manager->hasPermissionTo('catalog.labels.print'),
            'Expected manager role to have catalog.labels.print permission.'
        );
    }
}
