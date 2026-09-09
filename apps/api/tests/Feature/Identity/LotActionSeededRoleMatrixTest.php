<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Database\Seeders\RolesAndPermissionsSeeder;
use Tests\TestCase;

final class LotActionSeededRoleMatrixTest extends TestCase
{
    public function test_viewer_and_operator_gain_batch_view_in_canonical_matrix(): void
    {
        foreach (['viewer', 'operator', 'cashier'] as $role) {
            self::assertContains('batches.view', RolesAndPermissionsSeeder::rolePermissionGrants()[$role]);
        }
    }

    public function test_manager_loses_global_recall_and_gains_request_in_canonical_matrix(): void
    {
        $grants = RolesAndPermissionsSeeder::rolePermissionGrants()['manager'];
        self::assertNotContains('batches.recall', $grants);
        self::assertContains('batches.recall.request', $grants);
    }

    public function test_general_manager_has_the_ruled_permission_delta(): void
    {
        $grants = RolesAndPermissionsSeeder::rolePermissionGrants();
        self::assertArrayHasKey('general_manager', $grants);
        self::assertSame([], array_diff($grants['manager'], $grants['general_manager']));
        self::assertContains('batches.recall', $grants['general_manager']);
        self::assertContains('treasury.manage_all_locations', $grants['general_manager']);
    }
}
