<?php

declare(strict_types=1);

namespace Tests\Feature\Workshop\Bundle;

use App\Modules\Workshop\Bundle\Domain\ServiceBundle;
use Database\Seeders\DemoTenantSeeder;
use Database\Seeders\PlansSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_tenant_seeder_creates_workshop_bundles_idempotently(): void
    {
        $this->seed(PlansSeeder::class);
        $this->seed(DemoTenantSeeder::class);

        $codes = [
            'VIDANGE-10K-ESSENCE',
            'VIDANGE-10K-DIESEL',
            'FREINAGE-AV',
            'REVISION-40K',
            'PNEUS-REMPLACEMENT-4',
            'DIAGNOSTIC-OBD',
        ];

        foreach ($codes as $code) {
            $this->assertDatabaseHas('workshop_service_bundles', ['code' => $code]);
        }

        $countBefore = ServiceBundle::count();

        // Re-running must not duplicate rows.
        $this->seed(DemoTenantSeeder::class);

        $this->assertSame($countBefore, ServiceBundle::count());
    }
}
