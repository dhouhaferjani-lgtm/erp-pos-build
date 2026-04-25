<?php

declare(strict_types=1);

namespace Tests\Unit\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PosShiftsScale4Test extends TestCase
{
    use RefreshDatabase;

    public function test_pos_shifts_monetary_columns_are_decimal_16_4(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('information_schema.columns is Postgres-specific');
        }

        $cols = DB::select("
            SELECT column_name, numeric_precision, numeric_scale
            FROM information_schema.columns
            WHERE table_name = 'pos_shifts'
              AND column_name IN ('opening_cash','expected_cash','actual_cash','variance')
        ");

        $this->assertCount(4, $cols, 'expected 4 monetary columns on pos_shifts');
        foreach ($cols as $c) {
            $this->assertSame(16, (int) $c->numeric_precision, "{$c->column_name} precision");
            $this->assertSame(4, (int) $c->numeric_scale, "{$c->column_name} scale");
        }
    }

    public function test_shift_stores_4_decimal_value_roundtrip(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $location = Location::factory()->create(['company_id' => $company->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $terminal = Terminal::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
        ]);

        $shift = Shift::create([
            'terminal_id' => $terminal->id,
            'cashier_id' => $user->id,
            'shift_number' => 1,
            'opening_cash' => '100.1234',
            'expected_cash' => '150.5678',
            'actual_cash' => '150.5678',
            'variance' => '0.0000',
            'status' => 'OPEN',
            'opened_at' => now(),
        ]);

        $fresh = $shift->fresh();
        $this->assertSame('100.1234', $fresh->opening_cash);
        $this->assertSame('150.5678', $fresh->expected_cash);
        $this->assertSame('0.0000', $fresh->variance);
    }

    public function test_shift_accepts_new_cash_count_metadata_columns(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $location = Location::factory()->create(['company_id' => $company->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $manager = User::factory()->create(['tenant_id' => $tenant->id]);
        $terminal = Terminal::factory()->create(['tenant_id' => $tenant->id, 'company_id' => $company->id, 'location_id' => $location->id]);

        $shift = Shift::create([
            'terminal_id' => $terminal->id,
            'cashier_id' => $user->id,
            'shift_number' => 1,
            'opening_cash' => '100.0000',
            'status' => 'OPEN',
            'opened_at' => now(),
            'blind_count_used' => true,
            'manager_override_by' => $manager->id,
            'variance_severity' => 'warning',
        ]);

        $fresh = $shift->fresh();
        $this->assertTrue($fresh->blind_count_used);
        $this->assertSame($manager->id, $fresh->manager_override_by);
        $this->assertSame('warning', $fresh->variance_severity);
    }
}
