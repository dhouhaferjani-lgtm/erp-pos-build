<?php

declare(strict_types=1);

namespace Tests\Unit\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\ShiftStatus;
use App\Modules\POS\Domain\Shift;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ShiftApplyToleranceWriteoffTest extends TestCase
{
    use RefreshDatabase;

    public function test_atomic_increment_on_apply_tolerance_writeoff(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $location = Location::factory()->create(['company_id' => $company->id]);
        $cashier = User::factory()->create(['tenant_id' => $tenant->id]);

        $terminal = Terminal::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'location_id' => $location->id,
            'code' => 'POS01',
            'name' => 'Test Terminal',
            'genesis_seed' => str_repeat('0', 64),
            'current_sequence' => 0,
            'current_year' => 2026,
            'is_active' => true,
            'max_discount_percent' => 20.00,
            'allow_line_discounts' => true,
            'allow_transaction_discounts' => true,
        ]);

        $shift = Shift::create([
            'terminal_id' => $terminal->id,
            'cashier_id' => $cashier->id,
            'shift_number' => 1,
            'opening_cash' => '100.000',
            'status' => ShiftStatus::Open,
            'opened_at' => now(),
            'tolerance_writeoff_total' => '0.000',
            'tolerance_writeoff_count' => 0,
        ]);

        $shift->applyToleranceWriteoff('0.020');
        $shift->applyToleranceWriteoff('0.050');

        $shift->refresh();
        $this->assertSame('0.070', $shift->tolerance_writeoff_total);
        $this->assertSame(2, $shift->tolerance_writeoff_count);
    }
}
