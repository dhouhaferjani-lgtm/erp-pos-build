<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement;

use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Verifies PostgreSQL CHECK constraints on the procurement_policies table.
 *
 * Every test method is gated on the pgsql driver. Locally (SQLite) all
 * tests are skipped — that is expected and correct. CI runs this via the
 * backend-test-pgsql job filter.
 */
final class ProcurementPolicyCheckConstraintTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function baseRow(): array
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);

        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'bill_control_mode' => 'received',
            'match_mode' => 'three_way',
            'match_enforcement' => 'warn',
            'variance_tolerance_percent' => '0.00',
            'variance_tolerance_max_amount' => '0.000',
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ];
    }

    public function test_negative_tolerance_percent_is_rejected(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PG CHECK constraints require PostgreSQL.');
        }

        $this->expectException(QueryException::class);

        DB::table('procurement_policies')->insert(array_merge($this->baseRow(), [
            'variance_tolerance_percent' => '-1.00',
        ]));
    }

    public function test_invalid_bill_control_mode_is_rejected(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PG CHECK constraints require PostgreSQL.');
        }

        $this->expectException(QueryException::class);

        DB::table('procurement_policies')->insert(array_merge($this->baseRow(), [
            'bill_control_mode' => 'bogus',
        ]));
    }

    public function test_invalid_match_mode_is_rejected(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PG CHECK constraints require PostgreSQL.');
        }

        $this->expectException(QueryException::class);

        DB::table('procurement_policies')->insert(array_merge($this->baseRow(), [
            'match_mode' => 'invalid',
        ]));
    }

    public function test_invalid_match_enforcement_is_rejected(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PG CHECK constraints require PostgreSQL.');
        }

        $this->expectException(QueryException::class);

        DB::table('procurement_policies')->insert(array_merge($this->baseRow(), [
            'match_enforcement' => 'invalid',
        ]));
    }

    public function test_negative_tolerance_max_amount_is_rejected(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PG CHECK constraints require PostgreSQL.');
        }

        $this->expectException(QueryException::class);

        DB::table('procurement_policies')->insert(array_merge($this->baseRow(), [
            'variance_tolerance_max_amount' => '-0.001',
        ]));
    }

    public function test_valid_row_is_accepted(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PG CHECK constraints require PostgreSQL.');
        }

        DB::table('procurement_policies')->insert($this->baseRow());

        $this->assertDatabaseHas('procurement_policies', [
            'bill_control_mode' => 'received',
        ]);
    }
}
