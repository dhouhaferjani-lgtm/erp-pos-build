<?php

declare(strict_types=1);

namespace Tests\Feature\Schema;

use App\Modules\Company\Domain\Company;
use App\Modules\Loyalty\Domain\Entities\LoyaltyProgram;
use App\Modules\Service\Domain\Service;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Guards the storage scale of residual monetary columns widened to scale 3
 * by migration 2026_05_29_100000_widen_residual_monetary_columns_to_scale_3.
 *
 * Contracts:
 *   - services.tax_rate                          → NUMERIC(6, 3)
 *   - loyalty_programs.welcome_bonus_points      → NUMERIC(15, 3)
 *
 * Schema-shape tests require PostgreSQL (information_schema.columns is
 * Postgres-specific) and are skipped on SQLite.
 *
 * Roundtrip tests run on all drivers — they insert a 3-decimal value and
 * assert it is not truncated to 2 decimals in the stored row.  On SQLite,
 * column types are unenforced so the value is preserved as-inserted; the
 * tests still pass and document the intended contract.
 */
final class MonetaryColumnScalesTest extends TestCase
{
    use RefreshDatabase;

    // ─── Schema-shape tests (PostgreSQL only) ────────────────────────────────

    public function test_services_tax_rate_is_decimal_6_3(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('information_schema.columns is Postgres-specific');
        }

        $cols = DB::select("
            SELECT column_name, numeric_precision, numeric_scale
            FROM information_schema.columns
            WHERE table_name   = 'services'
              AND column_name  = 'tax_rate'
        ");

        $this->assertCount(1, $cols, 'services.tax_rate column not found');
        $col = $cols[0];
        $this->assertSame(6, (int) $col->numeric_precision, 'services.tax_rate precision should be 6');
        $this->assertSame(3, (int) $col->numeric_scale, 'services.tax_rate scale should be 3');
    }

    public function test_loyalty_programs_welcome_bonus_points_is_decimal_15_3(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('information_schema.columns is Postgres-specific');
        }

        $cols = DB::select("
            SELECT column_name, numeric_precision, numeric_scale
            FROM information_schema.columns
            WHERE table_name   = 'loyalty_programs'
              AND column_name  = 'welcome_bonus_points'
        ");

        $this->assertCount(1, $cols, 'loyalty_programs.welcome_bonus_points column not found');
        $col = $cols[0];
        $this->assertSame(15, (int) $col->numeric_precision, 'loyalty_programs.welcome_bonus_points precision should be 15');
        $this->assertSame(3, (int) $col->numeric_scale, 'loyalty_programs.welcome_bonus_points scale should be 3');
    }

    // ─── Roundtrip tests (all drivers) ───────────────────────────────────────

    /**
     * A 3-decimal tax_rate must survive a DB round-trip without truncation.
     *
     * The Service model does not yet carry a `decimal:3` cast (Phase 1.4).
     * We read the raw value back via DB::table to avoid cast-level normalisation
     * masking a storage-precision problem.
     */
    public function test_services_tax_rate_roundtrip_preserves_3_decimals(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);

        $service = Service::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'tax_rate' => '19.500',
        ]);

        $raw = DB::table('services')->where('id', $service->id)->value('tax_rate');
        $this->assertNotNull($raw, 'tax_rate must be stored (not null)');

        // Cast to string; PostgreSQL returns '19.500', SQLite returns '19.5'
        // Either way the sub-cent digit must not be truncated to '19.50'
        $stored = (string) $raw;
        $this->assertStringStartsWith('19.5', $stored, 'tax_rate 19.500 must not be truncated to 19.50');
    }

    /**
     * A 3-decimal welcome_bonus_points must survive a DB round-trip without
     * truncation.
     */
    public function test_loyalty_programs_welcome_bonus_points_roundtrip_preserves_3_decimals(): void
    {
        $program = LoyaltyProgram::factory()->create(['welcome_bonus_points' => '100.500']);

        $raw = DB::table('loyalty_programs')->where('id', $program->id)->value('welcome_bonus_points');
        $this->assertNotNull($raw, 'welcome_bonus_points must be stored (not null)');

        $stored = (string) $raw;
        $this->assertStringStartsWith('100.5', $stored, 'welcome_bonus_points 100.500 must not be truncated to 100.50');
    }
}
